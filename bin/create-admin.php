<?php
require_once dirname(__DIR__).'/app/bootstrap.php';
use Aurelle\Database as DB;
use Aurelle\Auth;
if (PHP_SAPI!=='cli') exit;
$email=$argv[1] ?? ''; $name=$argv[2] ?? 'Administrator';
echo "New admin password (12+ characters; input visible in this terminal): "; $password=trim(fgets(STDIN));
$id=Auth::register(['name'=>$name,'email'=>$email,'password'=>$password]); DB::query('INSERT INTO role_memberships VALUES (?,"admin")',[$id]);
echo "Administrator created. No public registration can grant this role.\n";
