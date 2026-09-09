<?php
// SPDX-License-Identifier: AGPL-3.0-only
require_once __DIR__ . '/davsecurity.php';
final class ZPushCalendarBridge {
    public static function enabled() { return defined('CALDAV_SCHEDULING_BRIDGE') && CALDAV_SCHEDULING_BRIDGE !== ''; }
    public static function request(array $data) {
        $base = sprintf('%s://%s:%d/', CALDAV_PROTOCOL, CALDAV_SERVER, CALDAV_PORT);
        $url = ZPushDavSecurity::url(CALDAV_SCHEDULING_BRIDGE, $base);
        $curl = curl_init(); ZPushDavSecurity::curl($curl);
        curl_setopt_array($curl, [CURLOPT_URL=>$url, CURLOPT_POST=>true,
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,
            CURLOPT_USERPWD=>Request::GetAuthUser() . ':' . Request::GetAuthPassword(),
            CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_POSTFIELDS=>json_encode($data)]);
        $raw = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
        $result = is_string($raw) ? json_decode($raw,true) : null;
        if ($status !== 200 || !is_array($result)) throw new RuntimeException('Calendar scheduling bridge failed');
        return $result;
    }
    public static function response($number) {
        $statuses = [1=>'ACCEPTED',2=>'TENTATIVE',3=>'DECLINED'];
        if (!isset($statuses[$number])) throw new RuntimeException('Invalid meeting response');
        return $statuses[$number];
    }
    public static function hasReply($part) {
        if (isset($part->ctype_primary,$part->ctype_secondary) &&
            strtolower($part->ctype_primary . '/' . $part->ctype_secondary) === 'text/calendar' &&
            preg_match('/^METHOD:REPLY\s*$/mi', $part->body ?? '')) return true;
        foreach ($part->parts ?? [] as $child) if (self::hasReply($child)) return true;
        return false;
    }
}
