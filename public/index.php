<?php
require dirname(__DIR__).'/app/bootstrap.php';
use Aurelle\Controllers\WebController;
try { (new WebController())->run(); }
catch (Throwable $error) {
    $code=(int)$error->getCode(); if (!in_array($code,[401,403,404,409,419,422,429])) $code=500;
    if ($code===500) { $dir=ROOT.'/storage/logs'; if (!is_dir($dir)) mkdir($dir,0700,true); error_log(date('c').' '.$error."\n",3,$dir.'/application.log'); }
    http_response_code($code); $message=$code===500?'We couldn’t complete this request. Check the local setup and application log.':$error->getMessage();
    if (str_starts_with($_SERVER['REQUEST_URI'],'/api/')) { header('Content-Type: application/json'); echo json_encode(['error'=>$message]); }
    else { $page='error'; $title='Let’s try again'; $user=null; $data=['message'=>$message,'code'=>$code]; require ROOT.'/templates/layout.php'; }
}
