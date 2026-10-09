<?php
require_once dirname(__DIR__).'/app/bootstrap.php';
if (!is_file(ROOT.'/vendor/autoload.php')) { fwrite(STDERR,"Run composer install first.\n"); exit(1); }
require ROOT.'/vendor/autoload.php';
use Workerman\Worker;
use Workerman\Timer;
use Aurelle\Database as DB;
use Aurelle\Auth;
use Aurelle\Services\Messaging as M;
$worker=new Worker('websocket://'.env('WS_LISTEN','127.0.0.1:8081'));
$worker->count=1; $worker->name='Veyra authorized messaging';
$worker->onWebSocketConnect=function($connection,$request) {
    if (rtrim($request->header('origin',''),'/')!==rtrim(env('APP_URL','http://127.0.0.1:8080'),'/')) $connection->close();
};
$worker->onConnect=function($connection) { $connection->aurelleUser=null; $connection->aurelleConversation=null; $connection->aurelleToken=''; $connection->aurelleConnected=time(); };
$worker->onMessage=function($connection,$raw) {
    try {
        if (strlen($raw)>16000) throw new RuntimeException('Frame too large.');
        $d=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if (($d['type'] ?? '')==='auth') {
            $actor=M::resolve($d['token'] ?? ''); if (!$actor) throw new RuntimeException('Authorization expired.');
            Auth::rate('socket_auth:'.$actor,30,60);
            M::access($actor,(int)($d['conversation'] ?? 0)); $connection->aurelleUser=$actor; $connection->aurelleConversation=(int)$d['conversation']; $connection->aurelleToken=$d['token']; $connection->send(json_encode(['type'=>'ready'])); return;
        }
        if (!$connection->aurelleUser || !M::resolve($connection->aurelleToken)) throw new RuntimeException('Authorization required.');
        M::access($connection->aurelleUser,$connection->aurelleConversation);
        if (($d['type'] ?? '')==='typing') {
            Auth::rate('typing:'.$connection->aurelleUser,30,60);
            foreach ($connection->worker->connections as $other) {
                if ($other!==$connection && $other->aurelleUser && $other->aurelleConversation===$connection->aurelleConversation && M::resolve($other->aurelleToken)) { M::access($other->aurelleUser,$other->aurelleConversation); $other->send(json_encode(['type'=>'typing'])); }
            }
        }
        if (($d['type'] ?? '')==='send') M::send($connection->aurelleUser,$connection->aurelleConversation,$d['body'] ?? '',$d['key'] ?? '');
    } catch (Throwable $e) { $connection->send(json_encode(['type'=>'error','error'=>$e->getMessage()])); if (!$connection->aurelleUser) $connection->close(); }
};
$worker->onWorkerStart=function($worker) {
    $cursor=(int)(DB::one('SELECT COALESCE(MAX(id),0) n FROM messages')['n']);
    Timer::add(.5,function() use ($worker,&$cursor) {
        try {
            $messages=DB::all('SELECT m.*,u.name sender_name FROM messages m JOIN users u ON u.id=m.sender_id WHERE m.id>? ORDER BY m.id LIMIT 200',[$cursor]);
            foreach ($messages as $m) {
                foreach ($worker->connections as $c) {
                    if (!$c->aurelleUser || $c->aurelleConversation!=(int)$m['conversation_id']) continue;
                    try { if (!M::resolve($c->aurelleToken)) { $c->close(); continue; } M::access($c->aurelleUser,$c->aurelleConversation); $c->send(json_encode(['type'=>'message','message'=>$m])); } catch (Throwable) { $c->close(); }
                }
                $cursor=max($cursor,(int)$m['id']);
            }
        } catch (Throwable $e) { error_log($e->getMessage()); }
    });
    Timer::add(5,function() use ($worker) {
        foreach ($worker->connections as $c) {
            if (!$c->aurelleUser) { if (time()-$c->aurelleConnected>10) $c->close(); continue; }
            try {
                if (!M::resolve($c->aurelleToken)) { $c->close(); continue; } M::access($c->aurelleUser,$c->aurelleConversation);
                $online=false;
                foreach ($worker->connections as $other) if ($other!==$c && $other->aurelleUser && $other->aurelleConversation===$c->aurelleConversation && DB::one('SELECT id FROM users WHERE id=? AND suspended=0 AND show_presence=1',[$other->aurelleUser])) $online=true;
                $c->send(json_encode(['type'=>'presence','online'=>$online]));
                $c->send(json_encode(['type'=>'receipts','messages'=>M::fetch($c->aurelleUser,$c->aurelleConversation)]));
            } catch (Throwable) { $c->close(); }
        }
    });
};
Worker::runAll();
