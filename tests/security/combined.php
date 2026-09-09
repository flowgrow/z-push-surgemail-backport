<?php
// Exercise scheduling without network calls or device state.
class Backend {}
interface ISearchProvider {}
class ZLog { public static function Write(...$args) {} }
define('LOGLEVEL_DEBUG', 4);
class BackendIMAP {
    public $timeout;
    public function ChangesSink($timeout) { $this->timeout=$timeout; return ['inbox']; }
}
class MockDav {
    public function HasChangesSink() { return true; }
    public function ChangesSink($timeout) { if ($timeout!==0) throw new Exception('DAV blocks IDLE'); return []; }
}
require '/usr/share/z-push/backend/combined/combined.php';
$combined=(new ReflectionClass(BackendCombined::class))->newInstanceWithoutConstructor();
$imap=new BackendIMAP();
$combined->backends=['i'=>$imap,'d'=>new MockDav()];
$combined->config=['delimiter'=>'/'];
$t=microtime(true);
$result=$combined->ChangesSink(30);
if ($imap->timeout<29 || $result!==['i/inbox'] || microtime(true)-$t>1) throw new Exception('Combined IDLE wakeup regression');
echo "PASS combined preserves IDLE wait and immediate wakeup\n";
