<?php
$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'Asia/Kolkata');
session_name($config['session_name'] ?? 'BPSLOGISTICSSESSID');
session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS']),'samesite'=>'Lax']);
session_start();
if (!empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $dsn = 'mysql:host='.$config['host'].';dbname='.$config['database'].';charset='.$config['charset'];
        $pdo = new PDO($dsn, $config['username'], $config['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $stmt = $pdo->prepare('SELECT id,username,password_hash,role,is_active FROM app_users WHERE username=? LIMIT 1');
        $stmt->execute([strtoupper(trim($_POST['username'] ?? ''))]);
        $u = $stmt->fetch();
        if ($u && (int)$u['is_active'] === 1 && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']=(int)$u['id']; $_SESSION['username']=$u['username']; $_SESSION['role']=$u['role'];
            header('Location: index.php'); exit;
        }
        $error = 'Login ID অথবা Password সঠিক নয়।';
    } catch (Throwable $e) { $error = 'Database connection error. config.php-এর তথ্য পরীক্ষা করো।'; }
}
?>
<!doctype html><html lang="bn"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>BPS LOGISTICS Login</title><style>
*{box-sizing:border-box}body{margin:0;background:#101c36;font:15px Arial,sans-serif;min-height:100vh;display:grid;place-items:center;padding:16px}.login{background:#fff;width:min(410px,100%);padding:30px;border-radius:16px;box-shadow:0 18px 55px #0005}.logo{font-size:26px;font-weight:800;color:#2563eb}.sub{color:#718096;font-size:12px;margin:5px 0 24px}label{display:block;font-size:13px;margin:13px 0 6px;color:#526079}input{width:100%;padding:12px;border:1px solid #d9e0eb;border-radius:8px;font-size:15px}button{width:100%;margin-top:20px;background:#2563eb;color:#fff;border:0;border-radius:8px;padding:12px;font-weight:700;cursor:pointer}.err{background:#ffe9eb;color:#a52b39;padding:10px;border-radius:8px;font-size:13px}.hint{font-size:12px;color:#718096;line-height:1.6;margin-top:18px}</style></head><body><form class="login" method="post"><div class="logo">BPS LOGISTICS</div><div class="sub">ONLINE MANAGEMENT SYSTEM</div><?php if($error): ?><div class="err"><?=htmlspecialchars($error)?></div><?php endif; ?><label>Login ID</label><input name="username" autocomplete="username" required autofocus><label>Password</label><input type="password" name="password" autocomplete="current-password" required><button>LOGIN</button><div class="hint">অনুমোদিত User ছাড়া প্রবেশ করা যাবে না। Login তথ্য কারও সঙ্গে শেয়ার করবে না।</div></form></body></html>
