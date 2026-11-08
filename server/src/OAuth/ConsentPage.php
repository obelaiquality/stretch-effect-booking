<?php
declare(strict_types=1);

namespace StretchEffect\OAuth;

/** Self-contained, branded HTML for the sign-in + consent screen and for error pages. No scripts, no external assets. */
final class ConsentPage
{
    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function shell(string $title, string $body): string
    {
        $t = self::e($title);
        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="referrer" content="no-referrer">
<title>{$t}</title>
<style>
:root{--ink:#0a0a0b;--panel:#131315;--line:#2a2a2f;--red:#c8102e;--red-hi:#e01a36;--silver:#c9ccd2;--white:#f4f4f2;--muted:#8d8d95}
*{box-sizing:border-box}
body{margin:0;background:var(--ink);color:var(--white);font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;min-height:100vh}
main{max-width:480px;margin:0 auto;padding:32px 16px 64px}
.brand{text-align:center;margin-bottom:24px}
.brand .a{letter-spacing:.5em;font-size:12px;color:var(--silver)}
.brand .b{font-family:Georgia,"Times New Roman",serif;font-weight:300;font-size:34px;letter-spacing:.28em;text-transform:uppercase;margin:4px 0}
.brand .c{color:var(--red);letter-spacing:.5em;font-size:12px;border-top:1px solid var(--red);border-bottom:1px solid var(--red);display:inline-block;padding:2px 12px}
.card{background:var(--panel);border:1px solid var(--line);border-radius:6px;padding:24px}
h1{font-size:20px;margin:0 0 8px;font-weight:600}
p{margin:0 0 16px;color:var(--silver)}
.who{border-left:3px solid var(--red);padding:8px 12px;background:var(--ink);margin:0 0 16px;color:var(--white);word-break:break-word}
.who small{display:block;color:var(--muted)}
fieldset{border:1px solid var(--line);border-radius:4px;margin:0 0 16px;padding:12px}
legend{color:var(--silver);padding:0 8px;font-size:14px}
.sc{display:flex;gap:12px;align-items:flex-start;padding:8px 0;min-height:44px}
.sc input{margin-top:5px;width:20px;height:20px;accent-color:var(--red)}
.sc label{flex:1}.sc span{display:block;color:var(--muted);font-size:13px}
label.f{display:block;margin:0 0 12px;color:var(--silver);font-size:14px}
input[type=email],input[type=password],input[type=text]{display:block;width:100%;margin-top:4px;padding:12px;font-size:16px;color:var(--white);background:var(--ink);border:1px solid var(--line);border-radius:4px;min-height:44px}
input:focus-visible,button:focus-visible{outline:2px solid var(--red-hi);outline-offset:2px}
.row{display:flex;gap:12px;margin-top:8px}
button{flex:1;min-height:44px;padding:12px;font-size:16px;font-weight:600;border-radius:2px;border:1px solid var(--line);background:var(--panel);color:var(--white);cursor:pointer}
button.go{background:var(--red);border-color:var(--red)}
button.go:hover{background:var(--red-hi)}
.new{background:#3a0a12;border:2px solid var(--red-hi);color:var(--white);padding:12px;border-radius:4px;margin:0 0 16px;font-weight:600}
.uri{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px;word-break:break-all;color:var(--white)}
.err{background:#2a0a10;border:1px solid var(--red);color:var(--white);padding:12px;border-radius:4px;margin:0 0 16px}
.fine{color:var(--muted);font-size:13px;margin-top:16px}
</style>
</head>
<body>
<main>
<div class="brand"><div class="a">THE</div><div class="b">Stretch</div><div class="c">EFFECT</div></div>
{$body}
</main>
</body>
</html>
HTML;
    }

    public static function error(string $message): string
    {
        $m = self::e($message);
        return self::shell('Sign-in problem', "<div class=\"card\"><h1>This request cannot continue</h1><p>{$m}</p></div>");
    }

    /** Shown when a brand-new client reaches /oauth/authorize while the owner has not opened the pairing window. */
    public static function pairingRequired(): string
    {
        return self::shell('Connection not allowed yet', '<div class="card"><h1>This connection is not allowed yet</h1>'
            . "<p>To connect a new ChatGPT, open Admin &rarr; ChatGPT and click 'Allow a new connection', then try again within 15 minutes.</p>"
            . '<p class="fine">If you did not just try to connect ChatGPT to The Stretch Effect, ignore this page. Nothing was changed.</p></div>');
    }

    /**
     * @param array{name:string,client_id:string} $client
     * @param list<string> $requestedScopes
     */
    public static function consent(array $client, string $redirectUri, array $requestedScopes, string $reqId, string $csrf, ?string $error, string $email = '', string $registered = '', bool $isNew = false): string
    {
        $name = self::e($client['name']);
        $cid = self::e($client['client_id']);
        $uri = self::e($redirectUri);
        $reg = $registered !== '' ? self::e(substr($registered, 0, 16) . ' UTC') : 'unknown';
        $newHtml = $isNew ? '<div class="new" role="alert">This is a new connection. Only continue if you just clicked &lsquo;Allow a new connection&rsquo; in Admin and started this from your own ChatGPT.</div>' : '';
        $scopeRows = '';
        foreach ($requestedScopes as $s) {
            $label = self::e(Scopes::DESCRIPTIONS[$s] ?? $s);
            $id = 'sc_' . preg_replace('/[^a-z]/', '_', $s);
            $sv = self::e($s);
            $scopeRows .= "<div class=\"sc\"><input type=\"checkbox\" id=\"{$id}\" name=\"scope[]\" value=\"{$sv}\" checked>"
                . "<label for=\"{$id}\">{$label}<span>{$sv}</span></label></div>";
        }
        $errHtml = $error !== null ? '<div class="err" role="alert">' . self::e($error) . '</div>' : '';
        $em = self::e($email);
        $req = self::e($reqId);
        $cs = self::e($csrf);
        $body = <<<HTML
<div class="card">
<h1>Connect an app to your bookings</h1>
<p>Only the owner of The Stretch Effect can approve this. Sign in with your password and your authenticator code.</p>
<div class="who"><strong>{$name}</strong><small>Client: {$cid}</small><small>Registered: {$reg}</small><small>Returns to:</small><span class="uri">{$uri}</span></div>
{$newHtml}
{$errHtml}
<form method="post" action="/oauth/authorize" autocomplete="off">
<input type="hidden" name="req" value="{$req}">
<input type="hidden" name="csrf" value="{$cs}">
<fieldset><legend>This app will be allowed to</legend>{$scopeRows}
<p class="fine" style="margin-bottom:0">Untick anything you do not want it to do. Access lasts until you revoke it; sign-in is refreshed every 30 days.</p></fieldset>
<label class="f">Email<input type="email" name="email" value="{$em}" required autocomplete="username"></label>
<label class="f">Password<input type="password" name="password" required autocomplete="current-password"></label>
<label class="f">6-digit authenticator code<input type="text" name="totp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code"></label>
<div class="row"><button type="submit" name="decision" value="deny" formnovalidate>Deny</button><button type="submit" name="decision" value="allow" class="go">Allow</button></div>
</form>
<p class="fine">If you did not start this from ChatGPT, press Deny and close this page.</p>
</div>
HTML;
        return self::shell('Connect to The Stretch Effect', $body);
    }
}
