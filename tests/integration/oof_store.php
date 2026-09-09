<?php
// Test-only helper. Credentials arrive on stdin and are never printed or persisted.
require '/usr/share/z-push/backend/imap/oof.php';
$v=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$c=new ZPushManageSieve('mailserver.purelymail.com',4190,$v['user'],$v['password'],true);
if ($v['action']==='snapshot') {
    $scripts=$c->scripts();$files=[];
    foreach ($scripts as $name=>$active) $files[$name]=$c->get($name);
    echo json_encode(['scripts'=>$scripts,'files'=>$files]);
} elseif ($v['action']==='restore') {
    $before=$v['before'];$now=$c->scripts();$active=array_search(true,$now,true);
    if ($active!==false) {
        $script=$c->get($active);$svc=new ZPushSieveOOF($c,$v['user']);[, $state]=$svc->split($script);
        if (!$state || !str_contains(json_encode($state),$v['marker'])) throw new RuntimeException('Refusing to overwrite an unrelated filter change');
    }
    foreach ($before['files'] as $name=>$body) if (!array_key_exists($name,$now) || $c->get($name)!==$body) $c->put($name,$body);
    $original=array_search(true,$before['scripts'],true);$c->activate($original===false?'':$original);
    foreach ($now as $name=>$_) {
        if (!array_key_exists($name,$before['scripts']) && str_starts_with($name,'z-push-autoreply-')) {
            $script=$c->get($name);$svc=new ZPushSieveOOF($c,$v['user']);[, $state]=$svc->split($script);
            if ($state && str_contains(json_encode($state),$v['marker'])) $c->delete($name);
        }
    }
    echo json_encode(['restored'=>true]);
}
