<?php
/**
 * Lightweight IMAP client for high-volume UID/FLAGS fetches and optional IDLE.
 * This is intentionally minimal and only implements the commands used by Z-Push.
 */

$g_rawimap_error = null;

function rawimap_set_error($error) {
    global $g_rawimap_error;
    $g_rawimap_error = $error;
}

function rawimap_get_error() {
    global $g_rawimap_error;
    return $g_rawimap_error;
}

function rawimap_quote($value) {
    return '"' . str_replace(array("\\", '"'), array("\\\\", '\\"'), (string) $value) . '"';
}

function rawimap_next_tag($connection) {
    $tag = sprintf("A%04d", $connection->tagcounter);
    $connection->tagcounter++;
    return $tag;
}

function rawimap_read_line($connection, $timeout = null) {
    if (!is_object($connection) || !isset($connection->stream) || !is_resource($connection->stream)) {
        rawimap_set_error("Connection is closed");
        return false;
    }

    if ($timeout !== null) {
        stream_set_timeout($connection->stream, (int) $timeout);
    }

    $line = fgets($connection->stream);
    if ($line === false) {
        $meta = stream_get_meta_data($connection->stream);
        if (!empty($meta["timed_out"])) {
            rawimap_set_error("Timed out waiting for IMAP response");
        }
        else {
            rawimap_set_error("Failed to read IMAP response");
        }
        return false;
    }

    return rtrim($line, "\r\n");
}

function rawimap_read_command_result($connection, $tag, &$infolines = null, $timeout = 30) {
    $infolines = array();

    while (!feof($connection->stream)) {
        $line = rawimap_read_line($connection, $timeout);
        if ($line === false) {
            return false;
        }

        if (strpos($line, $tag . " ") === 0) {
            return $line;
        }

        $infolines[] = $line;
    }

    rawimap_set_error("Connection closed before IMAP command completed");
    return false;
}

function rawimap_was_ok($resultline) {
    if (!is_string($resultline) || $resultline === "") {
        return false;
    }
    $parts = explode(" ", $resultline);
    return (count($parts) >= 2 && strtoupper($parts[1]) === "OK");
}

function rawimap_send_command($connection, $command, &$infolines = null, $timeout = 30) {
    $tag = rawimap_next_tag($connection);
    $line = $tag . " " . $command . "\r\n";
    if (fwrite($connection->stream, $line) === false) {
        rawimap_set_error("Failed to send IMAP command");
        return false;
    }

    $resultline = rawimap_read_command_result($connection, $tag, $infolines, $timeout);
    if ($resultline === false) {
        return false;
    }

    if (!rawimap_was_ok($resultline)) {
        rawimap_set_error(sprintf("IMAP command failed: %s", $resultline));
        return false;
    }

    rawimap_set_error(null);
    return $resultline;
}

function rawimap_open_connection($server, $port, $timeout = 30) {
    $server = (string) $server;
    $port = (int) $port;

    $errno = 0;
    $errstr = "";
    $stream = @stream_socket_client(sprintf("tcp://%s:%d", $server, $port), $errno, $errstr, (int) $timeout);
    if ($stream === false) {
        rawimap_set_error(sprintf("Unable to connect to IMAP server: %s (%d)", $errstr, $errno));
        return false;
    }

    stream_set_timeout($stream, (int) $timeout);

    $connection = new stdClass();
    $connection->stream = $stream;
    $connection->tagcounter = 1;
    $connection->timeout = (int) $timeout;

    $greeting = rawimap_read_line($connection, $timeout);
    if ($greeting === false) {
        @fclose($stream);
        return false;
    }

    if (strpos($greeting, "* OK") !== 0 && strpos($greeting, "* PREAUTH") !== 0) {
        rawimap_set_error(sprintf("Invalid IMAP greeting: %s", $greeting));
        @fclose($stream);
        return false;
    }

    rawimap_set_error(null);
    return $connection;
}

function rawimap_close_connection($connection) {
    if (is_object($connection) && isset($connection->stream) && is_resource($connection->stream)) {
        @fclose($connection->stream);
    }
}

function rawimap_login($connection, $user, $password) {
    return (rawimap_send_command($connection, "LOGIN " . rawimap_quote($user) . " " . rawimap_quote($password)) !== false);
}

function rawimap_select($connection, $mailbox) {
    $infolines = array();
    if (rawimap_send_command($connection, "SELECT " . rawimap_quote($mailbox), $infolines) === false) {
        return false;
    }

    $result = array("totalcount" => 0, "recentcount" => 0);
    foreach ($infolines as $line) {
        if (preg_match('/^\* (\d+) EXISTS$/', $line, $matches)) {
            $result["totalcount"] = (int) $matches[1];
        }
        else if (preg_match('/^\* (\d+) RECENT$/', $line, $matches)) {
            $result["recentcount"] = (int) $matches[1];
        }
    }
    return $result;
}

