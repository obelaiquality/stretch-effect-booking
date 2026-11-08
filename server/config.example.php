<?php
// Copy to server/config.php (gitignored) and fill in. Never commit config.php.
return [
    'app' => [
        // Public origin, no trailing slash. Used for OAuth metadata, redirects and cookies.
        'base_url' => 'https://thestretcheffect.co.za',
        // 'dev' = NullMailer (mail.log), no HSTS, Secure cookie only on https. 'live' = real mail, HSTS, Secure cookies.
        'env' => 'live',
        // base64 of 32 random bytes (libsodium secretbox key). Generate:
        //   php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
        // Encrypts the Google refresh token and TOTP secrets. Losing it means re-connecting Google and re-enrolling 2FA.
        'key' => 'CHANGE_ME_BASE64_32_BYTES',
    ],
    'db' => [
        // MySQL on Afrihost: 'mysql:host=localhost;dbname=cpaneluser_stretch;charset=utf8mb4'
        // Local dev/tests:   'sqlite:/absolute/path/to/server/var/dev.sqlite'  (or 'sqlite::memory:')
        'dsn' => 'mysql:host=localhost;dbname=CHANGE_ME;charset=utf8mb4',
        'user' => 'CHANGE_ME',
        'pass' => 'CHANGE_ME',
    ],
    'google' => [
        // Google Cloud Console > APIs & Services > Credentials > OAuth client (Web application).
        'client_id' => '',
        'client_secret' => '',
        // Must be listed as an authorised redirect URI in the OAuth client. Default: <base_url>/api/admin/calendar/callback
        'redirect_uri' => 'https://thestretcheffect.co.za/api/admin/calendar/callback',
        // 'primary' = the connected account's main calendar, or the calendar's email address.
        'calendar_id' => 'primary',
    ],
    'mail' => [
        // From address for booking confirmations. Use an address on your own domain (SPF/DKIM).
        'from' => 'The Stretch Effect <bookings@thestretcheffect.co.za>',
        // Optional SMTP. Leave smtp_host empty to use PHP mail() (live) or the dev log (dev).
        'smtp_host' => '',
        'smtp_port' => 465,
        'smtp_user' => '',
        'smtp_pass' => '',
        'smtp_secure' => 'ssl', // ssl (465) | tls (587 STARTTLS) | none
    ],
    'connector' => [
        // ChatGPT connector (OAuth + MCP): exact redirect URIs, prefixes, and hosts allowed for Client ID Metadata Documents.
        'allowed_redirect_uris' => ['https://chatgpt.com/connector_platform_oauth_redirect'],
        'allowed_redirect_prefixes' => ['https://chatgpt.com/connector/oauth/'],
        'cimd_hosts' => ['chatgpt.com', 'openai.com'],
    ],
    'cron' => [
        // Long random string. Used by POST /api/cron/sync (header X-Cron-Token). CLI cron (bin/cron.php) needs no token.
        'token' => 'CHANGE_ME_LONG_RANDOM',
    ],
];
