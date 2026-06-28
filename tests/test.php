<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require __DIR__ . '/app/bootstrap.php';

echo '<h1>Teste de conexão com o banco</h1>';
try {
    $pdo = db();
    echo '<p style="color: green;">Conexão com o banco OK!</p>';
    
    $stmt = $pdo->query('SELECT * FROM users LIMIT 1');
    $user = $stmt->fetch();
    if ($user) {
        echo '<p>Usuário encontrado: ' . htmlspecialchars($user['email']) . '</p>';
    }
} catch (Exception $e) {
    echo '<p style="color: red;">Erro no banco: ' . htmlspecialchars($e->getMessage()) . '</p>';
}

echo '<h1>Teste de sessão</h1>';
$_SESSION['test'] = 'Olá, mundo!';
echo 'Valor da sessão: ' . htmlspecialchars($_SESSION['test'] ?? 'não definido');
