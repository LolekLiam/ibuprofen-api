#!/usr/bin/env php
<?php
require dirname(__DIR__).'/vendor/autoload.php';
use App\Api\{Config,Storage,AdminAuth};
$command=$argv[1]??''; $username=$argv[2]??'';
if (!in_array($command,['create','disable'],true) || !$username) { fwrite(STDERR,"Usage: php bin/admin.php create|disable USERNAME\n"); exit(1); }
$db=new Storage(new Config());
if ($command==='disable') {
    $db->atomic(function () use ($db,$username) {
        $db->query('UPDATE admins SET enabled=0 WHERE username=?',[$username]);
        $db->query('DELETE FROM sessions WHERE admin_id=(SELECT id FROM admins WHERE username=?)',[$username]);
    });
    echo "Admin disabled and sessions revoked.\n"; exit;
}
$password=getenv('IBUPROFEN_ADMIN_PASSWORD');
if ($password===false) {
    if (!stream_isatty(STDIN)) { fwrite(STDERR,"Use a terminal or supply IBUPROFEN_ADMIN_PASSWORD for automation.\n"); exit(1); }
    fwrite(STDOUT,"New admin password (12–72 bytes): ");
    system('stty -echo');
    try { $password=rtrim(fgets(STDIN),"\r\n"); } finally { system('stty echo'); echo "\n"; }
    fwrite(STDOUT,"Confirm password: "); system('stty -echo');
    try { $confirmation=rtrim(fgets(STDIN),"\r\n"); } finally { system('stty echo'); echo "\n"; }
    if (!hash_equals($password,$confirmation)) { fwrite(STDERR,"Passwords do not match.\n"); exit(1); }
}
try { (new AdminAuth($db))->create($username,$password); echo "Admin created/updated. Existing sessions revoked.\n"; }
catch (\Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