function rawimap_parse_internaldate($internalDate) {
    $oldTimezone = date_default_timezone_get();
    date_default_timezone_set("UTC");
    $parsed = strtotime(preg_replace('/\(.*\)/', "", (string) $internalDate));
    date_default_timezone_set($oldTimezone);
    if ($parsed === false || $parsed == -1) {
        return 0;
    }
    return $parsed;
}

function rawimap_uid_fetch_overview($connection, $uidSet, $maxImapSizeBytes = 0) {
    $uidSet = trim((string) $uidSet);
    if ($uidSet === "") {
        return array();
    }

    $infolines = array();
    if (rawimap_send_command($connection, "UID FETCH " . $uidSet . " (UID INTERNALDATE RFC822.SIZE FLAGS)", $infolines, 60) === false) {
        return false;
    }

    $result = array();
    foreach ($infolines as $line) {
        if (strpos($line, " FETCH ") === false) {
            continue;
        }

        $overview = new stdClass();
        $overview->uid = 0;
        $overview->udate = 0;
        $overview->size = 0;
        $overview->seen = 0;
        $overview->recent = 0;
        $overview->deleted = 0;
        $overview->answered = 0;
        $overview->flagged = 0;

        if (preg_match('/\bUID\s+(\d+)/', $line, $matches)) {
            $overview->uid = (int) $matches[1];
        }
        if (preg_match('/\bRFC822\.SIZE\s+(\d+)/', $line, $matches)) {
            $overview->size = (int) $matches[1];
        }
        if (preg_match('/\bINTERNALDATE\s+"([^"]+)"/', $line, $matches)) {
            $overview->udate = rawimap_parse_internaldate($matches[1]);
        }
        if (preg_match('/\bFLAGS\s+\(([^)]*)\)/', $line, $matches)) {
            $flags = strtoupper($matches[1]);
            $overview->seen = (strpos($flags, "\\SEEN") !== false) ? 1 : 0;
            $overview->recent = (strpos($flags, "\\RECENT") !== false) ? 1 : 0;
            $overview->deleted = (strpos($flags, "\\DELETED") !== false) ? 1 : 0;
            $overview->answered = (strpos($flags, "\\ANSWERED") !== false) ? 1 : 0;
            $overview->flagged = (strpos($flags, "\\FLAGGED") !== false) ? 1 : 0;
        }

        if ($overview->uid <= 0) {
            continue;
        }

        if ((int) $maxImapSizeBytes > 0 && $overview->size > (int) $maxImapSizeBytes) {
            continue;
        }

        $result[] = $overview;
    }

    return $result;
}

function rawimap_idle_wait_for_exists($connection, $timeout) {
    $timeout = max(1, (int) $timeout);
    $tag = rawimap_next_tag($connection);
    if (fwrite($connection->stream, $tag . " IDLE\r\n") === false) {
        rawimap_set_error("Failed to send IDLE command");
        return false;
    }

    // Wait for continuation response ("+ idling").
    while (!feof($connection->stream)) {
        $line = rawimap_read_line($connection, min(5, $timeout));
        if ($line === false) {
            return false;
        }

        if (strpos($line, "+") === 0) {
            break;
        }
        if (strpos($line, $tag . " ") === 0) {
            rawimap_set_error(sprintf("IDLE rejected by IMAP server: %s", $line));
            return false;
        }
    }

    $found = false;
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        $remaining = $deadline - microtime(true);
        $seconds = (int) $remaining;
        $micros = (int) (($remaining - $seconds) * 1000000);
        $read = array($connection->stream);
        $write = null;
        $except = null;

        $ready = @stream_select($read, $write, $except, $seconds, $micros);
        if ($ready === false || $ready === 0) {
            break;
        }

        $line = fgets($connection->stream);
        if ($line === false) {
            break;
        }
        $line = rtrim($line, "\r\n");

        if (preg_match('/^\* \d+ EXISTS\b/', $line)) {
            $found = true;
            break;
        }
    }

    @fwrite($connection->stream, "DONE\r\n");
    $ignored = array();
    $resultline = rawimap_read_command_result($connection, $tag, $ignored, 10);
    if ($resultline === false) {
        // If DONE response is missing we still return whether an EXISTS was seen.
        return $found;
    }

    if (!rawimap_was_ok($resultline)) {
        rawimap_set_error(sprintf("IDLE completion failed: %s", $resultline));
    }

    return $found;
}
