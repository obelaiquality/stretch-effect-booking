<?php
declare(strict_types=1);

// Usage: php bin/create-owner.php            (interactive)
//        php bin/create-owner.php --name="Ivan Bond" --email=ivan@example.com --password=...  (non-interactive)
// Creates the owner account and prints the authenticator (TOTP) secret and otpauth:// URI. Shown once.
require __DIR__ . '/_boot.php';

use StretchEffect\Services\AuthService;
use StretchEffect\Services\Totp;
use StretchEffect\Support\Time;

$app = se_app();
$db = $app->db();
$o = se_opts($argv);

$name = $o['name'] ?? se_ask('Owner name: ');
$email = strtolower($o['email'] ?? se_ask('Owner email: '));
if ($name === '' || mb_strlen($name) > 120 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Invalid name or email.\n");
    exit(1);
}
$password = $o['password'] ?? se_ask('Password (min 12 characters): ', true);
if (strlen($password) < 12) {
    fwrite(STDERR, "Password must be at least 12 characters.\n");
    exit(1);
}
if (!isset($o['password']) && $password !== se_ask('Repeat password: ', true)) {
    fwrite(STDERR, "Passwords do not match.\n");
    exit(1);
}
if ($db->val('SELECT 1 FROM users WHERE email = ?', [$email]) !== null) {
    fwrite(STDERR, "A user with that email already exists.\n");
    exit(1);
}
if ($db->val("SELECT 1 FROM users WHERE role = 'owner'") !== null && !isset($o['force'])) {
    fwrite(STDERR, "An owner already exists. Use --force to add another.\n");
    exit(1);
}

$secret = Totp::generateSecret();
$id = $db->newId();
$db->run(
    "INSERT INTO users (id, role, name, email, phone, password_hash, totp_secret_enc, totp_enabled, created_at) VALUES (?, 'owner', ?, ?, NULL, ?, ?, 1, ?)",
    [$id, $name, $email, AuthService::hashPassword($password), $app->crypto()->encrypt($secret), Time::toDb($db->now())]
);

echo "\nOwner created: $name <$email>\n\n";
echo "Add this account to an authenticator app (Google Authenticator, Authy, 1Password ...):\n";
echo "  Secret : $secret\n";
echo "  URI    : " . Totp::otpauthUri($secret, $email) . "\n\n";
echo "Store the secret somewhere safe. It is not shown again.\n";
