<?php
require_once dirname(__DIR__).'/app/bootstrap.php';
use Aurelle\Database as DB;
if (PHP_SAPI!=='cli') exit;
$name=env('DB_DATABASE','aurelle_estates');
if (!preg_match('/^[a-zA-Z0-9_]+$/',$name)) throw new RuntimeException('Invalid database name.');
$pdo=new PDO('mysql:host='.env('DB_HOST','127.0.0.1').';port='.env('DB_PORT','3306'),env('DB_USERNAME','root'),env('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
DB::query('CREATE TABLE IF NOT EXISTS migrations (name VARCHAR(190) PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
foreach (glob(ROOT.'/database/migrations/*.sql') as $file) {
    if (DB::one('SELECT name FROM migrations WHERE name=?',[basename($file)])) continue;
    foreach (explode(';',file_get_contents($file)) as $sql) if (trim($sql)!=='') DB::query($sql);
    DB::query('INSERT INTO migrations (name) VALUES (?)',[basename($file)]); echo 'Applied '.basename($file).PHP_EOL;
}
