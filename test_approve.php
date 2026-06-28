<?php
require_once __DIR__ . '/app/bootstrap.php';
$pdo = db();
$pdo->exec("INSERT INTO users (nome, sobrenome, cpf, email, telefone, nome_loja, cidade, estado, endereco_completo, password_hash) VALUES ('Test', 'User', '99999999999', 'test@test.com', '999', 'Test Loja', 'CG', 'MS', 'End', 'hash')");
$userId = $pdo->lastInsertId();
echo "Inserted user $userId\n";

$_POST['action'] = 'admin_user_status';
$_POST['user_id'] = $userId;
$_POST['status'] = 'aprovado';
$_SESSION['user_id'] = 1; // Assuming 1 is admin

try {
    $trial = date('Y-m-d H:i:s', strtotime('+1 month'));
    $pdo->prepare("UPDATE users SET status_conta='aprovado', aprovado_em=COALESCE(aprovado_em, NOW()), trial_ends_at=COALESCE(trial_ends_at, ?), subscription_status = CASE WHEN paid_until IS NOT NULL AND paid_until >= NOW() THEN 'active' ELSE 'trialing' END, plano=COALESCE(NULLIF(plano,''),'Free') WHERE id=?")->execute([$trial, (int)$userId]);
    echo "Update executed.\n";
    $user = $pdo->query("SELECT status_conta, role FROM users WHERE id = $userId")->fetch();
    print_r($user);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

