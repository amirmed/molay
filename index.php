<?php
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (login($username, $password)) {
        header('Location: dashboard.php');
        exit;
    } else {
        $error = 'Nom d\'utilisateur ou mot de passe incorrect';
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MOLAY - Connexion</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Cairo', sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-container {
            background: #fff;
            border-radius: 20px;
            padding: 50px 40px;
            width: 420px;
            max-width: 95%;
            box-shadow: 0 25px 60px rgba(0,0,0,0.3);
            text-align: center;
        }
        .login-logo {
            margin: 0 auto 15px;
        }
        .login-logo img {
            max-width: 200px;
            height: auto;
        }
        .login-container h1 {
            display: none;
        }
        .login-container p { color: #919294; margin-bottom: 30px; font-size: 14px; }
        .form-group {
            position: relative; margin-bottom: 20px; text-align: right;
        }
        .form-group i {
            position: absolute; left: 15px; top: 50%; transform: translateY(-50%);
            color: #919294; font-size: 18px;
        }
        .form-group input {
            width: 100%; padding: 14px 20px 14px 45px;
            border: 2px solid #e8e8e8; border-radius: 12px;
            font-size: 15px; font-family: 'Cairo', sans-serif;
            transition: all 0.3s; background: #f8f9fa;
            direction: ltr; text-align: left;
        }
        .form-group input:focus {
            outline: none; border-color: #F38E21;
            background: #fff; box-shadow: 0 0 0 4px rgba(243,142,33,0.1);
        }
        .btn-login {
            width: 100%; padding: 14px;
            background: linear-gradient(135deg, #F38E21, #e07b15);
            color: #fff; border: none; border-radius: 12px;
            font-size: 16px; font-weight: 700; cursor: pointer;
            font-family: 'Cairo', sans-serif;
            transition: all 0.3s;
            box-shadow: 0 5px 20px rgba(243,142,33,0.3);
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(243,142,33,0.4);
        }
        .error-msg {
            background: #fee; color: #c00; padding: 10px; border-radius: 8px;
            margin-bottom: 20px; font-size: 14px;
        }
        .login-footer { margin-top: 25px; color: #919294; font-size: 12px; }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-logo"><img src="logo.png" alt="Moulay Chaabi"></div>
        <h1>MOLAY</h1>
        <p>نظام إدارة واستخلاص الفواتير</p>

        <?php if ($error): ?>
            <div class="error-msg"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <i class="fas fa-user"></i>
                <input type="text" name="username" placeholder="اسم المستخدم" required autofocus>
            </div>
            <div class="form-group">
                <i class="fas fa-lock"></i>
                <input type="password" name="password" placeholder="كلمة المرور" required>
            </div>
            <button type="submit" class="btn-login">
                <i class="fas fa-sign-in-alt"></i> تسجيل الدخول
            </button>
        </form>
        <div class="login-footer">admin / admin123 :الدخول الافتراضي</div>
    </div>
</body>
</html>
