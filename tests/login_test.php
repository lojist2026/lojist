<?php
require __DIR__ . '/app/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([strtolower(trim($_POST['email']))]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($_POST['senha'], $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        echo '<h1 style="color: green;">Login OK!</h1>';
        echo '<p>Usuário: ' . htmlspecialchars($user['email']) . '</p>';
        echo '<p>Role: ' . $user['role'] . '</p>';
        echo '<p>Status: ' . $user['status_conta'] . '</p>';
        echo '<p><a href="login_test.php">Voltar</a></p>';
        exit;
    } else {
        echo '<h1 style="color: red;">Login falhou!</h1>';
        echo '<p>Email ou senha incorretos.</p>';
        echo '<p><a href="login_test.php">Tentar novamente</a></p>';
        exit;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Teste de Login</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 400px; margin: 50px auto; padding: 20px; }
        h1 { color: #333; }
        form { background: #f5f5f5; padding: 20px; border-radius: 8px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input { width: 100%; padding: 10px; margin-bottom: 15px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        button { width: 100%; padding: 12px; background: #0066ff; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        button:hover { background: #0055dd; }
    </style>
</head>
<body>
    <h1>Teste de Login</h1>
    <form method="post">
        <label>Email:</label>
        <input type="email" name="email" required value="admin@lojist.com">
        
        <label>Senha:</label>
        <input type="password" name="senha" required value="admin123">
        
        <button type="submit">Entrar</button>
    </form>
    
    <h2>Credenciais de teste:</h2>
    <ul>
        <li><strong>Admin:</strong> admin@lojist.com / admin123</li>
        <li><strong>Lojista:</strong> lojista@lojist.com / lojista123</li>
    </ul>
</body>
</html>
