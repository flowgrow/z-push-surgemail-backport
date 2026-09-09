<?php
require '/usr/share/z-push/backend/imap/oof.php';
class SyncOOFMessage {}
function check($condition, $message) { if (!$condition) throw new Exception($message); }
function rejects($call, $message) { try { $call(); } catch (Throwable $e) { return; } throw new Exception($message); }
function request($state = 1, $text = 'Away') {
    return (object)['oofstate'=>$state, 'starttime'=>1893456000, 'endtime'=>1893542400, 'oofmessage'=>[
        (object)['appliesToInternal'=>'', 'enabled'=>1, 'bodytype'=>'Text', 'replymessage'=>$text],
        (object)['appliesToExternal'=>'', 'enabled'=>1, 'bodytype'=>'Text', 'replymessage'=>$text],
        (object)['appliesToExternalUnknown'=>'', 'enabled'=>1, 'bodytype'=>'Text', 'replymessage'=>$text],
    ]];
}
class FakeSieve {
    public $extensions = ['vacation','date','relational'];
    public $files = ['roundcube'=>'require ["fileinto"];\nif header :is "Subject" "Bills" { fileinto "Bills"; stop; }'];
    public $active = 'roundcube';
    public $failPut = false;
    public function scripts() { return array_map(fn($key)=>$key === $this->active, array_combine(array_keys($this->files),array_keys($this->files))); }
    public function get($name) { return $this->files[$name]; }
    public function put($name, $value) { if ($this->failPut) throw new RuntimeException('Rejected'); $this->files[$name]=$value; }
    public function activate($name) { $this->active=$name; }
    public function delete($name) { unset($this->files[$name]); }
}
$client = new FakeSieve(); $original=$client->files['roundcube']; $service=new ZPushSieveOOF($client, 'person@example.com');
$r=$service->settings((object)['bodytype'=>'Text']); check($r->oofstate===0, 'Initial state');
$text="Away \"quoted\" \\ line\n.\n}; redirect \"bad@example.net\"; # Grüße";
$r=$service->settings(request(2,$text)); check($r->Status===1, 'Set success');
[$base,$state]=$service->split($client->files['roundcube']); check($base===$original, 'Existing filters preserved byte-for-byte');
check(str_contains($client->files['roundcube'], ':value "ge" "iso8601" "2030-01-01T00:00:00+00:00"'), 'UTC schedule');
check(str_contains($client->files['roundcube'], '\\"bad@example.net\\"'), 'Quote injection escaped');
$r=$service->settings((object)['bodytype'=>'Text']); check($r->oofstate===2 && $r->oofmessage[0]->replymessage===$text, 'GET round trip');
$before=$client->files;
$bad=request();$bad->oofmessage[2]->enabled=0;rejects(fn()=>$service->settings($bad), 'Contacts-only must reject');check($client->files===$before,'Unsupported settings must not mutate');
$bad=request(2);$bad->endtime=$bad->starttime;rejects(fn()=>$service->settings($bad),'Bad schedule');
$bad=request();$bad->oofmessage[0]->replymessage="bad\0";rejects(fn()=>$service->settings($bad),'NUL injection');
$client->failPut=true;rejects(fn()=>$service->settings(request()),'Rejected upload');check($client->files===$before,'Rejected upload preserves state');$client->failPut=false;
$service->settings(request(0));[$base,$state]=$service->split($client->files['roundcube']);check($base===$original && $state['state']===0,'Disable preserves filters');
check(!str_contains($service->rules($state),'vacation'), 'Disabled rules cannot reply');
rejects(fn()=>$service->compose('vacation "existing";',ZPushSieveOOF::normalize(request())), 'Foreign autoresponder conflict');
$client->files['roundcube'].='# manual edit';rejects(fn()=>$service->settings(request()),'External edit conflict');
$client=new FakeSieve();$client->files=['inactive'=>'keep;'];$client->active=null;$service=new ZPushSieveOOF($client,'person@example.com');
$service->settings(request());check($client->files['inactive']==='keep;' && str_starts_with($client->active,'z-push-autoreply-'),'Inactive scripts preserved');
rejects(fn()=>ZPushManageSieve::quote("name\r\nDELETE"),'Protocol injection');
check(ZPushSieveOOF::plain('<p>Hello</p><script>bad()</script><p>World &amp; all</p>', 'HTML') === "Hello\nWorld & all\n",'HTML conversion');
echo "PASS OOF schedule, audiences, escaping, preservation, conflicts, disable and read-back\n";

// Exercise ManageSieve framing with fragmented literals, quoting and failure responses.
function wire($response, $fail = false) {
    $pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP);
    fwrite($pair[0],$response); stream_socket_shutdown($pair[0],STREAM_SHUT_WR);
    $client=(new ReflectionClass(ZPushManageSieve::class))->newInstanceWithoutConstructor();
    $property=new ReflectionProperty(ZPushManageSieve::class,'socket');$property->setValue($client,$pair[1]);
    $method=new ReflectionMethod(ZPushManageSieve::class,'response');
    try { $result=$method->invoke($client); } finally { fclose($pair[0]); }
    return $result;
}
check(wire("{5}\r\na\r\nbc\r\nOK\r\n")===[["a\r\nbc"]], 'Literal framing');
check(wire("\"my script\" ACTIVE\r\nOK\r\n")===[['my script','ACTIVE']], 'Active script parsing');
rejects(fn()=>wire("{99999999}\r\n"),'Oversized literal');
rejects(fn()=>wire("{10}\r\nshort"),'Truncated literal');
rejects(fn()=>wire("NO \"secret text\"\r\n"),'Server rejection');
echo "PASS ManageSieve literal framing, script names, response bounds and errors\n";
// Pre-TLS capabilities and subsequent command responses must remain separate.
check(wire("\"SIEVE\" \"vacation date relational\"\r\nOK\r\n")===[['SIEVE','vacation date relational']], 'Capability response framing');
echo "PASS capability response framing\n";
