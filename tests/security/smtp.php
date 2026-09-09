<?php
class ZLog { public static function Write(...$args) {} }
define('LOGLEVEL_ERROR',1);
define('IMAP_SMTP_REQUIRE_TLS',true);
require '/usr/share/z-push/backend/imap/Net/SMTP.php';
$smtp=(new ReflectionClass(Net_SMTP::class))->newInstanceWithoutConstructor();
$smtp->host='smtp.purelymail.com';
if ($smtp->auth('fixture','never-send') !== false) throw new Exception('SMTP accepted authentication without STARTTLS');
echo "PASS SMTP refuses credentials when mandatory STARTTLS is absent\n";
