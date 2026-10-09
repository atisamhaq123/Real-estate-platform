<?php
namespace Aurelle\Services;
use Aurelle\Database as DB;

final class DemoProperties {
    public static function populate(): array {
        if (\env('APP_ENV')==='production') throw new \RuntimeException('Demonstration property seeding is disabled in production.');
        return DB::transaction(function() {
            $seller=DB::one('SELECT id FROM users WHERE email="seller@aurelle.demo" AND suspended=0 FOR UPDATE');
            $admin=DB::one('SELECT u.id FROM users u JOIN role_memberships r ON r.user_id=u.id WHERE u.email="admin@aurelle.demo" AND r.role="admin" AND u.suspended=0');
            $agents=DB::all('SELECT u.id FROM users u JOIN agent_profiles a ON a.user_id=u.id JOIN role_memberships r ON r.user_id=u.id AND r.role="agent" WHERE u.email IN ("elena@aurelle.demo","james@aurelle.demo","sofia@aurelle.demo") AND a.approved_at IS NOT NULL AND u.suspended=0 ORDER BY u.id');
            if (!$seller || !$admin || !$agents) throw new \RuntimeException('Initialize the demonstration accounts with bin/seed.php before adding the collection.');

            // Price bases are fictional display amounts, not market valuations.
            $types=[
                'House'=>[3,2100,1150000,4200,'Detached living with flexible rooms, an inviting kitchen, and space to gather.'],
                'Apartment'=>[1,950,625000,2600,'An easy city rhythm, light-filled living spaces, and a practical open-plan layout.'],
                'Condo'=>[2,1400,850000,3300,'A considered residence with generous living areas and a private balcony.'],
                'Villa'=>[4,3400,2300000,7800,'Indoor-outdoor living, a sheltered terrace, and a garden designed for quiet afternoons.'],
                'Townhouse'=>[3,1900,980000,3800,'A home arranged across distinct living levels with a welcoming courtyard.'],
                'Penthouse'=>[3,2600,2100000,8200,'Upper-floor living with expansive rooms, a private terrace, and a dedicated study.'],
                'Estate'=>[5,5200,3900000,12500,'A spacious retreat with landscaped grounds, guest accommodation, and room to entertain.'],
                'Studio'=>[0,520,365000,1650,'A thoughtfully planned compact home with an integrated living and sleeping area.'],
                'Duplex'=>[4,2800,1450000,5200,'Two distinct living spaces with flexible arrangements for extended households.'],
                'Loft'=>[1,1250,780000,3100,'Open proportions, a flexible workspace, and a characterful living area.'],
            ];
            $places=[
                ['Bel Air','Los Angeles',34.100,-118.459,'photo-1600596542815-ffad4c1539a9'],
                ['Beverly Hills','Los Angeles',34.073,-118.400,'photo-1600047509807-ba8f99d2cdde'],
                ['Pacific Palisades','Los Angeles',34.046,-118.526,'photo-1518837695005-2083093ee35b'],
                ['Ocean Park','Santa Monica',34.006,-118.483,'photo-1518837695005-2083093ee35b'],
                ['Silver Lake','Los Angeles',34.086,-118.270,'photo-1600607687920-4e2a09cf159d'],
                ['Old Pasadena','Pasadena',34.146,-118.150,'photo-1600566753190-17f0baa2a6c3'],
                ['Point Dume','Malibu',34.022,-118.808,'photo-1518837695005-2083093ee35b'],
                ['Studio City','Los Angeles',34.143,-118.395,'photo-1600047509807-ba8f99d2cdde'],
                ['Venice','Los Angeles',33.990,-118.463,'photo-1600607687939-ce8a6c25118c'],
                ['West Hollywood','West Hollywood',34.091,-118.362,'photo-1600210492486-724fe5c67fb0'],
                ['Culver City','Culver City',34.021,-118.397,'photo-1600566753190-17f0baa2a6c3'],
                ['La Jolla','San Diego',32.833,-117.272,'photo-1518837695005-2083093ee35b'],
            ];
            $names=['Juniper','Willow','Cedar','Magnolia','Olive','Laurel','Sage','Cypress','Aspen','Maple','Rosewood','Palm'];
            $photos=['photo-1600596542815-ffad4c1539a9','photo-1600210492486-724fe5c67fb0','photo-1600607687920-4e2a09cf159d','photo-1600047509807-ba8f99d2cdde','photo-1600566753190-17f0baa2a6c3','photo-1600607687939-ce8a6c25118c'];
            foreach (array_keys($types) as $type) DB::query('INSERT IGNORE INTO property_categories (name) VALUES (?)',[$type]);
            $locations=[];
            foreach ($places as $place) {
                $row=DB::one('SELECT id FROM neighborhoods WHERE name=? AND city=?',[$place[0],$place[1]]);
                $locations[]= $row['id'] ?? DB::insert('INSERT INTO neighborhoods (name,city,description,image) VALUES (?,?,?,?)',[$place[0],$place[1],'Explore a fictional collection of homes around '.$place[0].'. This demonstration guide uses illustrative photography; local amenities and market conditions have not been verified.','/assets/photos/'.$place[4].'.jpg']);
            }
            $amenities=array_column(DB::all('SELECT id,name FROM amenities'),'id','name');
            $created=0; $skipped=0;
            foreach (array_keys($types) as $typeIndex=>$type) {
                [$baseBeds,$baseArea,$saleBase,$rentBase,$intro]=$types[$type];
                for ($variant=0;$variant<12;$variant++) {
                    $key='veyra-collection-v1-'.strtolower($type).'-'.str_pad((string)($variant+1),2,'0',STR_PAD_LEFT);
                    if (DB::one('SELECT listing_id FROM demo_listing_seeds WHERE seed_key=?',[$key])) { $skipped++; continue; }
                    $i=$typeIndex*12+$variant;
                    $placeIndex=($variant+$typeIndex)%count($places); $place=$places[$placeIndex];
                    $purpose=$variant%3===2?'rent':'sale';
                    $beds=$baseBeds+($type==='Studio'?0:$variant%3);
                    $baths=$type==='Studio'?1:max(1,$beds-1)+($variant%2?0.5:0);
                    $area=$baseArea+($variant*85)+($typeIndex*13);
                    $price=($purpose==='sale'?$saleBase+$variant*42500+$placeIndex*17500:$rentBase+$variant*175+$placeIndex*75)*100;
                    $agent=$agents[$i%count($agents)]['id'];
                    $title='The '.$names[$variant].' '.$type.' at '.$place[0];
                    $description=$intro.' This '.$beds.'-bedroom '.$type.' offers '.number_format($area).' square feet, '.(float)$baths.' bathrooms, and a considered balance of private and shared spaces. '.($purpose==='rent'?'A monthly rental example for exploring your next move.':'A sale listing example for building a thoughtful shortlist.').' Fictional demonstration property: photographs are illustrative stock images, addresses are withheld, and the price is not a verified market valuation.';
                    $published=(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->modify('-'.($i+1).' hours')->format('Y-m-d H:i:s');
                    $land=in_array($type,['House','Villa','Estate','Duplex','Townhouse'])?$area+1200+$variant*150:null;
                    $cover='/assets/photos/'.$photos[($typeIndex+$variant)%count($photos)].'.jpg';
                    $id=DB::insert('INSERT INTO listings (seller_id,agent_id,neighborhood_id,title,description,purpose,property_type,price_minor,rental_period,bedrooms,bathrooms,floor_area,land_area,area_unit,address,city,latitude,longitude,location_privacy,status,featured,cover_url,published_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,"sqft",?,?,?,?,"approximate","published",?,?,?)',[$seller['id'],$agent,$locations[$placeIndex],$title,$description,$purpose,$type,$price,$purpose==='rent'?'month':null,$beds,$baths,$area,$land,'Fictional demonstration address withheld',$place[1],$place[2]+($variant%3)*.002,$place[3]-($typeIndex%3)*.002,$variant===0?1:0,$cover,$published]);
                    DB::query('INSERT INTO demo_listing_seeds (seed_key,listing_id) VALUES (?,?)',[$key,$id]);
                    DB::query('INSERT INTO price_history (listing_id,price_minor,created_at) VALUES (?,?,?)',[$id,$price,$published]);
                    DB::query('INSERT INTO listing_status_history (listing_id,actor_id,from_status,to_status,reason,created_at) VALUES (?,? ,"draft","published","Demonstration dataset initialized; not a real offering or professional verification",?)',[$id,$admin['id'],$published]);
                    $features=['Home office'];
                    if (in_array($type,['House','Villa','Estate','Townhouse','Duplex'])) array_push($features,'Garden','Garage');
                    if (in_array($type,['Villa','Estate'])) $features[]='Pool';
                    if (in_array($type,['Apartment','Condo','Penthouse','Studio','Loft'])) $features[]='Elevator';
                    if ($variant%4===0) $features[]='Step-free entrance';
                    if ($variant%5===0) $features[]='Wide doorways';
                    foreach (array_unique($features) as $feature) if (isset($amenities[$feature])) DB::query('INSERT INTO listing_amenities VALUES (?,?)',[$id,$amenities[$feature]]);
                    for ($order=0;$order<3;$order++) DB::query('INSERT INTO listing_media (listing_id,path,kind,sort_order,is_cover) VALUES (?,? ,"photo",?,?)',[$id,'/assets/photos/'.$photos[($typeIndex+$variant+$order)%count($photos)].'.jpg',$order,$order===0?1:0]);
                    Platform::audit($admin['id'],'demo_collection_initialized','listing',$id,['seed_key'=>$key,'fictional'=>true]);
                    $created++;
                }
            }
            return ['created'=>$created,'already_present'=>$skipped,'public_total'=>(int)DB::one('SELECT COUNT(*) n FROM listings WHERE status IN ("published","under_offer")')['n']];
        });
    }
}
