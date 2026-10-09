<?php
namespace Aurelle\Services;
use Aurelle\Database as DB;
use Aurelle\Auth;
final class Messaging {
    public static function access(int $actor,int $conversation): array {
        $u=DB::one('SELECT id FROM users WHERE id=? AND suspended=0',[$actor]);
        $c=DB::one('SELECT c.* FROM conversations c JOIN conversation_participants cp ON cp.conversation_id=c.id WHERE cp.user_id=? AND c.id=?',[$actor,$conversation]);
        if (!$u || !$c) Platform::fail('This conversation is private.',403);
        if ($c['collection_id']) Platform::collection($actor,$c['collection_id']);
        if ($c['listing_id']) {
            $l=DB::one('SELECT seller_id,agent_id FROM listings WHERE id=?',[$c['listing_id']]);
            foreach (DB::all('SELECT user_id,kind FROM conversation_participants WHERE conversation_id=?',[$conversation]) as $participant) {
                if ($participant['kind']==='agent' && ($l['agent_id']!=$participant['user_id'] || !Auth::has('agent',$participant['user_id']))) Platform::fail('The property assignment changed. Start a conversation with the current agent.',403);
            }
        }
        $participant=DB::one('SELECT kind FROM conversation_participants WHERE conversation_id=? AND user_id=?',[$conversation,$actor]);
        if ($participant['kind']==='support' && !Auth::has('support',$actor)) Platform::fail('Support permission was revoked.',403);
        return $c;
    }
    public static function start(int $actor,int $listing): int {
        return DB::transaction(function() use ($actor,$listing) {
            $p=Platform::listing($listing,$actor,false,true); if (!$p['agent_id'] || !Auth::has('agent',$p['agent_id'])) Platform::fail('No approved agent is assigned.');
            if ($actor==$p['agent_id']) Platform::fail('You are the assigned agent.');
            $existing=DB::one('SELECT c.id FROM conversations c JOIN conversation_participants cp ON cp.conversation_id=c.id JOIN conversation_participants ap ON ap.conversation_id=c.id AND ap.kind="agent" WHERE c.listing_id=? AND cp.user_id=? AND ap.user_id=? AND c.collection_id IS NULL',[$listing,$actor,$p['agent_id']]); if ($existing) return (int)$existing['id'];
            $id=DB::insert('INSERT INTO conversations (listing_id,title) VALUES (?,?)',[$listing,$p['title']]);
            DB::query('INSERT INTO conversation_participants (conversation_id,user_id,kind) VALUES (?,? ,"customer")',[$id,$actor]);
            DB::query('INSERT INTO conversation_participants (conversation_id,user_id,kind) VALUES (?,? ,"agent")',[$id,$p['agent_id']]);
            if ($actor!=$p['seller_id']) { $lead=DB::insert('INSERT INTO leads (customer_id,agent_id,listing_id) VALUES (?,?,?)',[$actor,$p['agent_id'],$listing]); DB::query('INSERT INTO lead_assignment_history (lead_id,agent_id,actor_id) VALUES (?,?,?)',[$lead,$p['agent_id'],$actor]); }
            DB::query('INSERT INTO listing_events (listing_id,user_id,event) VALUES (?,?,"inquiry")',[$listing,$actor]); return $id;
        });
    }
    public static function send(int $actor,int $conversation,string $body,string $key): array {
        return DB::transaction(function() use ($actor,$conversation,$body,$key) {
            DB::one('SELECT id FROM conversations WHERE id=? FOR UPDATE',[$conversation]); self::access($actor,$conversation);
            if (strlen(trim($body))<1 || strlen($body)>10000 || !preg_match('/^[a-zA-Z0-9_-]{16,80}$/',$key)) Platform::fail('Enter a message and a valid send key.');
            $old=DB::one('SELECT * FROM messages WHERE sender_id=? AND idempotency_key=?',[$actor,$key]);
            if ($old) { if ($old['conversation_id']!=$conversation || $old['body']!==trim($body)) Platform::fail('Send key already used.',409); return $old; }
            Auth::rate('message:'.$actor,40,60);
            if (DB::one('SELECT b.user_id FROM blocks b JOIN conversation_participants cp ON cp.user_id=b.blocked_user_id WHERE b.user_id=? AND cp.conversation_id=? UNION SELECT b.user_id FROM blocks b JOIN conversation_participants cp ON cp.user_id=b.user_id WHERE b.blocked_user_id=? AND cp.conversation_id=?',[$actor,$conversation,$actor,$conversation])) Platform::fail('Messaging is blocked for this conversation.',403);
            $id=DB::insert('INSERT INTO messages (conversation_id,sender_id,body,idempotency_key) VALUES (?,?,?,?)',[$conversation,$actor,trim($body),$key]);
            foreach (DB::all('SELECT user_id FROM conversation_participants WHERE conversation_id=? AND user_id<>?',[$conversation,$actor]) as $u) { DB::query('INSERT INTO message_receipts (message_id,user_id) VALUES (?,?)',[$id,$u['user_id']]); Platform::notify($u['user_id'],'New message',mb_substr(trim($body),0,100),'/dashboard?tab=messages&conversation='.$conversation); }
            return DB::one('SELECT * FROM messages WHERE id=?',[$id]);
        });
    }
    public static function fetch(int $actor,int $conversation,int $after=0,int $before=0,string $search=''): array {
        self::access($actor,$conversation); $params=[$conversation,$after]; $where='m.conversation_id=? AND m.id>?';
        if ($before) { $where.=' AND m.id<?'; $params[]=$before; }
        if ($search!=='') { $where.=' AND m.body LIKE ?'; $params[]='%'.substr($search,0,100).'%'; }
        $order=($before || $after===0)?'DESC':'ASC';
        $messages=DB::all('SELECT m.*,u.name sender_name,(SELECT COUNT(*) FROM message_receipts r WHERE r.message_id=m.id AND r.read_at IS NOT NULL) read_count,(SELECT COUNT(*) FROM message_receipts r WHERE r.message_id=m.id AND r.delivered_at IS NOT NULL) delivered_count FROM messages m JOIN users u ON u.id=m.sender_id WHERE '.$where.' ORDER BY m.id '.$order.' LIMIT 100',$params);
        return $order==='DESC'?array_reverse($messages):$messages;
    }
    public static function receipt(int $actor,int $conversation,int $last,bool $read): void {
        self::access($actor,$conversation);
        if (!DB::one('SELECT id FROM messages WHERE id=? AND conversation_id=?',[$last,$conversation])) return;
        DB::query('UPDATE conversation_participants SET last_delivered_id=GREATEST(last_delivered_id,?)'.($read?',last_read_id=GREATEST(last_read_id,?)':'').' WHERE conversation_id=? AND user_id=?',$read?[$last,$last,$conversation,$actor]:[$last,$conversation,$actor]);
        DB::query('UPDATE message_receipts r JOIN messages m ON m.id=r.message_id SET r.delivered_at=COALESCE(r.delivered_at,UTC_TIMESTAMP())'.($read?',r.read_at=COALESCE(r.read_at,UTC_TIMESTAMP())':'').' WHERE r.user_id=? AND m.conversation_id=? AND m.id<=?',[$actor,$conversation,$last]);
    }
    public static function token(int $actor): string { $t=bin2hex(random_bytes(32)); DB::query('INSERT INTO realtime_tokens VALUES (?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE))',[hash('sha256',$t),$actor]); return $t; }
    public static function resolve(string $token): ?int { $t=DB::one('SELECT t.user_id FROM realtime_tokens t JOIN users u ON u.id=t.user_id WHERE token_hash=? AND expires_at>UTC_TIMESTAMP() AND u.suspended=0',[hash('sha256',$token)]); return $t?(int)$t['user_id']:null; }
}
