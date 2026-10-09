<?php
require_once dirname(__DIR__).'/app/bootstrap.php';
use Aurelle\Database as DB;
if (PHP_SAPI!=='cli') exit;
foreach (['listings'=>['id','cover_url'],'listing_media'=>['id','path'],'neighborhoods'=>['id','image'],'agent_profiles'=>['user_id','photo']] as $table=>[$pk,$column]) {
    foreach (DB::all('SELECT '.$pk.','.$column.' FROM '.$table.' WHERE '.$column.' LIKE "https://images.unsplash.com/photo-%"') as $row) {
        $name=basename(parse_url($row[$column],PHP_URL_PATH));
        if (preg_match('/^photo-[a-zA-Z0-9-]+$/',$name) && is_file(ROOT.'/public/assets/photos/'.$name.'.jpg')) DB::query('UPDATE '.$table.' SET '.$column.'=? WHERE '.$pk.'=?',['/assets/photos/'.$name.'.jpg',$row[$pk]]);
    }
}
echo "Available illustrative demo photographs now use local assets.\n";
