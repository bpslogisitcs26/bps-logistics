<?php
/* Run ONCE after uploading and configuring config.php, then DELETE install.php. */
$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'Asia/Kolkata');
try {
    $dsn = 'mysql:host='.$config['host'].';dbname='.$config['database'].';charset='.$config['charset'];
    $pdo = new PDO($dsn, $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $count = (int)$pdo->query('SELECT COUNT(*) FROM app_users')->fetchColumn();
    if ($count > 0) {
        $message = 'Login users already exist. For security, delete install.php now.';
    } else {
        $stmt = $pdo->prepare('INSERT INTO app_users (username,password_hash,role,is_active) VALUES (?,?,?,1)');
        $stmt->execute(['ADMIN', password_hash('admin@1234', PASSWORD_DEFAULT), 'ADMIN']);
        $stmt->execute(['BABU', password_hash('BABU@1234', PASSWORD_DEFAULT), 'BABU']);
        $message = 'ADMIN and BABU accounts created successfully. DELETE install.php now.';
    }
} catch (Throwable $e) {
    http_response_code(500);
    $message = 'Setup failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>BPS Setup</title>
<style>body{font:16px Arial;background:#f3f6fb;padding:30px}.box{max-width:650px;margin:8vh auto;background:white;padding:28px;border-radius:14px;box-shadow:0 8px 30px #0001}h1{color:#142344}p{line-height:1.6}.warn{background:#fff4d7;padding:12px;border-radius:8px;color:#72551b}</style>
<div class="box"><h1>BPS LOGISTICS Setup</h1><p><?= $message ?></p><p>ADMIN login: <b>ADMIN</b> / <b>admin@1234</b><br>BABU login: <b>BABU</b> / <b>BABU@1234</b></p><p class="warn">Important: Delete install.php from htdocs immediately after successful setup. Change passwords after first login once password-management is added.</p><p><a href="login.php">Open Login</a></p></div></html>
