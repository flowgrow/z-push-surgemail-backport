<?php
// An uncached event has a resource filename unrelated to its iCalendar UID.
class BackendDiff {}
class ZLog { static function Write(...$args) {} }
define('LOGLEVEL_DEBUG',4);
class Request { static function GetAuthUser(){return 'test@example.com';} }
require '/usr/share/z-push/backend/caldav/caldav.php';
class FixtureBackend extends BackendCalDAV {
 static $calls=[];
 protected function SubmitCalendarResponse(array $data){self::$calls[]=$data;return ['handled'=>true];}
}
class FixtureDav {
 public $gets=[];
 function GetResource($href){$this->gets[]=$href;return ['href'=>'opaque-name.ics','etag'=>count($this->gets)===1?'before':'after','data'=>'unused'];}
 function GetEntryByUid(){throw new Exception('Filename was incorrectly used as UID');}
}
$b=(new ReflectionClass(FixtureBackend::class))->newInstanceWithoutConstructor();$dav=new FixtureDav();
foreach(['_caldav'=>$dav,'_caldav_path'=>'/calendars/test@example.com/'] as $k=>$v){$p=new ReflectionProperty(BackendCalDAV::class,$k);$p->setValue($b,$v);}
$stat=$b->StatMessage('Cpersonal','opaque-name.ics');
if($stat['mod']!=='before'||$stat['id']!=='opaque-name.ics')throw new Exception('Uncached resource cannot be located');
$stat=$b->ChangeMessage('Cpersonal','opaque-name.ics',(object)['responsetype'=>'3'],null);
if(FixtureBackend::$calls!==[['object'=>'opaque-name.ics','status'=>'ACCEPTED']]||$stat['mod']!=='after')throw new Exception('Partial response was not preserved and forwarded');
echo "PASS opaque resource lookup and partial iOS acceptance preserve the existing event\n";
