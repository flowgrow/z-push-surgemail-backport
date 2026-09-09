<?php
/** Minimal RFC 5804 client. Credentials are sent only after verified STARTTLS. */
class ZPushManageSieve {
    private $socket;

    public $extensions = [];
    const LIMIT = 1048576;

    public function __construct($host, $port, $username, $password, $preTlsCapabilities = false) {
        if (!preg_match('/^[a-zA-Z0-9.-]+$/D', $host) || $port < 1 || $port > 65535 ||
            strpos($username, "\0") !== false || strpos($password, "\0") !== false) {
            throw new RuntimeException('Invalid ManageSieve configuration');
        }

        $context = stream_context_create(['ssl' => ['verify_peer'=>true, 'verify_peer_name'=>true,
            'peer_name'=>$host, 'allow_self_signed'=>false, 'SNI_enabled'=>true]]);
        $this->socket = @stream_socket_client("tcp://$host:$port", $errno, $error, 10, STREAM_CLIENT_CONNECT, $context);
        if (!$this->socket) throw new RuntimeException('ManageSieve connection failed');
        stream_set_timeout($this->socket, 10);
        $greeting = $this->response();
        if (!isset($this->capabilities($greeting)['STARTTLS'])) throw new RuntimeException('ManageSieve requires STARTTLS');
        $this->simple('STARTTLS');
        // Purelymail emits another capability response before starting TLS.
        // Drain and discard it so no pre-TLS bytes can acknowledge authentication.
        if ($preTlsCapabilities) $this->response();
        if (!@stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
            throw new RuntimeException('ManageSieve TLS verification failed');
        }
        // RFC 5804: discard pre-TLS capabilities and request them over TLS.
        $caps = $this->capabilities($this->command('CAPABILITY'));
        if (!in_array('PLAIN', explode(' ', $caps['SASL'] ?? ''), true)) throw new RuntimeException('ManageSieve authentication unavailable');
        $this->extensions = explode(' ', strtolower($caps['SIEVE'] ?? ''));
        $this->simple('AUTHENTICATE "PLAIN" ' . self::quote(base64_encode("\0$username\0$password")));
    }

    public function __destruct() { if (is_resource($this->socket)) fclose($this->socket); }
    public static function quote($value) {
        if (preg_match('/[\x00-\x1f\x7f]/', $value)) throw new RuntimeException('Invalid ManageSieve string');
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
    private function capabilities($rows) {
        $caps = [];
        foreach ($rows as $row) $caps[strtoupper($row[0])] = $row[1] ?? '';
        return $caps;
    }
    private function write($data) {
        while ($data !== '') {
            $n = @fwrite($this->socket, $data);
            if (!$n) throw new RuntimeException('ManageSieve write failed');
            $data = substr($data, $n);
        }
    }
    private function line() {
        $line = @fgets($this->socket, self::LIMIT + 1);
        if ($line === false || !str_ends_with($line, "\r\n")) throw new RuntimeException('Invalid or incomplete ManageSieve response');
        return substr($line, 0, -2);
    }
    private function response() {
        $rows = []; $total = 0;
        while (true) {
            $line = $this->line(); $total += strlen($line);
            if ($total > self::LIMIT) throw new RuntimeException('ManageSieve response too large');
            if (preg_match('/^OK(?:\s|$)/i', $line)) return $rows;
            // Do not log server text: it may echo script bodies or authentication data.
            if (preg_match('/^(NO|BYE)(?:\s|$)/i', $line)) throw new RuntimeException('ManageSieve command rejected');
            $tokens = [];
            while ($line !== '') {
                $line = ltrim($line, ' ');
                if ($line === '') break;
                if (preg_match('/^\{([0-9]+)\+?\}$/D', $line, $m)) {
                    $n = (int)$m[1]; $total += $n;
                    if ($total > self::LIMIT) throw new RuntimeException('ManageSieve literal too large');
                    $value = '';
                    while (strlen($value) < $n) {
                        $part = @fread($this->socket, $n - strlen($value));
                        if ($part === false || $part === '') throw new RuntimeException('Truncated ManageSieve literal');
                        $value .= $part;
                    }
                    $tokens[] = $value; $line = $this->line();
                } elseif ($line[0] === '"') {
                    $value = ''; $i = 1; $closed = false;
                    for (; $i < strlen($line); $i++) {
                        if ($line[$i] === '"') { $closed = true; $i++; break; }
                        if ($line[$i] === '\\') {
                            $i++;
                            if ($i >= strlen($line) || !in_array($line[$i], ['"', '\\'], true)) throw new RuntimeException('Invalid ManageSieve escape');
                        }
                        $value .= $line[$i];
                    }
                    if (!$closed) throw new RuntimeException('Unterminated ManageSieve string');
                    $tokens[] = $value; $line = substr($line, $i);
                } elseif (preg_match('/^([^ ]+)(.*)$/s', $line, $m)) {
                    $tokens[] = $m[1]; $line = $m[2];
                } else throw new RuntimeException('Invalid ManageSieve response');
            }
            if ($tokens) $rows[] = $tokens;
        }
    }
    private function command($command) { $this->write($command . "\r\n"); return $this->response(); }
    private function simple($command) {
        if ($this->command($command) !== []) throw new RuntimeException('Unexpected ManageSieve response data');
    }
    public function scripts() {
        $scripts = [];
        foreach ($this->command('LISTSCRIPTS') as $row) {
            if (count($row) > 2 || (count($row) === 2 && strtoupper($row[1]) !== 'ACTIVE')) throw new RuntimeException('Invalid ManageSieve script list');
            $scripts[$row[0]] = isset($row[1]);
        }
        ksort($scripts, SORT_STRING);
        return $scripts;
    }
    public function get($name) {
        $rows = $this->command('GETSCRIPT ' . self::quote($name));
        if (count($rows) !== 1 || count($rows[0]) !== 1) throw new RuntimeException('Invalid ManageSieve script response');
        return $rows[0][0];
    }
    public function put($name, $script) {
        if (strlen($script) > self::LIMIT / 2) throw new RuntimeException('Sieve script too large');
        $this->simple('PUTSCRIPT ' . self::quote($name) . ' {' . strlen($script) . "+}\r\n" . $script);
    }
    public function activate($name) { $this->simple('SETACTIVE ' . self::quote($name)); }
    public function delete($name) { $this->simple('DELETESCRIPT ' . self::quote($name)); }
}
