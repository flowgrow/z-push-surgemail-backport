<?php
require '/usr/share/z-push/backend/imap/idle.php';
function check($value, $message) { if (!$value) throw new RuntimeException($message); }
check(ZPushIdleConnection::quote('a"b\\c') === '"a\\"b\\\\c"', 'IMAP string escaping');
try { ZPushIdleConnection::quote("a\r\nb"); throw new Exception('injection accepted'); }
catch (RuntimeException $e) {}

foreach (['arrival', 'bye', 'no-idle', 'idle-rejected', 'quiet'] as $scenario) {
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $pid = pcntl_fork();
    if ($pid === 0) {
        fclose($sockets[0]); $s = $sockets[1]; stream_set_timeout($s, 3);
        fwrite($s, "* OK ready\r\n");
        check(str_contains(fgets($s), ' LOGIN "test" "secret"'), 'login command');
        fwrite($s, "ZP1 OK logged in\r\n");
        check(str_contains(fgets($s), ' CAPABILITY'), 'capability command');
        fwrite($s, '* CAPABILITY IMAP4rev1' . ($scenario === 'no-idle' ? '' : ' IDLE') . "\r\nZP2 OK done\r\n");
        if ($scenario === 'no-idle') { fclose($s); exit(0); }
        check(str_contains(fgets($s), ' EXAMINE "INBOX"'), 'must select read-only');
        fwrite($s, "* 20 EXISTS\r\n* OK [UIDNEXT 42] next\r\nZP3 OK examined\r\n");
        check(str_contains(fgets($s), ' IDLE'), 'idle command');
        if ($scenario === 'idle-rejected') { fwrite($s, "ZP4 NO refused\r\n"); fclose($s); exit(0); }
        fwrite($s, "+ idling\r\n"); usleep(20000);
        if ($scenario === 'arrival') {
            fwrite($s, '* 21 EX'); usleep(20000); fwrite($s, "ISTS\r\n* 1 FETCH (FLAGS (\\Seen))\r\n");
        } elseif ($scenario === 'bye') { fwrite($s, "* BYE gone\r\n"); }
        usleep(250000); fclose($s); exit(0);
    }
    fclose($sockets[1]); $c = new ZPushIdleConnection($sockets[0], 'INBOX'); $threw = false; $changed = false;
    try {
        $c->authenticate('test', 'secret', microtime(true) + 2);
        $until = microtime(true) + .15;
        do { $changed = $c->consume() || $changed; usleep(5000); } while (microtime(true) < $until);
    } catch (RuntimeException $e) { $threw = true; }
    $c->close(); pcntl_waitpid($pid, $status);
    check(pcntl_wexitstatus($status) === 0, 'mock server failed');
    check($threw === in_array($scenario, ['bye', 'no-idle', 'idle-rejected']), $scenario . ': failure handling');
    if (!$threw) check($changed === ($scenario === 'arrival'), $scenario . ': change detection');
    echo "PASS $scenario\n";
}
