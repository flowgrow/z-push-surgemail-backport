<?php
require_once __DIR__ . '/managesieve.php';

/** ActiveSync OOF backed by a marked section of the user's active Sieve script. */
class ZPushSieveOOF {
    const HEADER = "# Z-Push OOF requirements v1\r\nrequire [\"vacation\", \"date\", \"relational\"];\r\n# Z-Push OOF requirements end\r\n";
    const BEGIN = "\r\n# Z-Push OOF begin v1 ";
    const END = "# Z-Push OOF end v1\r\n";
    private $client;
    private $email;
    public function __construct($client, $email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('OOF requires a full email address');
        $this->client = $client; $this->email = $email;
    }
    private static function quote($text) {
        if (strpos($text, "\0") !== false) throw new InvalidArgumentException('Invalid OOF text');
        $text = preg_replace('/\r\n|\r|\n/', "\r\n", $text);
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $text) . '"';
    }
    public static function plain($text, $type) {
        if (strtoupper($type) !== 'HTML') return $text;
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $text);
        $text = preg_replace('#<br\s*/?>|</(?:p|div|li|tr|h[1-6])>#i', "\n", $text);
        return html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    public static function normalize($oof, $previous = null) {
        if (!in_array((string)$oof->oofstate, ['0','1','2'], true)) throw new InvalidArgumentException('Invalid OOF state');
        $state = ['state'=>(int)$oof->oofstate, 'start'=>null, 'end'=>null, 'messages'=>$previous['messages'] ?? []];
        $seen = [];
        if ($state['state'] === 2) {
            if (!is_numeric($oof->starttime) || !is_numeric($oof->endtime) || $oof->starttime >= $oof->endtime || $oof->starttime < 0 || $oof->endtime > 253402300799) {
                throw new InvalidArgumentException('Invalid OOF schedule');
            }
            $state['start'] = (int)$oof->starttime; $state['end'] = (int)$oof->endtime;
        }
        foreach ($oof->oofmessage as $message) {
            $audiences = [];
            foreach (['appliesToInternal','appliesToExternal','appliesToExternalUnknown'] as $key) {
                if (isset($message->$key)) $audiences[] = $key;
            }
            if (count($audiences) !== 1 || !in_array((string)$message->enabled, ['0','1'], true)) throw new InvalidArgumentException('Invalid OOF audience');
            $audience = $audiences[0];
            if (isset($seen[$audience])) throw new InvalidArgumentException('Duplicate OOF audience');
            $seen[$audience] = true;
            $type = strtoupper($message->bodytype ?? 'TEXT');
            $text = $message->replymessage ?? '';
            if (!in_array($type, ['TEXT','HTML'], true) || strlen($text) > 16384 || strpos($text, "\0") !== false || !mb_check_encoding($text, 'UTF-8')) throw new InvalidArgumentException('Invalid OOF message');
            $state['messages'][$audience] = ['enabled'=>(int)$message->enabled, 'text'=>self::plain($text, $type)];
        }
        if ($state['state'] !== 0) {
            $known = $state['messages']['appliesToExternal'] ?? ['enabled'=>0,'text'=>''];
            $unknown = $state['messages']['appliesToExternalUnknown'] ?? ['enabled'=>0,'text'=>''];
            if ($known['enabled'] !== $unknown['enabled'] || ($known['enabled'] && $known['text'] !== $unknown['text'])) {
                throw new InvalidArgumentException('Contacts-only external replies are not supported; choose all external senders');
            }
        }
        return $state;
    }
    public function rules($state) {
        if ($state['state'] === 0) return "# Automatic replies disabled\r\n";
        $tests = ['not exists "List-Id"', 'anyof (not exists "Auto-Submitted", header :is "Auto-Submitted" "no")',
            'not header :is "Precedence" ["bulk", "list", "junk"]', 'not header :is "X-Spam-Flag" "YES"'];
        if ($state['state'] === 2) {
            $tests[] = 'currentdate :zone "+0000" :value "ge" "iso8601" ' . self::quote(gmdate('Y-m-d\TH:i:s+00:00', $state['start']));
            $tests[] = 'currentdate :zone "+0000" :value "lt" "iso8601" ' . self::quote(gmdate('Y-m-d\TH:i:s+00:00', $state['end']));
        }
        $rules = 'if allof (' . implode(', ', $tests) . ") {\r\n";
        $domain = substr(strrchr($this->email, '@'), 1);
        foreach (['appliesToInternal'=>false, 'appliesToExternalUnknown'=>true] as $key=>$external) {
            $m = $state['messages'][$key] ?? ['enabled'=>0];
            if (!$m['enabled']) continue;
            $rules .= '  if ' . ($external ? 'not ' : '') . 'address :domain :is "from" ' . self::quote($domain) . " {\r\n";
            $rules .= '    vacation :days 7 :addresses [' . self::quote($this->email) . '] :handle "z-push-oof-v1" ' . self::quote($m['text']) . ";\r\n  }\r\n";
        }
        return $rules . "}\r\n";
    }
    public function split($script) {
        if (!str_contains($script, '# Z-Push OOF')) return [$script, null];
        if (!str_starts_with($script, self::HEADER) || !str_ends_with($script, self::END) || substr_count($script, self::BEGIN) !== 1) throw new RuntimeException('OOF section was edited outside Z-Push');
        $pos = strpos($script, self::BEGIN); $metaStart = $pos + strlen(self::BEGIN);
        $metaEnd = strpos($script, "\r\n", $metaStart);
        if ($metaEnd === false) throw new RuntimeException('Invalid OOF metadata');
        $meta = json_decode(base64_decode(substr($script, $metaStart, $metaEnd-$metaStart), true) ?: '', true);
        $rules = substr($script, $metaEnd+2, -strlen(self::END));
        if (!is_array($meta) || !isset($meta['settings'], $meta['sha256']) || !is_string($meta['sha256']) || !is_array($meta['settings']) || !hash_equals($meta['sha256'], hash('sha256', $rules))) throw new RuntimeException('OOF section integrity check failed');
        return [substr($script, strlen(self::HEADER), $pos-strlen(self::HEADER)), $meta['settings']];
    }
    public function compose($base, $state) {
        // Conservatively reject another vacation action rather than replace its settings.
        // False positives in comments/text are safe and resolved in the webmail filter editor.
        if ($state['state'] !== 0 && preg_match('/\bvacation\b/i', $base)) throw new InvalidArgumentException('An existing vacation filter must be disabled in webmail first');
        $rules = $this->rules($state);
        $meta = base64_encode(json_encode(['settings'=>$state, 'sha256'=>hash('sha256', $rules)], JSON_THROW_ON_ERROR));
        return self::HEADER . $base . self::BEGIN . $meta . "\r\n" . $rules . self::END;
    }
    public function settings($oof) {
        foreach (['vacation','date','relational'] as $ext) if (!in_array($ext, $this->client->extensions, true)) throw new RuntimeException('Required Sieve extension unavailable');
        $scripts = $this->client->scripts(); $active = array_search(true, $scripts, true);
        $script = $active === false ? '' : $this->client->get($active);
        [$base, $state] = $this->split($script);
        if (isset($oof->oofstate)) {
            $state = self::normalize($oof, $state);
            if ($active === false && $state['state'] === 0) { $oof->Status = 1; return $oof; }
            $updated = $this->compose($base, $state);
            // Check for edits while constructing the update. ManageSieve has no atomic CAS.
            if ($this->client->scripts() !== $scripts || ($active !== false && $this->client->get($active) !== $script)) throw new RuntimeException('Sieve filters changed; retry the setting');
            $name = $active === false ? 'z-push-autoreply-' . bin2hex(random_bytes(6)) : $active;
            $this->client->put($name, $updated); // Server validates before replacing a script.
            if ($active === false) {
                try { $this->client->activate($name); }
                catch (Throwable $e) { $this->client->delete($name); throw $e; }
            }
            if ($this->client->get($name) !== $updated) throw new RuntimeException('OOF update could not be verified');
        } else {
            $type = strtoupper($oof->bodytype ?? 'TEXT');
            $state = $state ?? ['state'=>0, 'start'=>null, 'end'=>null, 'messages'=>[]];
            $oof->oofstate = $state['state'];
            if ($state['state'] === 2) { $oof->starttime = $state['start']; $oof->endtime = $state['end']; }
            $oof->oofmessage = [];
            foreach (['appliesToInternal','appliesToExternal','appliesToExternalUnknown'] as $key) {
                $m = $state['messages'][$key] ?? ['enabled'=>0,'text'=>''];
                $message = new SyncOOFMessage(); $message->$key = '';
                $message->enabled = $m['enabled']; $message->bodytype = $type === 'HTML' ? 'HTML' : 'Text';
                $message->replymessage = $type === 'HTML' ? nl2br(htmlspecialchars($m['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) : $m['text'];
                $oof->oofmessage[] = $message;
            }
            unset($oof->bodytype);
        }
        $oof->Status = 1;
        return $oof;
    }
}
