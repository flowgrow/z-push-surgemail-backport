<?php
// SPDX-License-Identifier: AGPL-3.0-only
/** Keep DAV credentials on the configured HTTPS origin, including discovery. */
class ZPushDavSecurity {
    public static function url($url, $base) {
        $url = (string)$url;
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url)) throw new RuntimeException('Invalid DAV URL');
        $origin = parse_url($base);
        if (!$origin || ($origin['scheme'] ?? '') !== 'https' || empty($origin['host']))
            throw new RuntimeException('DAV requires HTTPS');
        if (str_starts_with($url, '/') && !str_starts_with($url, '//'))
            $url = 'https://' . $origin['host'] . ':' . ($origin['port'] ?? 443) . $url;
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' ||
            strtolower($parts['host'] ?? '') !== strtolower($origin['host']) ||
            ($parts['port'] ?? 443) !== ($origin['port'] ?? 443) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']))
            throw new RuntimeException('Cross-origin DAV URL rejected');
        return $url;
    }

    public static function curl($curl) {
        curl_setopt_array($curl, [CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>30]);
    }

    /** Purelymail's authenticated well-known redirect gives each user's DAV home. */
    public static function discover($base, $user, $password) {
        $url = self::url($base, $base);
        for ($i = 0; $i < 4; $i++) {
            $curl = curl_init(); self::curl($curl);
            curl_setopt_array($curl, [CURLOPT_URL=>$url, CURLOPT_NOBODY=>true,
                CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,
                CURLOPT_USERPWD=>$user . ':' . $password]);
            $ok = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $redirect = curl_getinfo($curl, CURLINFO_REDIRECT_URL); curl_close($curl);
            if ($ok === false) throw new RuntimeException('DAV discovery TLS/request failed');
            if (in_array($status, [301,302,303,307,308], true) && $redirect) {
                $url = self::url($redirect, $base); continue;
            }
            if ($status >= 200 && $status < 300) return $url;
            throw new RuntimeException('DAV discovery authentication failed');
        }
        throw new RuntimeException('Too many DAV redirects');
    }
}
