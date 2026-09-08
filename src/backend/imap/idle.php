<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Read-only IMAP IDLE wakeups. Z-Push remains responsible for synchronization.
class ZPushIdleConnection {
    public $stream;
    public $folder;
    private $buffer = '';
    private $tag = 0;
    private $idleTag;
    private $changed = false;

    public static function quote($value) {
        if (preg_match('/[\x00\r\n]/', $value)) throw new RuntimeException('Unsupported IMAP string');
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    public function __construct($stream, $folder) {
        $this->stream = $stream;
        $this->folder = $folder;
        stream_set_blocking($stream, false);
    }

    public static function connect($host, $port, $user, $password, $folder, $deadline) {
        if (!preg_match('/^[a-zA-Z0-9.-]+$/D', $host)) throw new RuntimeException('Invalid IMAP hostname');
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host,
            'allow_self_signed' => false, 'SNI_enabled' => true,
        ]]);
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) throw new RuntimeException('IDLE setup timed out');
        $stream = @stream_socket_client('tls://' . $host . ':' . (int)$port, $errno, $error,
            min(5, $remaining), STREAM_CLIENT_CONNECT, $context);
        if (!$stream) throw new RuntimeException('IDLE TLS connection failed');
        $connection = new self($stream, $folder);
        try {
            $connection->authenticate($user, $password, $deadline);
            return $connection;
        } catch (Throwable $e) {
            $connection->close();
            throw $e;
        }
    }

    public function authenticate($user, $password, $deadline) {
        $greeting = $this->line($deadline);
        if (!preg_match('/^\* (OK|PREAUTH)\b/i', $greeting, $match))
            throw new RuntimeException('Invalid IMAP greeting');
        if (strtoupper($match[1]) !== 'PREAUTH')
            $this->command('LOGIN ' . self::quote($user) . ' ' . self::quote($password), $deadline);
        $capabilities = $this->command('CAPABILITY', $deadline);
        if (!preg_match('/^\* CAPABILITY .*\bIDLE\b/im', implode("\n", $capabilities)))
            throw new RuntimeException('IMAP IDLE unavailable');
        $this->command('EXAMINE ' . self::quote($this->folder), $deadline);
        $this->idleTag = 'ZP' . ++$this->tag;
        $this->write($this->idleTag . " IDLE\r\n", $deadline);
        while (true) {
            $line = $this->line($deadline);
            if (str_starts_with($line, '+')) break;
            $this->observe($line);
            if (str_starts_with($line, $this->idleTag . ' '))
                throw new RuntimeException('IDLE rejected');
        }
    }

    private function command($command, $deadline) {
        $tag = 'ZP' . ++$this->tag;
        $this->write($tag . ' ' . $command . "\r\n", $deadline);
        $lines = [];
        while (true) {
            $line = $this->line($deadline);
            if (str_starts_with($line, $tag . ' ')) {
                if (!preg_match('/^' . $tag . ' OK\b/i', $line))
                    throw new RuntimeException('IDLE setup command rejected');
                return $lines;
            }
            $lines[] = $line;
            if (count($lines) > 1024) throw new RuntimeException('Excessive IMAP response');
        }
    }

    private function write($data, $deadline) {
        while ($data !== '') {
            $read = []; $write = [$this->stream]; $except = [];
            self::select($read, $write, $except, $deadline);
            $n = @fwrite($this->stream, $data);
            if (!$n) throw new RuntimeException('IMAP write failed');
            $data = substr($data, $n);
        }
    }

    public static function select(&$read, &$write, &$except, $deadline) {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) throw new RuntimeException('IMAP operation timed out');
        $seconds = (int)$remaining;
        $n = @stream_select($read, $write, $except, $seconds, (int)(($remaining - $seconds) * 1000000));
        if ($n === false) throw new RuntimeException('IMAP socket selection failed');
        return $n;
    }

    private function readAvailable() {
        $chunk = @fread($this->stream, 8192);
        if ($chunk === false || ($chunk === '' && feof($this->stream)))
            throw new RuntimeException('IMAP connection closed');
        $this->buffer .= $chunk;
        if (strlen($this->buffer) > 65536) throw new RuntimeException('IMAP response too large');
    }

    private function extractLine() {
        $end = strpos($this->buffer, "\r\n");
        if ($end === false) return null;
        $line = substr($this->buffer, 0, $end);
        $this->buffer = substr($this->buffer, $end + 2);
        if (preg_match('/^\* BYE\b/i', $line)) throw new RuntimeException('IMAP server ended session');
        return $line;
    }

    private function line($deadline) {
        while (($line = $this->extractLine()) === null) {
            $read = [$this->stream]; $write = []; $except = [];
            if (!self::select($read, $write, $except, $deadline))
                throw new RuntimeException('IMAP response timed out');
            $this->readAvailable();
        }
        return $line;
    }

    private function observe($line) {
        if (preg_match('/^\* \d+ (EXISTS|EXPUNGE|FETCH)\b/i', $line) || preg_match('/^\* VANISHED\b/i', $line))
            $this->changed = true;
    }

    public function consume() {
        // Also read bytes buffered by PHP/TLS, not only OS-readable sockets.
        $this->readAvailable();
        while (($line = $this->extractLine()) !== null) {
            if ($this->idleTag && str_starts_with($line, $this->idleTag . ' '))
                throw new RuntimeException('IMAP server ended IDLE');
            $this->observe($line);
        }
        $changed = $this->changed;
        $this->changed = false;
        return $changed;
    }

    public function close() {
        if (is_resource($this->stream)) fclose($this->stream);
    }
    public function __destruct() { $this->close(); }
}

class ZPushIdlePool {
    private $connections = [];
    private $created;
    public function __construct($host, $port, $user, $password, $folders, $deadline) {
        $this->created = microtime(true);
        try {
            foreach ($folders as $folder)
                $this->connections[] = ZPushIdleConnection::connect($host, $port, $user, $password, $folder, $deadline);
        } catch (Throwable $e) { $this->close(); throw $e; }
    }
    public function expired() { return microtime(true) - $this->created >= 240; }
    public function wait($timeout) {
        $deadline = microtime(true) + max(0, $timeout);
        do {
            $changed = []; $read = [];
            foreach ($this->connections as $connection) {
                if ($connection->consume()) $changed[] = $connection->folder;
                $read[] = $connection->stream;
            }
            if ($changed) return $changed;
            if (microtime(true) >= $deadline || !$read) return [];
            $write = []; $except = [];
            if (!ZPushIdleConnection::select($read, $write, $except, $deadline)) return [];
        } while (true);
    }
    public function close() {
        foreach ($this->connections as $connection) $connection->close();
        $this->connections = [];
    }
    public function __destruct() { $this->close(); }
}
