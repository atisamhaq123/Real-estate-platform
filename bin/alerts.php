<?php
require_once dirname(__DIR__).'/app/bootstrap.php';
use Aurelle\Database as DB;
use Aurelle\Services\Platform as P;
if (PHP_SAPI!=='cli') exit;
DB::transaction(function() {
    foreach (DB::all('SELECT * FROM saved_searches FOR UPDATE') as $s) {
        $matches=P::search(json_decode($s['filters'],true));
        $since=$s['last_checked_at'] ?? $s['created_at'];
        foreach ($matches['items'] as $p) if ($p['published_at']>$since) P::notify($s['user_id'],'A new home matches '.$s['name'],$p['title'],'/property?id='.$p['id']);
        DB::query('UPDATE saved_searches SET last_checked_at=UTC_TIMESTAMP() WHERE id=?',[$s['id']]);
    }
});
echo "Saved-search alerts checked. In-app only; no emails or SMS sent.\n";
