<?php
require '/usr/share/z-push/include/davsecurity.php';
$base = 'https://purelymail.com:443/webdav/test/';
foreach (['/webdav/test/', 'https://purelymail.com/webdav/test/'] as $url) ZPushDavSecurity::url($url, $base);
foreach (['http://purelymail.com/x', 'https://evil.example/x', '//evil.example/x', 'https://purelymail.com:444/x', 'https://u:p@purelymail.com/x', "https://purelymail.com/\r\nx", 'https://purelymail.com\\@evil.example/', 'file:///etc/passwd'] as $url) {
    try { ZPushDavSecurity::url($url, $base); throw new Exception('Unsafe DAV destination accepted'); }
    catch (RuntimeException $e) {}
}
echo "PASS DAV origin and protocol restrictions\n";
