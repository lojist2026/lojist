<?php
require_once __DIR__ . '/app/bootstrap.php';
$pdo = db();

// Simulate registration (skip insert since it exists)
// $pdo->exec("INSERT INTO users (nome, sobrenome, cpf, email, telefone, nome_loja, cidade, estado, endereco_completo, password_hash) VALUES ('Test2', 'User2', '88888888888', 'test2@test.com', '999', 'Test Loja 2', 'CG', 'MS', 'End', 'hash')");
$userId = $pdo->query("SELECT id FROM users WHERE cpf = '88888888888'")->fetchColumn();

// Verify user is in aguardando_aprovacao
$user = $pdo->query("SELECT status_conta, role FROM users WHERE id = $userId")->fetch();
echo "Before approval: status=" . $user['status_conta'] . " role=" . $user['role'] . "\n";

// Simulate clicking 'Aprovar'
$_POST['action'] = 'admin_user_status';
$_POST['user_id'] = $userId;
$_POST['status'] = 'aprovado';
$_SESSION['user_id'] = 1; // Admin
$_SESSION['_csrf_token'] = 'test';
$_POST['_csrf_token'] = 'test';
$_SERVER['REQUEST_METHOD'] = 'POST';

// Execute the block from index.php
try {
    $status = (string)post('status');
    if ($status === 'aprovado') {
        $trial = date('Y-m-d H:i:s', strtotime('+1 month'));
        $pdo->prepare("UPDATE users SET status_conta='aprovado', aprovado_em=COALESCE(aprovado_em, NOW()), trial_ends_at=COALESCE(trial_ends_at, ?), subscription_status = CASE WHEN paid_until IS NOT NULL AND paid_until >= NOW() THEN 'active' ELSE 'trialing' END, plano=COALESCE(NULLIF(plano,''),'Free') WHERE id=?")->execute([$trial, (int)post('user_id')]);
        add_notification((int)post('user_id'), 'cadastro_aprovado', 'Cadastro aprovado', 'Seu acesso foi liberado com 1 mês de teste grátis na LOJIST.');
    }
    echo "Approval code ran.\n";
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}

// Verify user is in aprovado
$user = $pdo->query("SELECT status_conta, role FROM users WHERE id = $userId")->fetch();
echo "After approval: status=" . $user['status_conta'] . " role=" . $user['role'] . "\n";

// Verify user appears in 'users' tab query
$where = "WHERE role='lojista' AND status_conta='aprovado'";
$count = $pdo->query("SELECT COUNT(*) FROM users $where AND id = $userId")->fetchColumn();
echo "Appears in query: " . ($count > 0 ? "Yes" : "No") . "\n";
