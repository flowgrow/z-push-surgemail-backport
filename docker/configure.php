<?php
function config($file, $values) {
    $text = file_get_contents($file);
    foreach ($values as $name => $value) {
        $text = preg_replace_callback('/define\\(\\s*[\'\"]' . preg_quote($name, '/') . '[\'\"]\\s*,.*?\\);/',
            fn() => "define('" . $name . "', " . var_export($value, true) . ");", $text, -1, $count);
        if (!$count) throw new RuntimeException('Missing configuration: ' . $name);
    }
    file_put_contents($file, $text);
}
foreach (['config.php', 'autodiscover/config.php'] as $file) {
    config($file, ['BACKEND_PROVIDER'=>'BackendCombined', 'TIMEZONE'=>'Europe/Vienna',
        'USE_FULLEMAIL_FOR_LOGIN'=>true]);
}
config('config.php', ['PING_HIGHER_BOUND_LIFETIME'=>300, 'LOGAUTHFAIL'=>true]);
config('backend/imap/config.php', ['IMAP_SERVER'=>'mailserver.purelymail.com', 'IMAP_PORT'=>993,
    'IMAP_OPTIONS'=>'/ssl/norsh', 'IMAP_USE_RAWIMAP_OVERVIEW'=>false, 'IMAP_FOLDER_CONFIGURED'=>true,
    'IMAP_FOLDER_INBOX'=>'INBOX', 'IMAP_FOLDER_SENT'=>'Sent', 'IMAP_FOLDER_DRAFT'=>'Drafts',
    'IMAP_FOLDER_TRASH'=>'Trash', 'IMAP_FOLDER_SPAM'=>'Junk', 'IMAP_FOLDER_ARCHIVE'=>'Archive',
    'IMAP_SMTP_METHOD'=>'smtp', 'IMAP_SIEVE_ENABLED'=>true, 'IMAP_SIEVE_PRE_TLS_CAPABILITIES'=>true]);
file_put_contents('backend/imap/config.php', "\n\$imap_smtp_params = ['host'=>'ssl://mailserver.purelymail.com','port'=>465,'auth'=>true,'username'=>'imap_username','password'=>'imap_password'];\n", FILE_APPEND);
file_put_contents('autodiscover/config.php', "\ndefine('ZPUSH_HOST', 'zpush.kniff.at');\n", FILE_APPEND);
config('backend/caldav/config.php', ['CALDAV_SERVER'=>'purelymail.com', 'CALDAV_PATH'=>'/.well-known/caldav', 'CALDAV_SUPPORTS_SYNC'=>false, 'CALDAV_PERSONAL'=>'2068226B-30F7-4618-A7FD-045AD11A26FD', 'CALDAV_EXCLUDED_CALENDARS'=>['default'], 'CALDAV_SERVER_TIME_RANGE'=>false]);
config('backend/carddav/config.php', ['CARDDAV_SERVER'=>'purelymail.com', 'CARDDAV_PATH'=>'/.well-known/carddav',
    'CARDDAV_DEFAULT_PATH'=>'/.well-known/carddav', 'CARDDAV_SUPPORTS_SYNC'=>false]);
$text=file_get_contents('backend/carddav/config.php');
$text=preg_replace("/define\\('CARDDAV_GAL_PATH'.*?;/", '', $text);
file_put_contents('backend/carddav/config.php', $text);
