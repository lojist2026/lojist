<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo '<h1>Teste de conexão MySQL</h1>';

try {
    $pdo = new PDO('mysql:host=localhost;dbname=lojist;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    echo '<p style="color: green;">Conexão MySQL OK!</p>';
    
    $stmt = $pdo->query('SELECT id, email, role, status_conta FROM users LIMIT 5');
    $users = $stmt->fetchAll();
    
    echo '<h2>Usuários encontrados:</h2>';
    echo '<ul>';
    foreach ($users as $user) {
        echo '<li>';
        echo 'ID: ' . $user['id'] . ' - ';
        echo 'Email: ' . htmlspecialchars($user['email']) . ' - ';
        echo 'Role: ' . $user['role'] . ' - ';
        echo 'Status: ' . $user['status_conta'];
        echo '</li>';
    }
    echo '</ul>';
    
} catch (Exception $e) {
    echo '<p style="color: red;">Erro: ' . htmlspecialchars($e->getMessage()) . '</p>';
}

echo '<h1>Teste de sessão</h1>';
session_start();
echo 'Sessão ID: ' . session_id() . '<br>';
$_SESSION['test'] = 'Olá, sessão funcionando!';
echo 'Valor: ' . $_SESSION['test'];
