<?php
require '/usr/share/z-push/include/calendarbridge.php';
$reply=(object)['ctype_primary'=>'text','ctype_secondary'=>'calendar','body'=>"BEGIN:VCALENDAR\r\nMETHOD:REPLY\r\nEND:VCALENDAR"];
$m=(object)['ctype_primary'=>'multipart','ctype_secondary'=>'mixed','parts'=>[(object)['ctype_primary'=>'multipart','ctype_secondary'=>'alternative','parts'=>[$reply]]]];
if(!ZPushCalendarBridge::hasReply($m)) throw new Exception('Nested calendar RSVP not detected');
$reply->body="BEGIN:VCALENDAR\r\nMETHOD:REQUEST\r\nEND:VCALENDAR";
if(ZPushCalendarBridge::hasReply($m)) throw new Exception('Ordinary invitations must not be treated as replies');
if(ZPushCalendarBridge::response(3)!=='DECLINED') throw new Exception('Wrong response mapping');
echo "PASS calendar MIME recognition and response mapping\n";
