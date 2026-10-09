<?php
namespace Aurelle\Services;
use Aurelle\Database as DB;
use Aurelle\Auth;
final class Platform {
    public static function fail(string $message, int $code=422): never { throw new \RuntimeException($message,$code); }
    public static function text(array $data,string $key,int $min=1,int $max=10000): string {
        $value=trim((string)($data[$key] ?? ''));
        if (strlen($value)<$min || strlen($value)>$max) self::fail('Please check the '.str_replace('_',' ',$key).' field.');
        return $value;
    }
    public static function amount(mixed $value): int {
        if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/',(string)$value)) self::fail('Enter a positive amount with at most two decimal places.');
        $parts=explode('.',(string)$value); $n=((int)$parts[0])*100+(int)str_pad($parts[1] ?? '',2,'0');
        if ($n<=0) self::fail('Amount must be greater than zero.'); return $n;
    }
    public static function audit(int $actor,string $action,string $type,int|string $id,array $details=[]): void {
        DB::query('INSERT INTO audit_events (actor_id,action,entity_type,entity_id,details) VALUES (?,?,?,?,?)',[$actor,$action,$type,(string)$id,json_encode($details)]);
    }
    public static function history(int $actor,string $type,int $id,string $state,string $details=''): void {
        DB::query('INSERT INTO workflow_history (actor_id,entity_type,entity_id,state,details) VALUES (?,?,?,?,?)',[$actor,$type,$id,$state,$details]);
        self::audit($actor,$state,$type,$id);
    }
    public static function notify(int $user,string $title,string $body,string $link='/dashboard'): void {
        $u=DB::one('SELECT notification_preferences FROM users WHERE id=?',[$user]); $prefs=json_decode($u['notification_preferences'] ?? '{}',true);
        if (($prefs['in_app'] ?? true)===false) return;
        DB::query('INSERT INTO notifications (user_id,title,body,link) VALUES (?,?,?,?)',[$user,$title,$body,$link]);
    }
    public static function listing(int $id,int $actor,bool $manage=false,bool $lock=false): array {
        $p=DB::one('SELECT * FROM listings WHERE id=?'.($lock?' FOR UPDATE':''),[$id]);
        if (!$p) self::fail('Property not found.',404);
        $owner=$p['seller_id']==$actor || ($p['agent_id']==$actor && Auth::has('agent',$actor));
        if ($manage && !$owner && !Auth::has('admin',$actor)) self::fail('You cannot manage this property.',403);
        if (!$manage && !in_array($p['status'],['published','under_offer']) && !$owner && !Auth::has('admin',$actor)) self::fail('Property not available.',404);
        return $p;
    }
    public static function search(array $f): array {
        $where=["l.status IN ('published','under_offer')"]; $params=[];
        if (!empty($f['q'])) { $where[]='(l.city LIKE ? OR l.title LIKE ? OR n.name LIKE ?)'; $q='%'.substr(trim($f['q']),0,150).'%'; array_push($params,$q,$q,$q); }
        foreach (['purpose'=>'l.purpose','type'=>'l.property_type','availability'=>'l.status'] as $key=>$field) if (!empty($f[$key])) { $where[]="$field=?"; $params[]=$f[$key]; }
        foreach (['min'=>'l.price_minor >=','max'=>'l.price_minor <=','beds'=>'l.bedrooms >=','baths'=>'l.bathrooms >=','area'=>'l.floor_area >='] as $key=>$field) if (isset($f[$key]) && $f[$key]!=='' && is_numeric($f[$key])) { $where[]="$field ?"; $params[]=(float)$f[$key]*(in_array($key,['min','max'])?100:1); }
        if (!empty($f['amenity'])) { $where[]='EXISTS (SELECT 1 FROM listing_amenities la WHERE la.listing_id=l.id AND la.amenity_id=?)'; $params[]=(int)$f['amenity']; }
        $base=' FROM listings l LEFT JOIN neighborhoods n ON n.id=l.neighborhood_id WHERE '.implode(' AND ',$where);
        $total=DB::one('SELECT COUNT(*) total'.$base,$params)['total']; $page=max(1,(int)($f['page'] ?? 1));
        $sort=['newest'=>'l.published_at DESC','price_low'=>'l.price_minor ASC','price_high'=>'l.price_minor DESC','area'=>'l.floor_area DESC'][$f['sort'] ?? 'newest'] ?? 'l.published_at DESC';
        $items=DB::all('SELECT l.*,n.name neighborhood'.$base.' ORDER BY '.$sort.',l.id DESC LIMIT 9 OFFSET '.(($page-1)*9),$params);
        foreach ($items as &$item) {
            if ($item['location_privacy']!=='exact') $item['address']='Address withheld';
            if ($item['location_privacy']==='private') { $item['latitude']=null; $item['longitude']=null; }
            elseif ($item['location_privacy']==='approximate') { $item['latitude']=round($item['latitude'],2); $item['longitude']=round($item['longitude'],2); }
        }
        unset($item);
        return ['items'=>$items,'total'=>(int)$total,'page'=>$page,'pages'=>(int)ceil($total/9)];
    }
    public static function saveListing(int $actor,array $d): int {
        return DB::transaction(function() use ($actor,$d) {
            $id=(int)($d['id'] ?? 0); $old=$id?self::listing($id,$actor,true,true):null;
            $title=self::text($d,'title',4,190); $description=self::text($d,'description',20,20000); $price=self::amount($d['price'] ?? '');
            $purpose=$d['purpose'] ?? 'sale'; if (!in_array($purpose,['sale','rent'])) self::fail('Invalid purpose.');
            if (!in_array($d['property_type'] ?? '',array_column(DB::all('SELECT name FROM property_categories'),'name'))) self::fail('Choose a property type.');
            $beds=filter_var($d['bedrooms'] ?? '',FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>100]]);
            if ($beds===false || !is_numeric($d['bathrooms'] ?? '') || (float)$d['bathrooms']<0 || (float)$d['bathrooms']>100 || !is_numeric($d['floor_area'] ?? '') || (float)$d['floor_area']<=0) self::fail('Enter valid rooms and floor area.');
            $privacy=$d['location_privacy'] ?? 'approximate'; if (!in_array($privacy,['private','approximate','exact'])) self::fail('Invalid location privacy.');
            $lat=(float)($d['latitude'] ?? 0); $lng=(float)($d['longitude'] ?? 0); if ($lat < -90 || $lat>90 || $lng < -180 || $lng>180) self::fail('Invalid coordinates.');
            $unit=$d['area_unit'] ?? 'sqft'; if (!in_array($unit,['sqft','sqm'])) self::fail('Invalid unit.');
            $state=$old['status'] ?? 'draft'; if (in_array($state,['published','under_offer'])) $state='submitted';
            $values=[$title,$description,$purpose,$d['property_type'],$price,$beds,(float)$d['bathrooms'],(float)$d['floor_area'],$unit,self::text($d,'address',3,255),self::text($d,'city',2,100),$lat,$lng,$privacy,$state,$purpose==='rent'?'month':null];
            if ($old) { DB::query('UPDATE listings SET title=?,description=?,purpose=?,property_type=?,price_minor=?,bedrooms=?,bathrooms=?,floor_area=?,area_unit=?,address=?,city=?,latitude=?,longitude=?,location_privacy=?,status=?,rental_period=? WHERE id=?',[...$values,$id]); }
            else { $id=DB::insert('INSERT INTO listings (title,description,purpose,property_type,price_minor,bedrooms,bathrooms,floor_area,area_unit,address,city,latitude,longitude,location_privacy,status,rental_period,seller_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[...$values,$actor]); }
            $land=trim((string)($d['land_area'] ?? '')); if ($land!=='' && (!is_numeric($land) || (float)$land<0)) self::fail('Enter a valid land area.');
            $links=[];
            foreach (['video_url','tour_url'] as $key) {
                $url=trim((string)($d[$key] ?? ''));
                if ($url!=='') {
                    $host=parse_url($url,PHP_URL_HOST); $hosts=explode(',',\env('AUTHORIZED_TOUR_HOSTS','my.matterport.com,www.youtube.com,youtube.com,vimeo.com'));
                    if (!filter_var($url,FILTER_VALIDATE_URL) || parse_url($url,PHP_URL_SCHEME)!=='https' || !in_array($host,$hosts,true) || !isset($d['link_authorization']) || strlen($url)>500) self::fail('Use an authorized HTTPS video/tour host and confirm your permission to link.');
                }
                $links[]=$url ?: null;
            }
            $neighborhood=(int)($d['neighborhood_id'] ?? ($old['neighborhood_id'] ?? 0));
            if ($neighborhood && !DB::one('SELECT id FROM neighborhoods WHERE id=?',[$neighborhood])) self::fail('Choose a valid neighborhood.');
            DB::query('UPDATE listings SET land_area=?,video_url=?,tour_url=?,neighborhood_id=? WHERE id=?',[$land!==''?(float)$land:null,...$links,$neighborhood ?: null,$id]);
            DB::query('DELETE FROM listing_amenities WHERE listing_id=?',[$id]);
            foreach (array_unique(array_map('intval',(array)($d['amenities'] ?? []))) as $aid) if (DB::one('SELECT id FROM amenities WHERE id=?',[$aid])) DB::query('INSERT INTO listing_amenities VALUES (?,?)',[$id,$aid]);
            if (!$old || $old['price_minor']!=$price) { DB::query('INSERT INTO price_history (listing_id,price_minor) VALUES (?,?)',[$id,$price]); self::listingAlert($id,'Property price updated'); }
            if ($old && $old['status']!==$state) self::listingHistory($id,$actor,$old['status'],$state,'Significant edit requires review');
            self::audit($actor,'save','listing',$id); return $id;
        });
    }
    public static function listingHistory(int $id,int $actor,string $from,string $to,string $reason): void {
        DB::query('INSERT INTO listing_status_history (listing_id,actor_id,from_status,to_status,reason) VALUES (?,?,?,?,?)',[$id,$actor,$from,$to,$reason]); self::audit($actor,'status','listing',$id,['from'=>$from,'to'=>$to,'reason'=>$reason]);
    }
    public static function listingAlert(int $id,string $title): void {
        foreach (DB::all('SELECT user_id FROM favorites WHERE listing_id=?',[$id]) as $u) self::notify($u['user_id'],$title,'A property in your saved homes has changed.','/property?id='.$id);
    }
    public static function transitionListing(int $actor,int $id,string $to,string $reason=''): void {
        DB::transaction(function() use ($actor,$id,$to,$reason) {
            $p=self::listing($id,$actor,true,true); $admin=Auth::has('admin',$actor);
            $allowed=['draft'=>['submitted','archived'],'submitted'=>['published','rejected','withdrawn'],'rejected'=>['draft','submitted','archived'],'published'=>['under_offer','withdrawn','archived'],'under_offer'=>['published','sold','rented','withdrawn'],'sold'=>['archived'],'rented'=>['archived'],'withdrawn'=>['draft','archived'],'archived'=>[]];
            if (!in_array($to,$allowed[$p['status']]) || (in_array($to,['published','rejected','sold','rented','archived']) && !$admin)) self::fail('This listing transition is not permitted.',403);
            if ($to==='rejected' && strlen(trim($reason))<5) self::fail('Provide a revision reason.');
            if ($to==='published' && (!$p['agent_id'] || !Auth::has('agent',(int)$p['agent_id']))) self::fail('Assign an approved agent before publication.');
            if (($to==='sold' && $p['purpose']!=='sale') || ($to==='rented' && $p['purpose']!=='rent')) self::fail('Choose the matching completion status.');
            DB::query('UPDATE listings SET status=?,published_at=CASE WHEN ?="published" THEN UTC_TIMESTAMP() ELSE published_at END WHERE id=?',[$to,$to,$id]);
            self::listingHistory($id,$actor,$p['status'],$to,$reason); self::listingAlert($id,'Property status updated'); self::notify($p['seller_id'],'Listing '.$to,$reason ?: $p['title'],'/dashboard?tab=listings');
        });
    }
    public static function collection(int $actor,int $id,bool $owner=false): array {
        $c=DB::one('SELECT * FROM collections WHERE id=?',[$id]);
        if (!$c || ($c['owner_id']!=$actor && ($owner || !DB::one('SELECT user_id FROM collection_memberships WHERE collection_id=? AND user_id=?',[$id,$actor])))) self::fail('This collection is private.',403);
        return $c;
    }
    public static function book(int $actor,array $d): int {
        return DB::transaction(function() use ($actor,$d) {
            $p=self::listing((int)$d['listing_id'],$actor,false,true);
            if (!in_array($p['status'],['published','under_offer']) || !$p['agent_id']) self::fail('This property is not accepting viewings.');
            $zone=self::text($d,'timezone',1,60); if (!in_array($zone,\DateTimeZone::listIdentifiers())) self::fail('Choose a valid time zone.');
            try { $start=new \DateTimeImmutable(self::text($d,'starts_at'),new \DateTimeZone($zone)); } catch (\Exception) { self::fail('Invalid appointment time.'); }
            if ($start->getTimestamp()<=time()) self::fail('Choose a future appointment.');
            $s=$start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'); $end=$start->modify('+1 hour')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            DB::one('SELECT id FROM users WHERE id=? FOR UPDATE',[$p['agent_id']]);
            if (DB::one("SELECT id FROM viewings WHERE (agent_id=? OR customer_id=? OR listing_id=?) AND status IN ('requested','confirmed') AND starts_at<? AND ends_at>?",[$p['agent_id'],$actor,$p['id'],$end,$s])) self::fail('That time conflicts with a booking. Choose another time.',409);
            $slots=DB::one('SELECT COUNT(*) total FROM availability WHERE listing_id=?',[$p['id']]);
            if ($slots['total'] && !DB::one('SELECT id FROM availability WHERE listing_id=? AND starts_at<=? AND ends_at>=?',[$p['id'],$s,$end])) self::fail('Choose a time within the listed availability.');
            $id=DB::insert('INSERT INTO viewings (listing_id,customer_id,agent_id,starts_at,ends_at,timezone,note) VALUES (?,?,?,?,?,?,?)',[$p['id'],$actor,$p['agent_id'],$s,$end,$zone,substr($d['note'] ?? '',0,2000)]);
            self::history($actor,'viewing',$id,'requested'); self::notify($p['agent_id'],'Viewing request',$p['title'],'/dashboard?tab=viewings'); return $id;
        });
    }
    public static function viewingState(int $actor,int $id,string $to): void {
        DB::transaction(function() use ($actor,$id,$to) {
            $v=DB::one('SELECT * FROM viewings WHERE id=? FOR UPDATE',[$id]);
            if (!$v) self::fail('Viewing not found.',404);
            $manager=($v['agent_id']==$actor && Auth::has('agent',$actor)) || Auth::has('admin',$actor);
            if (!$manager && ($v['customer_id']!=$actor || $to!=='cancelled')) self::fail('Viewing access denied.',403);
            $graph=['requested'=>['confirmed','cancelled'],'confirmed'=>['completed','cancelled','no_show'],'completed'=>[],'cancelled'=>[],'no_show'=>[]];
            if (!in_array($to,$graph[$v['status']])) self::fail('Invalid viewing transition.');
            if (in_array($to,['completed','no_show']) && strtotime($v['ends_at'])>time()) self::fail('The appointment has not ended yet.');
            DB::query('UPDATE viewings SET status=? WHERE id=?',[$to,$id]); self::history($actor,'viewing',$id,$to); self::notify($v['customer_id'],'Viewing '.$to,'Your appointment status has changed.','/dashboard?tab=viewings');
        });
    }
    public static function proposal(int $actor,array $d): int {
        return DB::transaction(function() use ($actor,$d) {
            $p=self::listing((int)$d['listing_id'],$actor); if (!in_array($p['status'],['published','under_offer'])) self::fail('This property is not accepting proposals.');
            if ($p['seller_id']==$actor || $p['agent_id']==$actor) self::fail('You cannot submit a customer proposal for your own or assigned property.');
            $amount=self::amount($d['amount'] ?? ''); $terms=self::text($d,'terms',5,10000);
            $id=DB::insert('INSERT INTO proposals (listing_id,customer_id) VALUES (?,?)',[$p['id'],$actor]);
            DB::query('INSERT INTO proposal_revisions (proposal_id,actor_id,amount_minor,terms) VALUES (?,?,?,?)',[$id,$actor,$amount,$terms]); self::history($actor,'proposal',$id,'submitted'); self::notify($p['seller_id'],'New proposal',$p['title'],'/dashboard?tab=proposals'); return $id;
        });
    }
    public static function proposalState(int $actor,int $id,string $to,array $d=[]): void {
        DB::transaction(function() use ($actor,$id,$to,$d) {
            $p=DB::one('SELECT p.*,l.seller_id,l.agent_id FROM proposals p JOIN listings l ON l.id=p.listing_id WHERE p.id=? FOR UPDATE',[$id]); if (!$p) self::fail('Proposal not found.',404);
            $customer=$p['customer_id']==$actor; $manager=$p['seller_id']==$actor || ($p['agent_id']==$actor && Auth::has('agent',$actor)) || Auth::has('admin',$actor);
            if (!$customer && !$manager) self::fail('Proposal access denied.',403);
            $graph=['submitted'=>['acknowledged','countered','accepted','rejected','withdrawn'],'acknowledged'=>['countered','accepted','rejected','withdrawn'],'countered'=>['countered','accepted','rejected','withdrawn'],'accepted'=>[],'rejected'=>[],'withdrawn'=>[]];
            if (!in_array($to,$graph[$p['status']]) || ($to==='withdrawn' && !$customer) || ($to==='acknowledged' && !$manager) || ($customer && !$manager && $p['status']!=='countered' && $to!=='withdrawn')) self::fail('Invalid proposal transition.',403);
            $latest=DB::one('SELECT actor_id FROM proposal_revisions WHERE proposal_id=? ORDER BY id DESC LIMIT 1',[$id]);
            if ($to==='accepted' && (int)$latest['actor_id']===$actor) self::fail('The other participant must accept the current terms.');
            if ($to==='countered') DB::query('INSERT INTO proposal_revisions (proposal_id,actor_id,amount_minor,terms) VALUES (?,?,?,?)',[$id,$actor,self::amount($d['amount'] ?? ''),self::text($d,'terms',5,10000)]);
            DB::query('UPDATE proposals SET status=? WHERE id=?',[$to,$id]); self::history($actor,'proposal',$id,$to,'Agreement in principle only; no ownership transfer.');
            if ($to==='accepted') foreach (['Inspection','Financing confirmation','Document collection','Legal review','Closing confirmation'] as $title) DB::query('INSERT INTO transaction_checklists (proposal_id,title) VALUES (?,?)',[$id,$title]);
            self::notify($customer?$p['seller_id']:$p['customer_id'],'Proposal '.$to,'Review the preserved proposal terms.','/dashboard?tab=proposals');
        });
    }
    public static function quote(int $actor,int $request,int $amount): int {
        if (!Auth::has('admin',$actor)) self::fail('Administrator access required.',403);
        return DB::transaction(function() use ($actor,$request,$amount) {
            $r=DB::one('SELECT * FROM service_requests WHERE id=? FOR UPDATE',[$request]);
            if (!$r || $r['status']!=='requested' || $amount<=0) self::fail('This request cannot be quoted.');
            DB::query('UPDATE service_requests SET status="quoted",quote_minor=? WHERE id=?',[$amount,$request]);
            $id=DB::insert('INSERT INTO invoices (request_id,user_id,total_minor) VALUES (?,?,?)',[$request,$r['user_id'],$amount]); self::history($actor,'service',$request,'quoted'); return $id;
        });
    }
    public static function payment(int $actor,int $invoice,int $amount,string $reference): int {
        if (!Auth::has('admin',$actor)) self::fail('Administrator access required.',403);
        return DB::transaction(function() use ($actor,$invoice,$amount,$reference) {
            $i=DB::one('SELECT * FROM invoices WHERE id=? FOR UPDATE',[$invoice]); if (!$i) self::fail('Invoice not found.',404);
            $existing=DB::one('SELECT * FROM payments WHERE reference=?',[$reference]);
            if ($existing) { if ($existing['invoice_id']!=$invoice || $existing['amount_minor']!=$amount) self::fail('Payment reference already used.',409); return (int)$existing['id']; }
            $paid=(int)DB::one('SELECT COALESCE(SUM(amount_minor),0) total FROM payments WHERE invoice_id=?',[$invoice])['total'];
            if ($amount<=0 || $paid+$amount>$i['total_minor']) self::fail('Payment exceeds the invoice balance.');
            $id=DB::insert('INSERT INTO payments (invoice_id,amount_minor,reference,actor_id) VALUES (?,?,?,?)',[$invoice,$amount,$reference,$actor]);
            if ($paid+$amount==$i['total_minor']) DB::query('UPDATE invoices SET status="paid" WHERE id=?',[$invoice]); self::audit($actor,'manual_payment','invoice',$invoice); return $id;
        });
    }
    public static function refund(int $actor,int $payment,int $amount,string $reference,string $reason): int {
        if (!Auth::has('admin',$actor)) self::fail('Administrator access required.',403);
        return DB::transaction(function() use ($actor,$payment,$amount,$reference,$reason) {
            $p=DB::one('SELECT * FROM payments WHERE id=? FOR UPDATE',[$payment]); if (!$p) self::fail('Payment not found.',404);
            $old=DB::one('SELECT * FROM refunds WHERE reference=?',[$reference]); if ($old) { if ($old['payment_id']!=$payment || $old['amount_minor']!=$amount) self::fail('Refund reference already used.',409); return (int)$old['id']; }
            $refunded=(int)DB::one('SELECT COALESCE(SUM(amount_minor),0) total FROM refunds WHERE payment_id=?',[$payment])['total'];
            if ($amount<=0 || $refunded+$amount>$p['amount_minor']) self::fail('Refund exceeds the payment balance.');
            $id=DB::insert('INSERT INTO refunds (payment_id,amount_minor,reference,reason) VALUES (?,?,?,?)',[$payment,$amount,$reference,$reason]);
            DB::query('INSERT INTO credits (invoice_id,amount_minor,reason) VALUES (?,?,?)',[$p['invoice_id'],$amount,$reason]); DB::query('UPDATE invoices SET status="credited" WHERE id=?',[$p['invoice_id']]); self::audit($actor,'manual_refund','payment',$payment); return $id;
        });
    }
}
