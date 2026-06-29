<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/features.php';

$config = app_config();

$page = $_GET['p'] ?? 'landing';
$flash = flash();

if (isset($_GET['admin_key']) && hash_equals((string)($config['marketing_admin_key'] ?? ''), (string)$_GET['admin_key'])) {
    $_SESSION['marketing_bypass'] = true;
}

if ($page === 'asaas-webhook') {
    process_asaas_webhook();
    exit;
}

if ($page === 'infinitepay-webhook') {
    process_infinitepay_webhook();
    exit;
}

if ($page === 'live-state') {
    render_live_state();
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    handle_post();
}

if (should_show_marketing_gate($page)) {
    render_marketing_gate();
    exit;
}

function handle_post(): void
{
    $pdo = db();
    $action = $_POST['action'] ?? '';

    if (function_exists('features_handle_action')) {
        features_handle_action($pdo, $action);
    }

    if ($action === 'register') {
        if (blocks_external_contact(implode(' ', array_map('strval', $_POST)))) {
            $_SESSION['flash'] = 'Remova links, telefone extra, e-mail ou tentativa de contato externo.';
            redirect('register');
        }
        $comprovante = store_upload('comprovante');
        if (!$comprovante) {
            $_SESSION['flash'] = 'Envie um comprovante para análise: print de rede social, cartão CNPJ, site ou documento da loja.';
            redirect('register');
        }
        try {
            $stmt = $pdo->prepare('INSERT INTO users (nome, sobrenome, cpf, cnpj, email, telefone, nome_loja, instagram_loja, cidade, estado, endereco_completo, comprovante, documento, password_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                trim((string)post('nome')),
                trim((string)post('sobrenome')),
                preg_replace('/\D+/', '', (string)post('cpf')),
                preg_replace('/\D+/', '', (string)post('cnpj')) ?: null,
                strtolower(trim((string)post('email'))),
                trim((string)post('telefone')),
                trim((string)post('nome_loja')),
                trim((string)post('instagram_loja')),
                trim((string)post('cidade')),
                strtoupper(trim((string)post('estado'))),
                trim((string)post('endereco_completo')),
                $comprovante,
                store_upload('documento'),
                password_hash((string)post('senha'), PASSWORD_DEFAULT),
            ]);
            $newUserId = (int)$pdo->lastInsertId();
            $admins = $pdo->query("SELECT id FROM users WHERE role='admin'")->fetchAll();
            foreach ($admins as $admin) {
                add_notification((int)$admin['id'], 'nova_solicitacao', 'Nova solicitacao de acesso', 'Um lojista solicitou acesso e aguarda aprovacao manual.');
            }
            $_SESSION['flash'] = 'Solicitação enviada. O admin master precisa aprovar seu acesso.';
            redirect('login');
        } catch (Throwable) {
            $_SESSION['flash'] = 'Não foi possível cadastrar. Verifique CPF/e-mail duplicado.';
            redirect('register');
        }
    }

    if ($action === 'login') {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([strtolower(trim((string)post('email')))]);
        $user = $stmt->fetch();
        if (!$user || !password_verify((string)post('senha'), $user['password_hash'])) {
            $_SESSION['flash'] = 'E-mail ou senha inválidos.';
            redirect('login');
        }
        if ($user['role'] !== 'admin' && $user['status_conta'] !== 'aprovado') {
            $_SESSION['flash'] = 'Sua conta ainda está em análise. Você será notificado após aprovação.';
            redirect('login');
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        redirect($user['role'] === 'admin' ? 'admin' : 'dashboard');
    }

    if ($action === 'forgot_password') {
        $email = strtolower(trim((string)post('email')));
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email=? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user) {
            send_password_reset_email($user);
            log_system((int)$user['id'], 'recuperacao_senha_solicitada', 'usuario', (int)$user['id'], 'Recuperacao de senha solicitada', 'Um link seguro de recuperacao foi enviado para o e-mail cadastrado.');
        }
        $_SESSION['flash'] = 'Se o e-mail estiver cadastrado, enviaremos um link seguro para redefinir a senha.';
        redirect('forgot-password');
    }

    if ($action === 'reset_password') {
        $token = (string)post('token');
        $password = (string)post('senha');
        if (strlen($password) < 8) {
            $_SESSION['flash'] = 'Use uma senha com pelo menos 8 caracteres.';
            redirect('reset-password', ['token' => $token]);
        }
        $hash = hash('sha256', $token);
        $stmt = $pdo->prepare('SELECT pr.*, u.email FROM password_reset_tokens pr JOIN users u ON u.id=pr.user_id WHERE pr.token_hash=? AND pr.used_at IS NULL AND pr.expires_at > NOW() LIMIT 1');
        $stmt->execute([$hash]);
        $reset = $stmt->fetch();
        if (!$reset) {
            $_SESSION['flash'] = 'Link expirado ou invalido. Solicite uma nova recuperacao.';
            redirect('forgot-password');
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), (int)$reset['user_id']]);
            $pdo->prepare('UPDATE password_reset_tokens SET used_at=NOW() WHERE id=?')->execute([(int)$reset['id']]);
            $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id=? AND used_at IS NULL')->execute([(int)$reset['user_id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        log_system((int)$reset['user_id'], 'senha_redefinida', 'usuario', (int)$reset['user_id'], 'Senha redefinida', 'Senha alterada por link seguro de recuperacao.');
        send_transactional_email((int)$reset['user_id'], (string)$reset['email'], 'Senha alterada na LOJIST', '<h1 style="margin:0 0 14px">Senha alterada</h1><p>Sua senha foi redefinida com sucesso. Se nao foi voce, contate o admin da LOJIST imediatamente.</p>', 'senha_redefinida');
        $_SESSION['flash'] = 'Senha redefinida. Entre com sua nova senha.';
        redirect('login');
    }

    if ($action === 'logout') {
        session_destroy();
        redirect('landing');
    }

    if ($action === 'update_profile') {
        $user = require_login();
        $pdo->prepare('UPDATE users SET endereco_completo=?, entrega_nome=?, entrega_telefone=?, entrega_cep=?, entrega_endereco=?, entrega_cidade=?, entrega_estado=?, entrega_complemento=?, retirada_instrucao=? WHERE id=?')->execute([
            trim((string)post('endereco_completo')),
            trim((string)post('entrega_nome')),
            trim((string)post('entrega_telefone')),
            trim((string)post('entrega_cep')),
            trim((string)post('entrega_endereco')),
            trim((string)post('entrega_cidade')),
            strtoupper(trim((string)post('entrega_estado'))),
            trim((string)post('entrega_complemento')),
            trim((string)post('retirada_instrucao')),
            (int)$user['id'],
        ]);
        log_system((int)$user['id'], 'perfil_atualizado', 'usuario', (int)$user['id'], 'Perfil atualizado', 'Dados comerciais, envio e recebimento foram atualizados pelo lojista.');
        $_SESSION['flash'] = 'Perfil e dados de entrega atualizados.';
        redirect('profile');
    }

    if ($action === 'create_product') {
        $user = require_login();
        if (empty($_POST['confirm_profile'])) {
            $_SESSION['flash'] = 'Confirme que seus dados de retirada/envio estão atualizados antes de vender.';
            redirect('new-product');
        }
        $plan = plan_for($user['plano']);
        if ($plan['limite_anuncios'] !== null && active_products_count((int)$user['id']) >= (int)$plan['limite_anuncios']) {
            $_SESSION['flash'] = 'Limite de anúncios ativos atingido para seu plano.';
            redirect('plans');
        }
        $details = implode(', ', $_POST['detalhes'] ?? []);
        $short = trim((string)post('detalhes_curto'));
        if ($short !== '') {
            if (blocks_external_contact($short)) {
                $_SESSION['flash'] = 'Detalhes não podem conter contato externo, loja, links ou telefone.';
                redirect('new-product');
            }
            $details .= ($details ? ', ' : '') . safe_substr($short, 0, 120);
        }
        $photos = store_uploads('fotos');
        if (!$photos) {
            $_SESSION['flash'] = 'Envie pelo menos uma foto válida.';
            redirect('new-product');
        }
        $serial = trim((string)post('serial_number', (string)post('imei_interno')));
        if (post('tipo') === 'seminovo' && $serial === '') {
            $_SESSION['flash'] = 'Serial Number interno é obrigatório para seminovos.';
            redirect('new-product');
        }
        if (duplicate_product_exists((int)$user['id'], (string)post('modelo'), (string)post('armazenamento'), (string)post('cor'), $serial)) {
            $warnings = register_duplicate_warning((int)$user['id'], trim((string)post('modelo')) ?: 'aparelho');
            $_SESSION['flash'] = $warnings >= 3
                ? 'Anúncio duplicado bloqueado. Sua conta foi suspensa para análise do admin.'
                : 'Anúncio duplicado detectado e bloqueado. Aviso ' . $warnings . ' de 3.';
            redirect($warnings >= 3 ? 'login' : 'new-product');
        }
        $stmt = $pdo->prepare('INSERT INTO products (vendedor_id, categoria, marca, modelo, armazenamento, cor, tipo, preco, custo_privado, quantidade, estado_geral, bateria, face_id, true_tone, icloud_livre, defeito, peca_trocada, detalhes_estruturados, imei_interno, serial_number, fotos, aceita_oferta, venda_expressa, metodos_entrega, cidade, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $user['id'], post('categoria'), post('marca'), post('modelo'), post('armazenamento'), post('cor'), post('tipo'),
            (float)post('preco'), post('custo_privado') !== '' ? (float)post('custo_privado') : null, max(1, (int)post('quantidade', 1)),
            post('estado_geral'), post('bateria'), post('face_id'), post('true_tone'), post('icloud_livre'), post('defeito'), post('peca_trocada'),
            $details, $serial, $serial, implode(',', $photos), isset($_POST['aceita_oferta']) ? 1 : 0, isset($_POST['venda_expressa']) ? 1 : 0,
            implode(', ', $_POST['metodos_entrega'] ?? ['Retirada local']), $user['cidade'], $user['estado']
        ]);
        $newProductId = (int)$pdo->lastInsertId();
        check_price_alerts($pdo, post('modelo'), (float)post('preco'), $newProductId);
        add_notification((int)$user['id'], 'anuncio_criado', 'Anúncio criado', 'Seu aparelho foi publicado com privacidade e watermark.');
        redirect('my-products');
    }

    if ($action === 'toggle_product' || $action === 'pause_all') {
        $user = require_login();
        if ($action === 'pause_all') {
            $pdo->prepare("UPDATE products SET status='pausado' WHERE vendedor_id=? AND status='disponivel'")->execute([$user['id']]);
            $_SESSION['flash'] = 'Todos os anúncios ativos foram pausados.';
        } else {
            $stmt = $pdo->prepare('SELECT status FROM products WHERE id=? AND vendedor_id=?');
            $stmt->execute([(int)post('id'), $user['id']]);
            $status = $stmt->fetchColumn();
            if ($status) {
                $pdo->prepare('UPDATE products SET status=? WHERE id=?')->execute([$status === 'pausado' ? 'disponivel' : 'pausado', (int)post('id')]);
            }
        }
        redirect('my-products');
    }

    if ($action === 'update_product') {
        $user = require_login();
        $stmt = $pdo->prepare('SELECT * FROM products WHERE id=? AND vendedor_id=?');
        $stmt->execute([(int)post('id'), $user['id']]);
        $product = $stmt->fetch();
        if (!$product) {
            $_SESSION['flash'] = 'Anúncio não encontrado.';
            redirect('my-products');
        }
        $details = implode(', ', $_POST['detalhes'] ?? []);
        $short = trim((string)post('detalhes_curto'));
        if ($short !== '') {
            if (blocks_external_contact($short)) {
                $_SESSION['flash'] = 'Detalhes não podem conter contato externo, loja, links ou telefone.';
                redirect('edit-product', ['id' => (int)$product['id']]);
            }
            $details .= ($details ? ', ' : '') . safe_substr($short, 0, 120);
        }
        $photos = store_uploads('fotos');
        $photoList = $photos ? implode(',', $photos) : $product['fotos'];
        $serial = trim((string)post('serial_number', (string)post('imei_interno')));
        if (duplicate_product_exists((int)$user['id'], (string)post('modelo'), (string)post('armazenamento'), (string)post('cor'), $serial, (int)$product['id'])) {
            $warnings = register_duplicate_warning((int)$user['id'], trim((string)post('modelo')) ?: (string)$product['modelo']);
            $_SESSION['flash'] = $warnings >= 3
                ? 'Edição bloqueada por duplicidade. Sua conta foi suspensa para análise do admin.'
                : 'Duplicidade detectada. A edição foi bloqueada e o aviso ' . $warnings . ' de 3 foi registrado.';
            redirect($warnings >= 3 ? 'login' : 'edit-product', ['id' => (int)$product['id']]);
        }
        $stmt = $pdo->prepare('UPDATE products SET categoria=?, marca=?, modelo=?, armazenamento=?, cor=?, tipo=?, preco=?, custo_privado=?, quantidade=?, estado_geral=?, bateria=?, face_id=?, true_tone=?, icloud_livre=?, defeito=?, peca_trocada=?, detalhes_estruturados=?, imei_interno=?, serial_number=?, fotos=?, aceita_oferta=?, venda_expressa=?, metodos_entrega=?, preco_ajustado_recentemente=1 WHERE id=? AND vendedor_id=?');
        $stmt->execute([
            post('categoria'), post('marca'), post('modelo'), post('armazenamento'), post('cor'), post('tipo'),
            (float)post('preco'), post('custo_privado') !== '' ? (float)post('custo_privado') : null, max(1, (int)post('quantidade', 1)),
            post('estado_geral'), post('bateria'), post('face_id'), post('true_tone'), post('icloud_livre'), post('defeito'), post('peca_trocada'),
            $details, $serial, $serial, $photoList, isset($_POST['aceita_oferta']) ? 1 : 0, isset($_POST['venda_expressa']) ? 1 : 0,
            implode(', ', $_POST['metodos_entrega'] ?? ['Retirada local']), (int)$product['id'], (int)$user['id']
        ]);
        check_price_alerts($pdo, post('modelo'), (float)post('preco'), (int)$product['id']);
        $_SESSION['flash'] = 'Anúncio atualizado com sucesso.';
        redirect('my-products');
    }

    if ($action === 'delete_product') {
        $user = require_login();
        $stmt = $pdo->prepare('SELECT id, modelo FROM products WHERE id=? AND vendedor_id=?');
        $stmt->execute([(int)post('id'), $user['id']]);
        $product = $stmt->fetch();
        if ($product) {
            try {
                $pdo->prepare('DELETE FROM products WHERE id=? AND vendedor_id=?')->execute([(int)$product['id'], (int)$user['id']]);
            } catch (Throwable) {
                $pdo->prepare("UPDATE products SET status='cancelado' WHERE id=? AND vendedor_id=?")->execute([(int)$product['id'], (int)$user['id']]);
            }
            $_SESSION['flash'] = 'Anúncio excluído ou removido do feed.';
        }
        redirect('my-products');
    }

    if ($action === 'buy_now') {
        $user = require_login();
        if (empty($_POST['confirm_delivery'])) {
            $_SESSION['flash'] = 'Confirme os dados de entrega antes de gerar o Pix.';
            redirect('product', ['id' => (int)post('produto_id')]);
        }
        if (!gateway_is_configured()) {
            $_SESSION['flash'] = 'Configure o gateway de pagamento (' . gateway_name() . ') antes de gerar Pix real.';
            redirect('product', ['id' => (int)post('produto_id')]);
        }
        $product = null;
        $orderId = 0;
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT p.*, u.plano, u.asaas_wallet_id FROM products p JOIN users u ON u.id=p.vendedor_id WHERE p.id=? FOR UPDATE');
            $stmt->execute([(int)post('produto_id')]);
            $product = $stmt->fetch();
            if (!$product || $product['status'] !== 'disponivel' || (int)$product['vendedor_id'] === (int)$user['id']) {
                $pdo->rollBack();
                $_SESSION['flash'] = 'Anuncio indisponivel. Outro lojista pode ter reservado ou comprado agora.';
                redirect('feed');
            }
            if (active_payment_provider() === 'asaas' && empty($product['asaas_wallet_id'])) {
                $pdo->rollBack();
                $_SESSION['flash'] = 'O vendedor ainda nao possui walletId do Asaas configurado para receber split automatico.';
                redirect('product', ['id' => (int)$product['id']]);
            }
            [$fee, $net] = calculate_fee((float)$product['preco'], $product['plano']);
            $stmt = $pdo->prepare('INSERT INTO orders (produto_id, comprador_id, vendedor_id, valor_bruto, taxa_plataforma, valor_liquido, metodo_entrega, pix_qrcode, destinatario, telefone_entrega, cep_entrega, endereco_entrega, cidade_entrega, estado_entrega, complemento_entrega) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $product['id'], $user['id'], $product['vendedor_id'], $product['preco'], $fee, $net, post('metodo_entrega', 'Retirada local'), '',
                trim((string)post('destinatario')), trim((string)post('telefone_entrega')), trim((string)post('cep_entrega')),
                trim((string)post('endereco_entrega')), trim((string)post('cidade_entrega')), strtoupper(trim((string)post('estado_entrega'))), trim((string)post('complemento_entrega'))
            ]);
            $orderId = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO payments (order_id, gateway, valor, taxa_plataforma, pix_qrcode) VALUES (?, ?, ?, ?, "")')->execute([$orderId, gateway_name(), $product['preco'], $fee]);
            $pdo->prepare("UPDATE products SET status='aguardando_pagamento' WHERE id=? AND status='disponivel'")->execute([$product['id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        try {
            create_gateway_order_payment($orderId);
            add_order_event($orderId, 'aguardando_pagamento', 'Pix gerado', 'Compra criada no gateway ' . gateway_name() . '. O aparelho ficou aguardando pagamento Pix.', (int)$user['id']);
            add_notification((int)$product['vendedor_id'], 'pix_gerado', 'Pix gerado', 'Um comprador gerou Pix para seu anúncio pelo gateway ' . gateway_name() . '.');
        } catch (Throwable $e) {
            $pdo->prepare("UPDATE orders SET status='cancelado', pix_status='cancelado', motivo_cancelamento=? WHERE id=?")->execute([$e->getMessage(), $orderId]);
            $pdo->prepare("UPDATE payments SET status='cancelado', webhook_data=? WHERE order_id=?")->execute([json_encode(['erro' => $e->getMessage()], JSON_UNESCAPED_UNICODE), $orderId]);
            $pdo->prepare("UPDATE products SET status='disponivel' WHERE id=? AND status='aguardando_pagamento'")->execute([(int)$product['id']]);
            $_SESSION['flash'] = 'Nao foi possivel gerar o Pix no gateway ' . gateway_name() . ': ' . $e->getMessage();
            redirect('product', ['id' => (int)$product['id']]);
        }
        redirect('checkout', ['id' => $orderId]);
    }

    if ($action === 'confirm_payment') {
        $user = require_login();
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id=? AND comprador_id=?');
        $stmt->execute([(int)post('order_id'), $user['id']]);
        $order = $stmt->fetch();
        if ($order) {
            $pdo->prepare("UPDATE orders SET status='pagamento_aprovado', pix_status='aprovado', pagamento_aprovado_em=NOW() WHERE id=?")->execute([$order['id']]);
            $pdo->prepare("UPDATE payments SET status='aprovado', aprovado_em=NOW(), webhook_data=? WHERE order_id=?")->execute([json_encode(['evento' => 'pix_aprovado_simulado']), $order['id']]);
            $pdo->prepare("UPDATE products SET status='pagamento_aprovado' WHERE id=?")->execute([$order['produto_id']]);
            add_order_event((int)$order['id'], 'pagamento_aprovado', 'Pagamento aprovado', 'Pix confirmado. Dados completos de entrega liberados ao vendedor.', (int)$user['id']);
            add_notification((int)$order['vendedor_id'], 'pagamento_aprovado', 'Pagamento aprovado', 'Dados necessários para entrega foram liberados.');
        }
        redirect('purchases');
    }

    if ($action === 'order_status') {
        $user = require_login();
        $allowed = ['preparando_envio', 'enviado', 'entregue', 'finalizado', 'cancelado'];
        $status = (string)post('status');
        if (in_array($status, $allowed, true)) {
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE id=? AND (vendedor_id=? OR comprador_id=?)');
            $stmt->execute([(int)post('order_id'), $user['id'], $user['id']]);
            $order = $stmt->fetch();
            if ($order) {
                $field = ['enviado' => 'enviado_em', 'entregue' => 'entregue_em', 'finalizado' => 'finalizado_em', 'cancelado' => 'cancelado_em'][$status] ?? null;
                $sql = "UPDATE orders SET status=?";
                if ($field) { $sql .= ", $field=NOW()"; }
                if ($status === 'finalizado') {
                    $repasse = date('Y-m-d H:i:s', strtotime('+2 hours'));
                    $sql .= ", repasse_liberado_em='{$repasse}', repasse_status='retido'";
                }
                if ($status === 'enviado' && trim((string)post('codigo_rastreio')) !== '') {
                    $sql .= ", codigo_rastreio=" . $pdo->quote(trim((string)post('codigo_rastreio')));
                }
                $sql .= ' WHERE id=?';
                $pdo->prepare($sql)->execute([$status, $order['id']]);
                $pdo->prepare('UPDATE products SET status=? WHERE id=?')->execute([$status, $order['produto_id']]);
                $events = [
                    'preparando_envio' => ['Pedido em preparo', 'O vendedor marcou o aparelho como em preparo para envio ou retirada.'],
                    'enviado' => ['Pedido enviado', 'O vendedor marcou o aparelho como enviado.'],
                    'entregue' => ['Pedido entregue', 'O aparelho foi marcado como entregue ao destinatário.'],
                    'finalizado' => ['Venda finalizada', 'A venda foi concluída e liberada para avaliação.'],
                    'cancelado' => ['Venda cancelada', 'A venda foi cancelada e impactou o score conforme regra da plataforma.'],
                ];
                add_order_event((int)$order['id'], $status, $events[$status][0], $events[$status][1], (int)$user['id']);
                $targetUser = (int)$user['id'] === (int)$order['vendedor_id'] ? (int)$order['comprador_id'] : (int)$order['vendedor_id'];
                add_notification($targetUser, 'pedido_' . $status, $events[$status][0], $events[$status][1] . ' Pedido #' . (int)$order['id'] . '.');
                if ($status === 'cancelado') {
                    $pdo->prepare("UPDATE products SET status='cancelado' WHERE id=?")->execute([$order['produto_id']]);
                    $pdo->prepare('UPDATE users SET taxa_cancelamento = LEAST(100, taxa_cancelamento + 1.5), score_vendedor = GREATEST(1, score_vendedor - 0.2) WHERE id=?')->execute([$order['vendedor_id']]);
                }
                refresh_user_reputation((int)$order['comprador_id']);
                refresh_user_reputation((int)$order['vendedor_id']);
            }
        }
        redirect('sales');
    }

    if ($action === 'offer') {
        $user = require_login();
        $stmt = $pdo->prepare('SELECT * FROM products WHERE id=? AND aceita_oferta=1 AND status="disponivel"');
        $stmt->execute([(int)post('produto_id')]);
        $product = $stmt->fetch();
        if ($product && (int)$product['vendedor_id'] !== (int)$user['id']) {
            $cooldown = $pdo->prepare('SELECT COUNT(*) FROM offers WHERE produto_id=? AND comprador_id=? AND cooldown_ate > NOW()');
            $cooldown->execute([$product['id'], $user['id']]);
            if ((int)$cooldown->fetchColumn() === 0) {
                $cooldown = date('Y-m-d H:i:s', strtotime('+30 minutes'));
                $pdo->prepare('INSERT INTO offers (produto_id, comprador_id, vendedor_id, valor_oferta, cooldown_ate) VALUES (?, ?, ?, ?, ?)')->execute([$product['id'], $user['id'], $product['vendedor_id'], (float)post('valor_oferta'), $cooldown]);
                add_notification((int)$product['vendedor_id'], 'oferta_recebida', 'Oferta recebida', 'Um lojista enviou oferta com score visível.');
            }
        }
        redirect('offers');
    }

    if ($action === 'offer_status') {
        $user = require_login();
        $status = (string)post('status');
        if (in_array($status, ['aceita', 'recusada'], true)) {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('SELECT o.*, p.status produto_status FROM offers o JOIN products p ON p.id=o.produto_id WHERE o.id=? AND o.vendedor_id=? FOR UPDATE');
                $stmt->execute([(int)post('offer_id'), $user['id']]);
                $offer = $stmt->fetch();
                if ($offer && $offer['status'] === 'pendente') {
                    if ($status === 'aceita' && $offer['produto_status'] !== 'disponivel') {
                        $pdo->prepare("UPDATE offers SET status='expirada' WHERE id=?")->execute([(int)$offer['id']]);
                        $pdo->commit();
                        $_SESSION['flash'] = 'Esta oferta nao pode ser aceita porque o produto nao esta mais disponivel.';
                        redirect('offers');
                    }
                    $expiraEm = date('Y-m-d H:i:s', strtotime('+30 minutes'));
                    $pdo->prepare("UPDATE offers SET status=?, aceita_em = CASE WHEN ?='aceita' THEN NOW() ELSE aceita_em END, expira_em = CASE WHEN ?='aceita' THEN ? ELSE expira_em END WHERE id=?")->execute([$status, $status, $status, $expiraEm, $offer['id']]);
                    if ($status === 'aceita') {
                        $pdo->prepare("UPDATE products SET status='reservado' WHERE id=? AND status='disponivel'")->execute([$offer['produto_id']]);
                    }
                    $pdo->commit();
                    add_notification((int)$offer['comprador_id'], 'oferta_' . $status, 'Oferta ' . $status, $status === 'aceita' ? 'Sua oferta foi aceita. Pague em ate 30 minutos.' : 'Sua oferta foi recusada.');
                } else {
                    $pdo->commit();
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                throw $e;
            }
        }
        redirect('offers');
    }

    if ($action === 'pay_offer') {
        $user = require_login();
        if (empty($_POST['confirm_delivery'])) {
            $_SESSION['flash'] = 'Confirme os dados de entrega antes de gerar o Pix da oferta.';
            redirect('offers');
        }
        if (!gateway_is_configured()) {
            $_SESSION['flash'] = 'Configure o gateway de pagamento (' . gateway_name() . ') antes de gerar Pix real.';
            redirect('offers');
        }
        $offer = null;
        $orderId = 0;
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT o.*, p.status produto_status, p.modelo, vendedor.plano, vendedor.asaas_wallet_id FROM offers o JOIN products p ON p.id=o.produto_id JOIN users vendedor ON vendedor.id=o.vendedor_id WHERE o.id=? AND o.comprador_id=? FOR UPDATE');
            $stmt->execute([(int)post('offer_id'), $user['id']]);
            $offer = $stmt->fetch();
            if (!$offer || $offer['status'] !== 'aceita' || strtotime((string)$offer['expira_em']) <= time() || !in_array($offer['produto_status'], ['reservado', 'disponivel'], true)) {
                if ($offer && $offer['status'] === 'aceita') {
                    $pdo->prepare("UPDATE offers SET status='expirada' WHERE id=?")->execute([(int)$offer['id']]);
                    $pdo->prepare("UPDATE products SET status='disponivel' WHERE id=? AND status='reservado'")->execute([(int)$offer['produto_id']]);
                }
                $pdo->commit();
                $_SESSION['flash'] = 'Oferta expirada ou indisponivel.';
                redirect('offers');
            }
            if (active_payment_provider() === 'asaas' && empty($offer['asaas_wallet_id'])) {
                $pdo->rollBack();
                $_SESSION['flash'] = 'O vendedor ainda nao possui walletId do Asaas configurado para receber split automatico.';
                redirect('offers');
            }
            [$fee, $net] = calculate_fee((float)$offer['valor_oferta'], $offer['plano']);
            $stmt = $pdo->prepare('INSERT INTO orders (produto_id, comprador_id, vendedor_id, valor_bruto, taxa_plataforma, valor_liquido, metodo_entrega, pix_qrcode, destinatario, telefone_entrega, cep_entrega, endereco_entrega, cidade_entrega, estado_entrega, complemento_entrega) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $offer['produto_id'], $user['id'], $offer['vendedor_id'], $offer['valor_oferta'], $fee, $net, post('metodo_entrega', 'Retirada local'), '',
                trim((string)post('destinatario')), trim((string)post('telefone_entrega')), trim((string)post('cep_entrega')),
                trim((string)post('endereco_entrega')), trim((string)post('cidade_entrega')), strtoupper(trim((string)post('estado_entrega'))), trim((string)post('complemento_entrega'))
            ]);
            $orderId = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO payments (order_id, gateway, valor, taxa_plataforma, pix_qrcode) VALUES (?, ?, ?, ?, "")')->execute([$orderId, gateway_name(), $offer['valor_oferta'], $fee]);
            $pdo->prepare("UPDATE offers SET status='paga', paga_em=NOW() WHERE id=?")->execute([$offer['id']]);
            $pdo->prepare("UPDATE products SET status='aguardando_pagamento' WHERE id=?")->execute([$offer['produto_id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        try {
            create_gateway_order_payment($orderId);
            add_order_event($orderId, 'aguardando_pagamento', 'Pix gerado pela oferta', 'Oferta aceita e convertida em pedido aguardando pagamento Pix pelo gateway ' . gateway_name() . '.', (int)$user['id']);
            add_notification((int)$offer['vendedor_id'], 'pix_gerado', 'Pix gerado por oferta aceita', 'O comprador gerou Pix para uma oferta aceita.');
        } catch (Throwable $e) {
            $pdo->prepare("UPDATE orders SET status='cancelado', pix_status='cancelado', motivo_cancelamento=? WHERE id=?")->execute([$e->getMessage(), $orderId]);
            $pdo->prepare("UPDATE payments SET status='cancelado', webhook_data=? WHERE order_id=?")->execute([json_encode(['erro' => $e->getMessage()], JSON_UNESCAPED_UNICODE), $orderId]);
            $pdo->prepare("UPDATE offers SET status='aceita', paga_em=NULL WHERE id=? AND status='paga'")->execute([(int)$offer['id']]);
            $pdo->prepare("UPDATE products SET status='reservado' WHERE id=? AND status='aguardando_pagamento'")->execute([(int)$offer['produto_id']]);
            $_SESSION['flash'] = 'Nao foi possivel gerar o Pix no gateway ' . gateway_name() . ': ' . $e->getMessage();
            redirect('offers');
        }
        refresh_user_reputation((int)$user['id']);
        refresh_user_reputation((int)$offer['vendedor_id']);
        redirect('checkout', ['id' => $orderId]);
    }

    if ($action === 'review_order') {
        $user = require_login();
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id=? AND (comprador_id=? OR vendedor_id=?) AND status IN ("entregue","finalizado")');
        $stmt->execute([(int)post('order_id'), $user['id'], $user['id']]);
        $order = $stmt->fetch();
        if ($order) {
            $reviewed = (int)$order['comprador_id'] === (int)$user['id'] ? (int)$order['vendedor_id'] : (int)$order['comprador_id'];
            $criteria = implode(', ', $_POST['criterios'] ?? []);
            $note = max(1, min(5, (int)post('nota', 5)));
            $pdo->prepare('INSERT INTO reviews (order_id, avaliador_id, avaliado_id, nota, criterios) VALUES (?, ?, ?, ?, ?)')->execute([$order['id'], $user['id'], $reviewed, $note, $criteria]);
            $pdo->prepare('UPDATE users SET nota_geral = (SELECT AVG(nota) FROM reviews WHERE avaliado_id = ?) WHERE id = ?')->execute([$reviewed, $reviewed]);
            refresh_user_reputation($reviewed);
            refresh_user_reputation((int)$user['id']);
            add_notification($reviewed, 'avaliacao_recebida', 'Avaliação recebida', 'Você recebeu uma avaliação estruturada em uma venda finalizada.');
        }
        redirect('purchases');
    }

    if ($action === 'pay_plan') {
        $user = require_login();
        $planIdPost = (int)post('plan_id', 0);
        if ($planIdPost > 0) {
            $stmt = $pdo->prepare('SELECT * FROM plans WHERE id=? AND ativo=1');
            $stmt->execute([$planIdPost]);
            $plan = $stmt->fetch() ?: plan_for((string)$user['plano']);
        } else {
            $plan = plan_for((string)$user['plano']);
        }
        $state = subscription_state($user);
        $base = !empty($state['ends_at']) && $state['active'] ? (string)$state['ends_at'] : date('Y-m-d H:i:s');
        $start = new DateTimeImmutable($base);
        $end = $start->modify('+1 month');
        $planId = (int)($plan['id'] ?? 1);
        if ((float)$plan['preco_mensal'] <= 0) {
            $pdo->prepare("UPDATE users SET plano=?, subscription_status='active', paid_until=? WHERE id=?")->execute([(string)$plan['nome'], $end->format('Y-m-d H:i:s'), (int)$user['id']]);
            add_notification((int)$user['id'], 'plano_ativado', 'Plano Free ativado', 'Seu plano Free foi ativado para continuar testando a plataforma.');
            $_SESSION['flash'] = 'Plano Free ativado por 1 mês.';
            redirect('plans');
        }
        if (!gateway_is_configured()) {
            $_SESSION['flash'] = 'Configure o gateway de pagamento (' . gateway_name() . ') antes de cobrar o plano.';
            redirect('plans');
        }
        $pdo->prepare('INSERT INTO plan_payments (user_id, plan_id, valor, status, pix_qrcode, periodo_inicio, periodo_fim) VALUES (?, ?, ?, "pendente", "", ?, ?)')->execute([(int)$user['id'], $planId, (float)$plan['preco_mensal'], $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')]);
        $planPaymentId = (int)$pdo->lastInsertId();
        try {
            create_gateway_plan_payment($planPaymentId);
        } catch (Throwable $e) {
            $pdo->prepare("UPDATE plan_payments SET status='cancelado', webhook_data=? WHERE id=?")->execute([json_encode(['erro' => $e->getMessage()], JSON_UNESCAPED_UNICODE), $planPaymentId]);
            $_SESSION['flash'] = 'Nao foi possivel gerar o Pix do plano no gateway ' . gateway_name() . ': ' . $e->getMessage();
            redirect('plans');
        }
        add_notification((int)$user['id'], 'plano_pix_gerado', 'Pix do plano gerado', 'Pague o Pix para ativar ou renovar seu acesso. O webhook do gateway liberara automaticamente apos confirmacao.');
        redirect('plan-checkout', ['id' => $planPaymentId]);
    }

    if ($action === 'admin_user_status') {
        require_admin();
        $status = (string)post('status');
        if ($status === 'aprovado') {
            $trial = date('Y-m-d H:i:s', strtotime('+1 month'));
            $pdo->prepare("UPDATE users SET status_conta='aprovado', aprovado_em=COALESCE(aprovado_em, NOW()), trial_ends_at=COALESCE(trial_ends_at, ?), subscription_status = CASE WHEN paid_until IS NOT NULL AND paid_until >= NOW() THEN 'active' ELSE 'trialing' END, plano=COALESCE(NULLIF(plano,''),'Free') WHERE id=?")->execute([$trial, (int)post('user_id')]);
            add_notification((int)post('user_id'), 'cadastro_aprovado', 'Cadastro aprovado', 'Seu acesso foi liberado com 1 mês de teste grátis na LOJIST.');
        } else {
            $pdo->prepare("UPDATE users SET status_conta=?, aprovado_em = CASE WHEN ?='aprovado' THEN NOW() ELSE aprovado_em END WHERE id=?")->execute([$status, $status, (int)post('user_id')]);
            $map = [
                'recusado' => ['cadastro_recusado', 'Cadastro recusado', 'Sua solicitacao foi recusada pelo admin master.'],
                'suspenso' => ['conta_suspensa', 'Conta suspensa', 'Sua conta foi suspensa para analise do admin master.'],
                'banido' => ['conta_suspensa', 'Conta banida', 'Sua conta foi banida da plataforma.'],
            ];
            if (isset($map[$status])) {
                add_notification((int)post('user_id'), $map[$status][0], $map[$status][1], $map[$status][2]);
            }
        }
        if ($status === 'aprovado') {
            redirect('admin', ['tab' => 'users']);
        } else {
            redirect('admin', ['tab' => 'approvals']);
        }
    }

    if ($action === 'admin_user_wallet') {
        require_admin();
        $pdo->prepare('UPDATE users SET asaas_wallet_id=? WHERE id=? AND role="lojista"')->execute([trim((string)post('asaas_wallet_id')), (int)post('user_id')]);
        $_SESSION['flash'] = 'WalletId do Asaas atualizado para o lojista.';
        redirect('admin', ['tab' => 'users']);
    }

    if ($action === 'admin_plan') {
        require_admin();
        foreach ($_POST['plans'] ?? [] as $id => $plan) {
            $limit = trim((string)$plan['limite_anuncios']) === '' ? null : (int)$plan['limite_anuncios'];
            db()->prepare('UPDATE plans SET taxa=?, limite_anuncios=?, filtros_avancados=?, preco_mensal=?, ativo=? WHERE id=?')->execute([(float)$plan['taxa'], $limit, isset($plan['filtros_avancados']) ? 1 : 0, (float)$plan['preco_mensal'], isset($plan['ativo']) ? 1 : 0, (int)$id]);
        }
        redirect('admin', ['tab' => 'plans']);
    }

    if ($action === 'admin_add_plan') {
        require_admin();
        $name = trim((string)post('nome'));
        if ($name !== '') {
            $limit = trim((string)post('limite_anuncios')) === '' ? null : (int)post('limite_anuncios');
            $pdo->prepare('INSERT INTO plans (nome, taxa, limite_anuncios, filtros_avancados, preco_mensal, especial, ativo) VALUES (?, ?, ?, ?, ?, 1, 1) ON DUPLICATE KEY UPDATE taxa=VALUES(taxa), limite_anuncios=VALUES(limite_anuncios), filtros_avancados=VALUES(filtros_avancados), preco_mensal=VALUES(preco_mensal), especial=1, ativo=1')->execute([
                $name,
                (float)post('taxa'),
                $limit,
                isset($_POST['filtros_avancados']) ? 1 : 0,
                (float)post('preco_mensal'),
            ]);
        }
        redirect('admin', ['tab' => 'plans']);
    }

    if ($action === 'admin_delete_user') {
        require_admin();
        $stmt = $pdo->prepare("SELECT id, status_conta FROM users WHERE id=? AND role='lojista'");
        $stmt->execute([(int)post('user_id')]);
        $u = $stmt->fetch();
        if ($u && $u['status_conta'] === 'banido') {
            $pdo->prepare('DELETE FROM users WHERE id=? AND role="lojista"')->execute([(int)$u['id']]);
            $_SESSION['flash'] = 'Cadastro banido excluído do sistema.';
        } else {
            $_SESSION['flash'] = 'Só é possível excluir cadastros banidos.';
        }
        redirect('admin', ['tab' => 'users']);
    }

    if ($action === 'admin_delete_product') {
        require_admin();
        error_log("admin_delete_product called with POST: " . print_r($_POST, true));
        try {
            $pdo->prepare('DELETE FROM products WHERE id=?')->execute([(int)post('product_id')]);
            $_SESSION['flash'] = 'Anúncio excluído pelo admin.';
        } catch (Throwable $e) {
            error_log("admin_delete_product Exception: " . $e->getMessage());
            $pdo->prepare("UPDATE products SET status='cancelado' WHERE id=?")->execute([(int)post('product_id')]);
            $_SESSION['flash'] = 'Anúncio vinculado a pedido foi cancelado pelo admin.';
        }
        redirect('admin', ['tab' => 'products']);
    }

    if ($action === 'admin_delete_order') {
        require_admin();
        $pdo->prepare("UPDATE products p JOIN orders o ON o.produto_id=p.id SET p.status='disponivel' WHERE o.id=? AND p.status IN ('reservado','aguardando_pagamento')")->execute([(int)post('order_id')]);
        $pdo->prepare('DELETE FROM orders WHERE id=?')->execute([(int)post('order_id')]);
        $_SESSION['flash'] = 'Venda/pedido excluído manualmente.';
        redirect('admin', ['tab' => 'sales']);
    }

    if ($action === 'admin_reset_platform') {
        $admin = require_admin();
        $confirmation = trim((string)post('confirmacao'));
        if ($confirmation !== 'LIMPAR LOJIST') {
            $_SESSION['flash'] = 'Confirmação inválida. Digite exatamente LIMPAR LOJIST para limpar o banco.';
            redirect('admin', ['tab' => 'settings']);
        }

        $pdo->beginTransaction();
        try {
            foreach ([
                'disputes',
                'reviews',
                'payments',
                'order_events',
                'offers',
                'orders',
                'price_history',
                'products',
                'notifications',
                'email_logs',
                'password_reset_tokens',
                'system_logs',
            ] as $table) {
                $pdo->exec("DELETE FROM `$table`");
            }
            $pdo->prepare("DELETE FROM users WHERE id <> ?")->execute([(int)$admin['id']]);
            $pdo->prepare("UPDATE users SET role='admin', status_conta='aprovado', plano='Elite', nivel='Diamond', nota_geral=5, vendas_concluidas=0, compras_concluidas=0, taxa_cancelamento=0, score_comprador=5, score_vendedor=5, reservas_expiradas=0, duplicate_warnings=0, trial_ends_at=NULL, paid_until=NULL, subscription_status='active' WHERE id=?")->execute([(int)$admin['id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $_SESSION['flash'] = 'Não foi possível limpar o banco: ' . $e->getMessage();
            redirect('admin', ['tab' => 'settings']);
        }

        log_system((int)$admin['id'], 'limpeza_banco', 'admin', (int)$admin['id'], 'Banco limpo pelo admin', 'Todos os lojistas, anúncios, pedidos, ofertas, pagamentos e logs foram removidos. Apenas o admin foi preservado.');
        add_notification((int)$admin['id'], 'limpeza_banco', 'Banco limpo', 'A base foi limpa e apenas o usuário admin foi preservado.');
        $_SESSION['flash'] = 'Banco limpo com sucesso. Apenas o admin foi preservado.';
        redirect('admin', ['tab' => 'settings']);
    }
}

function current_route(string $route): string
{
    $p = $_GET['p'] ?? '';
    $tab = $_GET['tab'] ?? '';
    $current = $p . ($tab ? '&tab=' . $tab : '');
    return $current === $route ? 'active' : '';
}

function render_header(string $title): void
{
    global $flash;
    global $page;
    $user = current_user();
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
    ?>
    <!doctype html>
    <html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($title) ?> | LOJIST</title>
        <script src="https://unpkg.com/@phosphor-icons/web"></script>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <link rel="stylesheet" href="assets/css/style.css?v=11">
    </head>
    <body class="<?= $user ? 'app-shell' : 'public-shell' ?>">
    <?php if ($flash && $page !== 'landing'): ?><div class="flash"><?= e($flash) ?></div><?php endif; ?>
    <?php
}

function render_footer(): void
{
    echo '<script src="assets/js/app.js?v=7"></script></body></html>';
}

function should_show_marketing_gate(string $page): bool
{
    $config = app_config();
    if (empty($config['marketing_lock_enabled'])) {
        return false;
    }
    if (!empty($_SESSION['marketing_bypass'])) {
        return false;
    }
    return !in_array($page, ['register', 'login', 'forgot-password', 'reset-password', 'asaas-webhook', 'infinitepay-webhook', 'live-state'], true);
}

function render_live_state(): void
{
    header('Content-Type: application/json; charset=utf-8');
    $user = current_user();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'auth' => false]);
        return;
    }
    $pdo = db();
    $ids = array_filter(array_map('intval', explode(',', (string)($_GET['products'] ?? ''))));
    $products = [];
    if ($ids) {
        $ids = array_slice(array_values(array_unique($ids)), 0, 80);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id, status FROM products WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $row) {
            $products[(int)$row['id']] = (string)$row['status'];
        }
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND lida=0');
    $stmt->execute([(int)$user['id']]);
    echo json_encode([
        'ok' => true,
        'server_time' => date('Y-m-d H:i:s'),
        'notifications' => (int)$stmt->fetchColumn(),
        'products' => $products,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function render_marketing_gate(): void
{
    global $flash;
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
    ?>
    <!doctype html>
    <html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Em breve | LOJIST</title>
        <meta name="description" content="LOJIST: em breve a plataforma privada para lojistas mobile comprarem, venderem e girarem estoque com segurança.">
        <link rel="stylesheet" href="assets/css/style.css?v=9">
    </head>
    <body class="marketing-shell">
        <?php if ($flash): ?><div class="flash"><?= e($flash) ?></div><?php endif; ?>
        <main class="coming-soon-page">
            <img class="coming-blur-logo" src="assets/img/logo.png?v=5" alt="">
            <div class="coming-grid-glow" aria-hidden="true"></div>
            <section class="coming-copy">
                <!-- <img class="coming-logo" src="assets/img/logo.png?v=5" alt="LOJIST"> -->
                <h1 style="max-width: 800px; font-weight: 900; font-size: 4.5rem; line-height: 1.1; color: #FFFFFF; letter-spacing: -1px;">O marketplace privado dos<br>lojistas mobile.</h1>
                <p style="color: #D1D5DB; font-size: 1.35rem; max-width: 600px; margin-top: 1.5rem; margin-bottom: 2rem; font-weight: 500;">Compre e venda celulares de repasse com segurança, Pix automático e lojistas verificados.</p>
                <div class="coming-actions" style="display: flex; gap: 1rem; align-items: center; margin-top: 2rem;">
                    <a class="coming-button" href="index.php?p=register" style="padding: 1rem 2.5rem; font-size: 1.15rem; min-width: auto; height: 56px;">Solicitar acesso</a>
                    <a class="coming-button secondary" href="index.php?p=login" style="padding: 1rem 2.5rem; font-size: 1.15rem; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: white; min-width: auto; height: 56px; box-shadow: none;">Conhecer a plataforma</a>
                </div>
            </section>
            <section class="coming-visual" aria-hidden="true">
                <div class="coming-orbit one"></div>
                <div class="coming-orbit two"></div>
                <div class="coming-phone-stage">
                    <img class="coming-phone-bg-logo" src="assets/img/logo.png?v=5" alt="">
                    <div class="iphone-float coming-phone">
                        <div class="iphone-screen coming-phone-screen">
                            <span class="phone-notch"></span>
                            <span class="dynamic-island"></span>
                            <img class="phone-logo coming-phone-logo" src="assets/img/logo.png?v=5" alt="">
                        </div>
                    </div>
                </div>
                <div class="coming-card-mini">
                    <strong>Pix imediato</strong>
                    <span>Dados protegidos ate a compra</span>
                </div>
            </section>
        </main>
    </body>
    </html>
    <?php
}

function logo(): string
{
    $logoPath = file_exists(__DIR__ . '/assets/img/logo.png') ? 'assets/img/logo.png' : 'assets/img/logo.svg';
    return '<a class="brand" href="index.php" aria-label="LOJIST"><img src="' . e($logoPath) . '?v=5" alt="LOJIST"><span>LOJIST</span></a>';
}

function app_nav(array $user): void
{
    $admin = $user['role'] === 'admin';
    $items = $admin
        ? ['admin' => 'Dashboard', 'admin&tab=users' => 'Lojistas', 'admin&tab=approvals' => 'Aprovações', 'admin&tab=products' => 'Anúncios', 'admin&tab=sales' => 'Vendas', 'admin&tab=disputes' => 'Disputas', 'admin&tab=logs' => 'Logs', 'admin&tab=reports' => 'Relatórios', 'admin&tab=plans' => 'Planos', 'admin&tab=settings' => 'Configurações']
        : ['dashboard' => 'Início', 'feed' => 'Buscar', 'new-product' => 'Anunciar', 'my-products' => 'Meus anúncios', 'market-research' => 'Pesquisa Mercado', 'inventory' => 'Estoque', 'price-alerts' => 'Alertas', 'offers' => 'Ofertas', 'sales' => 'Vendas', 'purchases' => 'Compras', 'finance' => 'Financeiro', 'notifications' => 'Notificações', 'referrals' => 'Indicações', 'profile' => 'Perfil', 'plans' => 'Plano'];
    
    $icons = [
        'Dashboard' => 'ph-squares-four', 'Lojistas' => 'ph-users', 'Aprovações' => 'ph-check-circle', 'Anúncios' => 'ph-device-mobile', 'Vendas' => 'ph-currency-dollar', 'Disputas' => 'ph-warning-circle', 'Logs' => 'ph-terminal-window', 'Relatórios' => 'ph-chart-line-up', 'Planos' => 'ph-star', 'Configurações' => 'ph-gear',
        'Início' => 'ph-house', 'Buscar' => 'ph-magnifying-glass', 'Anunciar' => 'ph-plus-circle', 'Meus anúncios' => 'ph-list-dashes', 'Pesquisa Mercado' => 'ph-trend-up', 'Estoque' => 'ph-package', 'Alertas' => 'ph-bell-ringing', 'Ofertas' => 'ph-handshake', 'Compras' => 'ph-shopping-bag', 'Financeiro' => 'ph-wallet', 'Notificações' => 'ph-bell', 'Indicações' => 'ph-users-three', 'Perfil' => 'ph-user', 'Plano' => 'ph-crown'
    ];

    echo '<aside class="sidebar">' . logo() . '<nav>';
    foreach ($items as $route => $label) {
        $icon = $icons[$label] ?? 'ph-circle';
        echo '<a class="' . current_route($route) . '" href="index.php?p=' . e($route) . '"><i class="ph ' . $icon . '" style="font-size: 1.25rem; margin-right: 12px; opacity: 0.8;"></i>' . e($label) . '</a>';
    }
    echo '</nav><form method="post"><input type="hidden" name="action" value="logout"><button class="ghost full" style="margin-top: auto;"><i class="ph ph-sign-out" style="font-size: 1.25rem; margin-right: 8px;"></i> Sair</button></form></aside><nav class="mobile-nav">';
    foreach (array_slice($items, 0, 5) as $route => $label) {
        $icon = $icons[$label] ?? 'ph-circle';
        echo '<a href="index.php?p=' . e($route) . '"><i class="ph ' . $icon . '" style="font-size: 1.5rem;"></i></a>';
    }
    echo '</nav>';
}

function layout_start(string $title): array
{
    $user = require_login();
    render_header($title);
    app_nav($user);
    echo '<main class="main">';
    if (function_exists('render_top_header')) {
        render_top_header($user);
    }
    return $user;
}

function layout_end(): void
{
    echo '</main>';
    render_footer();
}

function page_number(): int { return max(1, (int)($_GET['pagina'] ?? 1)); }
function page_size(): int { return 30; }
function page_offset(): int { return (page_number() - 1) * page_size(); }

function paged_query(PDO $pdo, string $selectSql, string $countSql, array $args = []): array
{
    $count = $pdo->prepare($countSql);
    $count->execute($args);
    $total = (int)$count->fetchColumn();
    $stmt = $pdo->prepare($selectSql . ' LIMIT ' . page_size() . ' OFFSET ' . page_offset());
    $stmt->execute($args);
    return [$stmt->fetchAll(), $total];
}

function pagination_links(int $total): void
{
    $pages = (int)ceil($total / page_size());
    if ($pages <= 1) { return; }
    echo '<nav class="pagination">';
    for ($i = 1; $i <= $pages; $i++) {
        $params = array_merge($_GET, ['pagina' => $i]);
        echo '<a class="' . ($i === page_number() ? 'active' : '') . '" href="index.php?' . e(http_build_query($params)) . '">' . $i . '</a>';
    }
    echo '</nav>';
}

function metric_card(string $label, string $value, string $meta = '', string $icon = 'ph-chart-bar'): void
{
    echo '<div class="metric animate-entry" style="padding: 24px; border: 1px solid rgba(255,255,255,0.04); background: rgba(255,255,255,0.015); backdrop-filter: blur(20px); border-radius: 20px; box-shadow: inset 0 1px 0 rgba(255,255,255,0.05); display: flex; flex-direction: column; justify-content: space-between;"><div class="metric-header" style="display: flex; align-items: center; gap: 12px; margin-bottom: 24px;"><span class="metric-icon" style="display: flex; align-items: center; justify-content: center; width: 44px; height: 44px; border-radius: 14px; background: rgba(255,255,255, 0.03); border: 1px solid rgba(255,255,255,0.04); color: #fafafa;"><i class="ph ' . $icon . '" style="font-size: 1.3rem;"></i></span><span style="color: #a1a1aa; font-size: 1rem; font-weight: 500;">' . e($label) . '</span></div><strong style="font-size: 2.2rem; display: block; letter-spacing: -1px; color: #ffffff; font-weight: 600;">' . e($value) . '</strong>' . ($meta ? '<small style="display: flex; align-items: center; color: var(--ok); margin-top: 16px; font-weight: 600; font-size: 0.85rem; background: rgba(16, 185, 129, 0.1); padding: 6px 10px; border-radius: 8px; width: fit-content;"><i class="ph ph-trend-up" style="margin-right: 6px; font-size: 1rem;"></i>' . e($meta) . '</small>' : '') . '</div>';
}

function product_card(array $product): void
{
    ?>
    <article class="product-card clickable-card" data-live-product-id="<?= (int)$product['id'] ?>" data-href="index.php?p=product&id=<?= (int)$product['id'] ?>" role="link" tabindex="0">
        <div class="photo protected-image">
            <img src="<?= e(product_image($product)) ?>" alt="<?= e($product['modelo']) ?>" draggable="false">
            <span class="watermark">LOJIST</span>
        </div>
        <div class="card-body">
            <?php
            $planColor = '#6b7280'; $planName = $product['plano'] ?? 'Free';
            if ($planName === 'Pro') $planColor = 'var(--blue)';
            if ($planName === 'Elite') $planColor = '#F59E0B';
            ?>
            <div class="row between">
                <div style="display:flex; gap:8px;">
                    <span class="chip"><?= e($product['categoria']) ?></span>
                    <span class="chip" style="background: <?= $planColor ?>; border-color: <?= $planColor ?>; color: white;"><i class="ph ph-crown" style="margin-right:4px;"></i> <?= e($planName) ?></span>
                </div>
                <?php if ($product['venda_expressa']): ?><span class="chip blue">Venda Expressa</span><?php endif; ?>
            </div>
            <h3><?= e($product['modelo']) ?></h3>
            <p><?= e($product['armazenamento']) ?> • <?= e($product['cor']) ?> • <?= e(ucfirst($product['tipo'])) ?></p>
            <strong class="price"><?= money((float)$product['preco']) ?></strong>
            <div class="seller-line"><span class="level <?= level_class($product['nivel']) ?>"><?= e($product['nivel']) ?></span><span>Nota <?= number_format((float)$product['nota_geral'], 1, ',', '.') ?></span><span><?= (int)$product['vendas_concluidas'] ?> vendas</span></div>
            <p class="muted"><?= e($product['cidade']) ?>/<?= e($product['estado']) ?> • Cancelamento <?= number_format((float)$product['taxa_cancelamento'], 1, ',', '.') ?>% • Envio <?= e($product['tempo_medio_envio']) ?></p>
        </div>
    </article>
    <?php
}

switch ($page) {
    case 'landing':
        render_header('Marketplace privado B2B');
        ?>
        <header class="landing-hero" style="background: #040D1A; min-height: 100vh; overflow: hidden; font-family: 'Inter', 'Montserrat', sans-serif;">
            <nav class="landing-nav" style="padding: 2.5rem 3rem; width: 100%; display: flex; justify-content: space-between; align-items: center; box-sizing: border-box;">
                <a href="index.php" style="display: flex; align-items: center; gap: 1rem; text-decoration: none; color: white;">
                    <img src="assets/img/logo.png?v=5" alt="LOJIST" style="height: 50px;">
                </a>
                <div style="display: flex; gap: 1rem; align-items: center;">
                    <a href="index.php?p=plans" style="color: #ffffff; text-decoration: none; font-weight: 500; font-size: 1.1rem; padding: 0.6rem 2rem; border: 1px solid #ffffff; border-radius: 9999px; transition: all 0.3s; background: transparent;">Planos</a>
                    <a href="index.php?p=about" style="color: #ffffff; text-decoration: none; font-weight: 500; font-size: 1.1rem; padding: 0.6rem 2rem; border: 1px solid #ffffff; border-radius: 9999px; transition: all 0.3s; background: transparent;">Sobre</a>
                    <a class="button small" href="index.php?p=login" style="background: transparent; border: 1px solid #0066ff; color: #ffffff; padding: 0.6rem 2rem; border-radius: 9999px; font-weight: 600; text-decoration: none; font-size: 1.1rem; transition: all 0.3s;">Acessar Plataforma</a>
                </div>
            </nav>
            <section style="display: flex; flex-direction: row; align-items: center; justify-content: space-between; width: 100%; min-height: calc(100vh - 120px); padding: 2rem 3rem; box-sizing: border-box;">
                <div style="flex: 0 0 55%; color: white; text-align: left;">
                    <h1 style="font-size: clamp(2.5rem, 4vw, 3.8rem); line-height: 1.15; font-weight: 800; margin-bottom: 1.5rem; letter-spacing: -0.03em; color: #FFFFFF;">O marketplace privado dos<br>lojistas mobile.</h1>
                    <p style="font-size: 1.5rem; color: #A0AEC0; margin-bottom: 2.5rem; line-height: 1.4; font-weight: 300;">Compre e venda celulares de repasse com segurança,<br>Pix automático e lojistas verificados.</p>
                    <div style="display: flex; gap: 1.5rem; margin-bottom: 4rem;">
                        <a href="index.php?p=register" style="background: transparent; border: 1px solid #0066ff; color: white; padding: 0.8rem 2rem; border-radius: 9999px; font-weight: 600; text-decoration: none; font-size: 1.1rem;">Solicitar acesso</a>
                        <a href="index.php?p=about" style="color: white; text-decoration: none; padding: 0.8rem 2rem; border: 1px solid #ffffff; border-radius: 9999px; font-weight: 500; font-size: 1.1rem; background: transparent;">Conhecer a plataforma</a>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 1rem; font-size: 1.25rem;">
                        <div style="display: flex; gap: 0.75rem; align-items: center; opacity: 0.9;">
                            <span style="display: inline-block; width: 14px; height: 22px; background: #0066ff; border-radius: 3px; box-shadow: 0 0 10px rgba(0,102,255,0.5);"></span>
                            <span><strong style="font-weight: 700; color: white;">2.347</strong> aparelhos anunciados</span>
                        </div>
                        <div style="display: flex; gap: 0.75rem; align-items: center; opacity: 0.9;">
                            <span style="display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; background: #0066ff; color: white; border-radius: 50%; font-size: 0.8rem; font-weight: bold; box-shadow: 0 0 10px rgba(0,102,255,0.5);">✓</span>
                            <span><strong style="font-weight: 700; color: white;">312</strong> lojistas verificados</span>
                        </div>
                    </div>
                </div>
                <div style="flex: 0 0 40%; position: relative; display: flex; justify-content: center; align-items: center;">
                    <div style="position: absolute; width: 600px; height: 600px; background: radial-gradient(circle, rgba(0,102,255,0.08) 0%, transparent 60%); border-radius: 50%; top: 50%; left: 50%; transform: translate(-50%, -50%); pointer-events: none;"></div>
                    <img src="assets/img/celular3d.png?v=3" alt="Celular LOJIST" style="max-width: 100%; height: auto; object-fit: contain; filter: drop-shadow(0 30px 50px rgba(0,0,0,0.8)); transform: scale(1.15); position: relative; z-index: 2;">
                </div>
            </section>
        </header>
        <?php render_footer(); break;

    case 'about':
        render_header('Sobre a Plataforma');
        ?>
        <main style="background: #040D1A; color: white; min-height: 100vh; padding: 4rem 2rem;">
            <div style="max-width: 800px; margin: 0 auto; text-align: left;">
                <a href="index.php" style="display: inline-block; margin-bottom: 3rem;"><img src="assets/img/logo.png?v=5" alt="LOJIST" style="height: 50px;"></a>
                <h1 style="font-size: 3.5rem; font-weight: 800; margin-bottom: 2rem; line-height: 1.1;">O primeiro marketplace privado 100% focado em lojistas mobile.</h1>
                <p style="font-size: 1.25rem; line-height: 1.8; opacity: 0.8; margin-bottom: 2rem;">A LOJIST foi criada para resolver os maiores problemas no mercado de repasse de celulares: falta de confiança, calotes, envios demorados e aparelhos com defeitos não declarados.</p>
                <p style="font-size: 1.25rem; line-height: 1.8; opacity: 0.8; margin-bottom: 2rem;">Nós verificamos manualmente cada lojista antes de aprovar o acesso. O pagamento é garantido e seguro via Pix. O dinheiro só é liberado para o vendedor após o aparelho ser entregue com sucesso e validado pelo comprador.</p>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-top: 3rem; margin-bottom: 4rem;">
                    <div style="background: rgba(255,255,255,0.05); padding: 2rem; border-radius: 1rem; border: 1px solid rgba(255,255,255,0.1);">
                        <h3 style="font-size: 1.5rem; margin-bottom: 1rem; color: #0066ff;">Compra Garantida</h3>
                        <p style="opacity: 0.8;">Se o aparelho não for entregue ou estiver fora do padrão anunciado, seu dinheiro volta para você na hora.</p>
                    </div>
                    <div style="background: rgba(255,255,255,0.05); padding: 2rem; border-radius: 1rem; border: 1px solid rgba(255,255,255,0.1);">
                        <h3 style="font-size: 1.5rem; margin-bottom: 1rem; color: #0066ff;">Vendedores Reais</h3>
                        <p style="opacity: 0.8;">Zero golpistas. Todos os CNPJs, Instagrams e identidades são validados antes do primeiro acesso.</p>
                    </div>
                </div>
                <div style="text-align: center;">
                    <a href="index.php?p=register" style="display: inline-block; background: #0066ff; color: white; padding: 1rem 3rem; border-radius: 9999px; font-weight: 700; text-decoration: none; font-size: 1.25rem; transition: background 0.3s;">Quero me cadastrar</a>
                </div>
            </div>
        </main>
        <?php render_footer(); break;

    case 'register':
        render_header('Solicitar acesso');
        ?>
        <main class="auth-page"><section class="auth-card wide"><?= logo() ?><h1>Solicitar acesso</h1><p>Cadastro privado. O acesso só é liberado após aprovação manual.</p><form method="post" enctype="multipart/form-data" class="form-grid"><input type="hidden" name="action" value="register">
            <?php foreach (['nome'=>'Nome do responsável','sobrenome'=>'Sobrenome','cpf'=>'CPF','cnpj'=>'CNPJ opcional','email'=>'E-mail','telefone'=>'Telefone','nome_loja'=>'Nome da loja','instagram_loja'=>'Instagram da loja','cidade'=>'Cidade','estado'=>'Estado','endereco_completo'=>'Endereço comercial completo'] as $name=>$label): ?><label><?= e($label) ?><input <?= $name === 'cnpj' ? '' : 'required' ?> name="<?= e($name) ?>" type="<?= $name === 'email' ? 'email' : 'text' ?>"></label><?php endforeach; ?>
            <label>Senha<input required name="senha" type="password" minlength="6"></label><label>Documento opcional<input name="documento" type="file" accept="image/*"></label><label class="full">Comprovante obrigatório<small>Envie print de rede social da loja, cartão CNPJ, página do site ou documento que ajude na análise.</small><input required name="comprovante" type="file" accept="image/*"></label><button class="button full">Enviar solicitação</button></form><a href="index.php?p=login">Já tenho acesso</a></section></main>
        <?php render_footer(); break;

    case 'login':
        render_header('Entrar');
        ?>
        <main class="auth-page login-page"><div class="login-bg-logo"><?= logo() ?></div><div class="floating-login-logo"><?= logo() ?></div><section class="auth-card"><h1>Entrar na plataforma</h1><form method="post"><input type="hidden" name="action" value="login"><label>E-mail<input required name="email" type="email"></label><label>Senha<input required name="senha" type="password"></label><button class="button full">Entrar</button></form><div class="row between"><a href="index.php?p=register">Solicitar acesso</a><a href="index.php?p=forgot-password">Esqueci minha senha</a></div><p class="muted">Teste: admin@lojist.com / admin123 ou lojista@lojist.com / lojista123</p></section></main>
        <?php render_footer(); break;

    case 'forgot-password':
        render_header('Recuperar senha');
        ?>
        <main class="auth-page login-page"><div class="login-bg-logo"><?= logo() ?></div><div class="floating-login-logo"><?= logo() ?></div><section class="auth-card"><h1>Recuperar senha</h1><p>Informe seu e-mail cadastrado. Enviaremos um link seguro com validade de 45 minutos.</p><form method="post"><input type="hidden" name="action" value="forgot_password"><label>E-mail<input required name="email" type="email" autocomplete="email"></label><button class="button full">Enviar link de recuperacao</button></form><a href="index.php?p=login">Voltar para o login</a></section></main>
        <?php render_footer(); break;

    case 'reset-password':
        render_header('Nova senha');
        $token = (string)($_GET['token'] ?? '');
        ?>
        <main class="auth-page login-page"><div class="login-bg-logo"><?= logo() ?></div><div class="floating-login-logo"><?= logo() ?></div><section class="auth-card"><h1>Criar nova senha</h1><form method="post"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="token" value="<?= e($token) ?>"><label>Nova senha<input required name="senha" type="password" minlength="8" autocomplete="new-password"></label><button class="button full">Redefinir senha</button></form><a href="index.php?p=login">Voltar para o login</a></section></main>
        <?php render_footer(); break;

    case 'dashboard':
        $user = layout_start('Painel do lojista');
        $pdo = db();
        $stats = $pdo->prepare('SELECT COUNT(*) total, SUM(status="disponivel") ativos, SUM(status="pausado") pausados, SUM(status IN ("pagamento_aprovado","enviado","entregue","finalizado")) vendidos FROM products WHERE vendedor_id=?');
        $stats->execute([$user['id']]);
        $stats = $stats->fetch() ?: ['ativos' => 0, 'pausados' => 0, 'vendidos' => 0];
        $month = $pdo->prepare('SELECT COALESCE(SUM(valor_bruto),0) bruto, COUNT(*) vendas, COALESCE(AVG(valor_bruto),0) ticket FROM orders WHERE vendedor_id=? AND status IN ("pagamento_aprovado","preparando_envio","enviado","entregue","finalizado") AND data_criacao >= DATE_FORMAT(NOW(), "%Y-%m-01")');
        $month->execute([$user['id']]);
        $month = $month->fetch() ?: ['bruto' => 0, 'vendas' => 0, 'ticket' => 0];
        $purchases = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE comprador_id=? AND status IN ("pagamento_aprovado","preparando_envio","enviado","entregue","finalizado") AND data_criacao >= DATE_FORMAT(NOW(), "%Y-%m-01")');
        $purchases->execute([$user['id']]);
        $openSales = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE vendedor_id=? AND status IN ("pagamento_aprovado","preparando_envio","enviado","entregue")');
        $openSales->execute([$user['id']]);
        $levelProgress = min(100, max(8, (float)$user['nota_geral'] * 18 + min(10, ((int)$user['vendas_concluidas'] + (int)$user['compras_concluidas']) / 3)));
        echo '<div class="page-head animate-entry"><div><span class="eyebrow">Painel do lojista</span><h1>Controle de estoque, vendas e liquidez</h1><p class="page-subtitle">Visão real do seu mês, estoque e reputação com base nos dados da plataforma.</p></div><a class="button" href="index.php?p=new-product"><i class="ph ph-plus" style="margin-right: 4px;"></i> Anunciar aparelho</a></div><section class="metric-grid">';
        metric_card('Faturamento do mês', money((float)$month['bruto']), '', 'ph-currency-dollar');
        metric_card('Vendas do mês', (string)(int)$month['vendas'], '', 'ph-shopping-cart');
        metric_card('Compras do mês', (string)(int)$purchases->fetchColumn(), '', 'ph-bag');
        metric_card('Ticket médio', money((float)$month['ticket']), '', 'ph-receipt');
        metric_card('Produtos ativos', (string)(int)($stats['ativos'] ?? 0), '', 'ph-device-mobile');
        metric_card('Produtos pausados', (string)(int)($stats['pausados'] ?? 0), '', 'ph-pause-circle');
        metric_card('Taxa de cancelamento', number_format((float)$user['taxa_cancelamento'],1,',','.').'%', '', 'ph-warning-circle');
        metric_card('Avaliação média', number_format((float)$user['nota_geral'],1,',','.'), '', 'ph-star');
        echo '</section>';
        echo '<div class="chart-container-premium animate-entry"><div class="chart-header"><span class="chart-title"><i class="ph ph-chart-line-up" style="color: var(--blue-2); font-size: 1.5rem;"></i> Evolução de Faturamento</span><span class="chip" style="background: rgba(0, 102, 255, 0.1); border-color: var(--blue); color: var(--blue-2);">Mês atual</span></div><div style="height: 250px; width: 100%;"><canvas id="lojistaChart"></canvas></div></div>';
        echo '<section class="panel-grid" style="margin-top: 32px;"><div class="panel premium-panel animate-entry"><h2>Nível atual</h2><div class="level big ' . level_class($user['nivel']) . '">' . e($user['nivel']) . '</div><div class="progress"><span style="width:' . $levelProgress . '%"></span></div><p>Progresso calculado por reputação, vendas, compras, SLA, cancelamentos, score e recorrência.</p><p class="muted">Vendas abertas agora: ' . (int)$openSales->fetchColumn() . '</p></div><div class="panel animate-entry"><h2>Eventos em tempo real</h2>';
        notifications_list((int)$user['id']);
        echo '</div></section>';
        layout_end(); break;

    case 'feed':
        $user = layout_start('Feed de anúncios');
        $plan = plan_for($user['plano']);
        $where = ['p.status="disponivel"'];
        $args = [];
        foreach (['categoria', 'modelo', 'armazenamento', 'cor', 'estado'] as $filter) {
            if (!empty($_GET[$filter])) { $where[] = ($filter === 'estado' ? 'p.estado' : 'p.' . $filter) . ' LIKE ?'; $args[] = '%' . $_GET[$filter] . '%'; }
        }
        if (!empty($_GET['min'])) { $where[] = 'p.preco >= ?'; $args[] = (float)$_GET['min']; }
        if (!empty($_GET['max'])) { $where[] = 'p.preco <= ?'; $args[] = (float)$_GET['max']; }
        if (!empty($_GET['tipo'])) { $where[] = 'p.tipo = ?'; $args[] = $_GET['tipo']; }
        if (!empty($_GET['venda_expressa'])) { $where[] = 'p.venda_expressa = 1'; }
        $autoCity = (string)($_GET['cidade'] ?? $user['cidade']);
        if (empty($_GET['todos']) || (int)$plan['filtros_avancados'] !== 1) {
            $where[] = 'p.cidade LIKE ?';
            $args[] = '%' . $autoCity . '%';
        }
        [$products, $total] = paged_query(db(), 'SELECT p.*, u.plano, u.nivel, u.nota_geral, u.vendas_concluidas, u.taxa_cancelamento, u.tempo_medio_envio FROM products p JOIN users u ON u.id=p.vendedor_id WHERE ' . implode(' AND ', $where) . ' ORDER BY p.venda_expressa DESC, p.data_criacao DESC', 'SELECT COUNT(*) FROM products p JOIN users u ON u.id=p.vendedor_id WHERE ' . implode(' AND ', $where), $args);
        ?>
        <div class="page-head"><div><span class="eyebrow">Feed privado B2B</span><h1><?= $total ?> anúncios verificados disponíveis</h1><p class="page-subtitle">Todos os aparelhos ativos aparecem para lojistas aprovados. Os filtros apenas refinam a busca.</p></div></div>
        <div class="notice">Busca iniciada automaticamente em <?= e($autoCity) ?>/<?= e($user['estado']) ?>. Quando o navegador permite localização, a plataforma ajusta a cidade do aparelho; se não permitir, usa a cidade cadastrada do lojista.</div>
        <form class="filter-bar" method="get" data-auto-city-filter><input type="hidden" name="p" value="feed"><select name="categoria"><option value="">Categoria</option><option <?= selected('iPhone', $_GET['categoria'] ?? null) ?>>iPhone</option><option <?= selected('Samsung', $_GET['categoria'] ?? null) ?>>Samsung</option><option <?= selected('Xiaomi', $_GET['categoria'] ?? null) ?>>Xiaomi</option></select><input name="modelo" value="<?= e((string)($_GET['modelo'] ?? '')) ?>" placeholder="Modelo"><input name="armazenamento" value="<?= e((string)($_GET['armazenamento'] ?? '')) ?>" placeholder="Armazenamento"><input name="cor" value="<?= e((string)($_GET['cor'] ?? '')) ?>" placeholder="Cor"><input name="estado" value="<?= e((string)($_GET['estado'] ?? $user['estado'])) ?>" placeholder="UF"><input name="min" value="<?= e((string)($_GET['min'] ?? '')) ?>" type="number" placeholder="Preço mínimo"><input name="max" value="<?= e((string)($_GET['max'] ?? '')) ?>" type="number" placeholder="Preço máximo"><select name="tipo"><option value="">Tipo</option><option value="seminovo" <?= selected('seminovo', $_GET['tipo'] ?? null) ?>>Seminovo</option><option value="lacrado" <?= selected('lacrado', $_GET['tipo'] ?? null) ?>>Lacrado</option></select><input name="cidade" value="<?= e($autoCity) ?>" placeholder="Cidade específica" <?= (int)$plan['filtros_avancados'] ? '' : 'data-pro-locked readonly' ?>><label class="check"><input type="checkbox" name="venda_expressa" value="1" <?= !empty($_GET['venda_expressa']) ? 'checked' : '' ?>> Venda expressa</label><?php if ((int)$plan['filtros_avancados']): ?><label class="check"><input type="checkbox" name="todos" value="1" <?= !empty($_GET['todos']) ? 'checked' : '' ?>> Ver todas as cidades</label><?php endif; ?><button class="button small">Filtrar</button><a class="ghost small" href="index.php?p=feed">Limpar</a></form>
        <section class="product-grid"><?php foreach ($products as $p) { product_card($p); } ?></section><?php if (!$products): ?><div class="empty">Nenhum anúncio encontrado com estes filtros.</div><?php endif; ?><?php pagination_links($total); ?>
        <?php layout_end(); break;

    case 'product':
        render_product_page();
        break;
    case 'new-product':
        render_new_product_page();
        break;
    case 'edit-product':
        render_edit_product_page();
        break;
    case 'market-research':
        render_market_research_page();
        break;
    case 'inventory':
        render_inventory_page();
        break;
    case 'price-alerts':
        render_price_alerts_page();
        break;
    case 'referrals':
        render_referrals_page();
        break;
    case 'my-products':
        render_my_products_page();
        break;
    case 'pdv':
        render_pdv_page();
        break;
    case 'checkout':
        render_checkout_page();
        break;
    case 'plan-checkout':
        render_plan_checkout_page();
        break;
    case 'tracking':
        render_tracking_page();
        break;
    case 'offers':
    case 'sales':
    case 'purchases':
    case 'finance':
    case 'notifications':
    case 'profile':
        render_generic_app_page($page);
        break;
    case 'plans':
        render_subscriptions_page();
        break;
    case 'edit-profile':
        render_edit_profile_page();
        break;
    case 'admin':
        render_admin();
        break;
    default:
        redirect('landing');
}

function device_reference_lists(): array
{
    return [
        'models' => [
            'iPhone XR', 'iPhone XS', 'iPhone XS Max',
            'iPhone 11', 'iPhone 11 Pro', 'iPhone 11 Pro Max',
            'iPhone 12 mini', 'iPhone 12', 'iPhone 12 Pro', 'iPhone 12 Pro Max',
            'iPhone 13 mini', 'iPhone 13', 'iPhone 13 Pro', 'iPhone 13 Pro Max',
            'iPhone SE 3Âª geração',
            'iPhone 14', 'iPhone 14 Plus', 'iPhone 14 Pro', 'iPhone 14 Pro Max',
            'iPhone 15', 'iPhone 15 Plus', 'iPhone 15 Pro', 'iPhone 15 Pro Max',
            'iPhone 16', 'iPhone 16 Plus', 'iPhone 16 Pro', 'iPhone 16 Pro Max',
            'Galaxy S21', 'Galaxy S21 Ultra', 'Galaxy S22', 'Galaxy S22 Ultra',
            'Galaxy S23', 'Galaxy S23 Ultra', 'Galaxy S24', 'Galaxy S24 Ultra',
            'Galaxy Z Flip5', 'Galaxy Z Flip6', 'Galaxy Z Fold5', 'Galaxy Z Fold6',
            'Xiaomi 12', 'Xiaomi 13', 'Xiaomi 13T Pro', 'Xiaomi 14', 'Xiaomi 14 Ultra',
            'Redmi Note 12', 'Redmi Note 13', 'Poco X6 Pro', 'Poco F6',
        ],
        'storages' => ['64 GB', '128 GB', '256 GB', '512 GB', '1 TB'],
        'colors' => [
            'Preto', 'Branco', 'Azul', 'Verde', 'Vermelho', 'Roxo', 'Rosa', 'Amarelo',
            'Meia-noite', 'Estelar', 'Grafite', 'Prateado', 'Dourado',
            'Titânio Natural', 'Titânio Azul', 'Titânio Branco', 'Titânio Preto',
            'Roxo Profundo', 'Azul Sierra', 'Cinza Espacial',
        ],
    ];
}

function render_device_datalists(): void
{
    $lists = device_reference_lists();
    echo '<datalist id="deviceModels">';
    foreach ($lists['models'] as $item) { echo '<option value="' . e($item) . '">'; }
    echo '</datalist><datalist id="deviceStorages">';
    foreach ($lists['storages'] as $item) { echo '<option value="' . e($item) . '">'; }
    echo '</datalist><datalist id="deviceColors">';
    foreach ($lists['colors'] as $item) { echo '<option value="' . e($item) . '">'; }
    echo '</datalist>';
}

function render_delivery_fields(array $user): void
{
    $name = $user['entrega_nome'] ?: $user['nome'] . ' ' . $user['sobrenome'];
    $phone = $user['entrega_telefone'] ?: $user['telefone'];
    $city = $user['entrega_cidade'] ?: $user['cidade'];
    $state = $user['entrega_estado'] ?: $user['estado'];
    ?>
    <div class="delivery-choice" data-delivery-choice>
        <div class="saved-address">
            <strong>Endereço cadastrado</strong>
            <p><?= e($name) ?> • <?= e($phone) ?></p>
            <p><?= e((string)$user['entrega_endereco']) ?><?= $user['entrega_complemento'] ? ', ' . e((string)$user['entrega_complemento']) : '' ?> • <?= e($city) ?>/<?= e($state) ?> • CEP <?= e((string)$user['entrega_cep']) ?></p>
        </div>
        <label class="check"><input type="checkbox" data-alt-address-toggle> Usar outro endereço nesta compra</label>
        <input required name="destinatario" value="<?= e($name) ?>" placeholder="Nome do destinatário" readonly data-default-address-field>
        <input required name="telefone_entrega" value="<?= e($phone) ?>" placeholder="Telefone para entrega" readonly data-default-address-field>
        <input required name="cep_entrega" value="<?= e((string)$user['entrega_cep']) ?>" placeholder="CEP" readonly data-default-address-field>
        <input required name="endereco_entrega" value="<?= e((string)$user['entrega_endereco']) ?>" placeholder="Endereço completo de entrega" readonly data-default-address-field>
        <div class="two-cols">
            <input required name="cidade_entrega" value="<?= e($city) ?>" placeholder="Cidade" readonly data-default-address-field>
            <input required name="estado_entrega" value="<?= e($state) ?>" maxlength="2" placeholder="UF" readonly data-default-address-field>
        </div>
        <input name="complemento_entrega" value="<?= e((string)$user['entrega_complemento']) ?>" placeholder="Complemento ou referência" readonly data-default-address-field>
    </div>
    <?php
}

function render_product_page(): void
{
    $user = layout_start('Anúncio');
    $stmt = db()->prepare('SELECT p.*, u.plano, u.nivel, u.nota_geral, u.vendas_concluidas, u.taxa_cancelamento, u.tempo_medio_envio, u.score_vendedor FROM products p JOIN users u ON u.id=p.vendedor_id WHERE p.id=?');
    $stmt->execute([(int)($_GET['id'] ?? 0)]);
    $p = $stmt->fetch();
    if (!$p) { echo '<div class="empty">Anúncio não encontrado.</div>'; layout_end(); return; }
    ?>
    <?php $photos = product_images($p); ?>
    <section class="detail-grid" data-live-product-id="<?= (int)$p['id'] ?>"><div class="gallery protected-image product-carousel" data-carousel><button type="button" class="carousel-btn prev" data-carousel-prev>&lsaquo;</button><img src="<?= e($photos[0]) ?>" draggable="false" alt="<?= e($p['modelo']) ?>" data-carousel-main><button type="button" class="carousel-btn next" data-carousel-next>&rsaquo;</button><span class="watermark large">LOJIST</span><div class="thumb-row"><?php foreach ($photos as $i => $photo): ?><button type="button" class="<?= $i === 0 ? 'active' : '' ?>" data-carousel-thumb="<?= (int)$i ?>" data-src="<?= e($photo) ?>"><img src="<?= e($photo) ?>" alt="Foto <?= $i + 1 ?>"></button><?php endforeach; ?></div><button type="button" class="ghost small zoom-photo" data-open-photo>Ampliar foto</button></div><div class="detail-panel"><span class="chip"><?= e($p['categoria']) ?></span><h1><?= e($p['modelo']) ?></h1><strong class="price xl"><?= money((float)$p['preco']) ?></strong><div class="spec-grid">
    <?php foreach (['Marca'=>$p['marca'],'Armazenamento'=>$p['armazenamento'],'Cor'=>$p['cor'],'Tipo'=>ucfirst($p['tipo']),'Cidade/UF'=>$p['cidade'].'/'.$p['estado'],'SLA de envio'=>$p['tempo_medio_envio']] as $k=>$v): ?><div><span><?= e($k) ?></span><strong><?= e($v) ?></strong></div><?php endforeach; ?>
    <?php
    $planColor = '#6b7280'; $planName = $p['plano'] ?? 'Free';
    if ($planName === 'Pro') $planColor = 'var(--blue)';
    if ($planName === 'Elite') $planColor = '#F59E0B';
    ?>
    </div><div class="anonymous-seller"><h2>Loja verificada <span class="chip" style="background: <?= $planColor ?>; border-color: <?= $planColor ?>; color: white; font-size: 0.8rem; margin-left: 8px;"><i class="ph ph-crown" style="margin-right:4px;"></i> <?= e($planName) ?></span></h2><p>Lojista aprovado • Nível <span class="level <?= level_class($p['nivel']) ?>"><?= e($p['nivel']) ?></span> • Nota <?= number_format((float)$p['nota_geral'],1,',','.') ?> • <?= (int)$p['vendas_concluidas'] ?> vendas</p><p>Cancelamento <?= number_format((float)$p['taxa_cancelamento'],1,',','.') ?>% • Score vendedor <?= number_format((float)$p['score_vendedor'],1,',','.') ?></p></div>
    <?php $insight = market_insight_for((string)$p['modelo'], (string)$p['armazenamento'], (float)$p['preco']); ?>
    <section class="market-insight">
        <div><span>Valor sugerido</span><strong><?= money((float)$insight['suggested']) ?></strong></div>
        <div><span>Potencial de margem</span><strong><?= number_format((float)$insight['buyer_margin'], 1, ',', '.') ?>%</strong></div>
        <div><span>Leitura do mercado</span><strong><?= e($insight['verdict']) ?></strong></div>
        <p><?= (int)$insight['market']['count'] ?> referência(s) em <?= e((string)$insight['market']['source']) ?>. Use como apoio para decidir se a compra faz sentido.</p>
    </section>
    <p class="hint"><?= $p['preco_ajustado_recentemente'] ? 'Preço ajustado recentemente' : 'Condição revisada' ?></p><p><strong>Métodos:</strong> <?= e($p['metodos_entrega']) ?></p><p><strong>Detalhes estruturados:</strong> <?= e($p['detalhes_estruturados']) ?></p>
    <?php if ((int)$p['vendedor_id'] !== (int)$user['id'] && $p['status'] === 'disponivel'): ?>
        <form method="post" class="buy-box">
            <input type="hidden" name="action" value="buy_now">
            <input type="hidden" name="produto_id" value="<?= (int)$p['id'] ?>">
            <h2>Dados para entrega</h2>
            <select name="metodo_entrega"><?php foreach (array_map('trim', explode(',', $p['metodos_entrega'])) as $m): ?><option><?= e($m) ?></option><?php endforeach; ?></select>
            <?php render_delivery_fields($user); ?>
            <label class="check"><input required type="checkbox" name="confirm_delivery" value="1"> Confirmo que estes dados de entrega estão corretos</label>
            <button class="button full">Comprar agora e gerar Pix</button>
        </form>
        <?php if ($p['aceita_oferta']): ?><form method="post" class="inline-form"><input type="hidden" name="action" value="offer"><input type="hidden" name="produto_id" value="<?= (int)$p['id'] ?>"><input required type="number" name="valor_oferta" placeholder="Valor da oferta"><button class="ghost">Enviar oferta</button></form><?php endif; ?>
    <?php endif; ?>
    <?php if ($user['plano'] === 'Elite'): ?>
        <form method="post" action="index.php?p=price-alerts" class="inline-form" style="margin-top: 1rem;">
            <input type="hidden" name="action" value="create_price_alert">
            <input type="hidden" name="modelo" value="<?= e($p['modelo']) ?>">
            <input type="hidden" name="valor_desejado" value="<?= (float)$p['preco'] ?>">
            <button class="button" style="background: #F59E0B; border-color: #F59E0B; color: white;"><i class="ph ph-bell-ringing" style="margin-right:4px;"></i> Ativar Alerta</button>
        </form>
    <?php endif; ?>
    </div></section>
    <?php layout_end();
}

function render_new_product_page(): void
{
    layout_start('Criar anúncio');
    ?>
    <div class="page-head"><div><span class="eyebrow">Anunciar</span><h1>Novo aparelho</h1><p class="page-subtitle">Sem descrição livre: tudo estruturado para proteger o mercado e evitar contato por fora.</p></div></div>
    <div class="notice">Depois de informar modelo, armazenamento e preço, acompanhe em Meus anúncios e no PDV o valor de mercado calculado pelas vendas e anúncios dos lojistas.</div>
    <?php render_device_datalists(); ?>
    <form method="post" enctype="multipart/form-data" class="panel form-grid"><input type="hidden" name="action" value="create_product"><label>Tipo<select name="tipo" id="tipoProduto"><option value="seminovo">Seminovo</option><option value="lacrado">Lacrado</option></select></label><label>Categoria<select name="categoria"><option>iPhone</option><option>Samsung</option><option>Xiaomi</option></select></label><label>Marca<input required name="marca" value="Apple"></label><label>Modelo<input required name="modelo" list="deviceModels" placeholder="iPhone 15 Pro"></label><label>Armazenamento<input required name="armazenamento" list="deviceStorages" placeholder="256 GB"></label><label>Cor<input required name="cor" list="deviceColors"></label><label>Preço<input required name="preco" type="number" step="0.01"></label><label>Custo privado<input name="custo_privado" type="number" step="0.01"></label><label>Quantidade<input name="quantidade" type="number" min="1" value="1"></label><label class="upload-card">Fotos do produto (até 8)<input required name="fotos[]" type="file" accept="image/*,.heic,.heif,.pdf" multiple><span>Arraste ou selecione várias fotos do aparelho. A LOJIST aplica watermark automático.</span></label><div class="seminovo-fields"><label>Estado geral<select name="estado_geral"><option>Excelente</option><option>Muito bom</option><option>Bom</option><option>Com detalhes</option></select></label><label>Saúde da bateria<input name="bateria" placeholder="Ex.: 91%"></label><label>Face ID funcionando?<select name="face_id"><option>Sim</option><option>Não</option><option>Não se aplica</option></select></label><label>True Tone funcionando?<select name="true_tone"><option>Sim</option><option>Não</option><option>Não se aplica</option></select></label><label>iCloud livre?<select name="icloud_livre"><option>Sim</option><option>Não</option><option>Não se aplica</option></select></label><label>Tem defeito?<select name="defeito"><option>Não</option><option>Sim</option></select></label><label>Teve peça trocada?<select name="peca_trocada" data-parts-select><option>Não</option><option>Sim</option></select></label><label>Serial Number interno<input name="serial_number" placeholder="Oculto antes da compra"></label></div><fieldset class="checks"><legend>Estado estruturado</legend><?php foreach (['Tela original','Tela trocada','Bateria original','Bateria trocada','Tampa original','Tampa trocada','Câmera OK','Câmera com detalhe','Carcaça sem marcas','Carcaça com marcas leves','Face ID OK','True Tone OK','Sem detalhes','Com detalhes','Aparelho revisado','Nacional','Importado','Nota fiscal','Garantia'] as $d): ?><label class="<?= strpos($d, 'trocad') !== false ? 'parts-only' : '' ?>"><input type="checkbox" name="detalhes[]" value="<?= e($d) ?>"> <?= e($d) ?></label><?php endforeach; ?></fieldset><label class="parts-only">Peças trocadas ou defeitos<input maxlength="120" name="detalhes_curto" placeholder="Texto curto, moderado e sem contato externo"></label><fieldset class="checks"><legend>Métodos de entrega</legend><?php foreach (['Retirada local','Entrega própria','Motoboy parceiro','Transportadora'] as $m): ?><label><input type="checkbox" name="metodos_entrega[]" value="<?= e($m) ?>" <?= $m === 'Retirada local' ? 'checked' : '' ?>> <?= e($m) ?></label><?php endforeach; ?></fieldset><label class="check"><input type="checkbox" name="aceita_oferta"> Aceitar ofertas?</label><label class="check"><input type="checkbox" name="venda_expressa"> Venda expressa</label><label class="check full"><input required type="checkbox" name="confirm_profile" value="1"> Confirmo que meus dados comerciais, retirada e envio estão atualizados</label><button class="button full">Publicar anúncio</button></form>
    <?php layout_end();
}

function render_edit_product_page(): void
{
    $user = layout_start('Editar anúncio');
    $stmt = db()->prepare('SELECT * FROM products WHERE id=? AND vendedor_id=?');
    $stmt->execute([(int)($_GET['id'] ?? 0), $user['id']]);
    $p = $stmt->fetch();
    if (!$p) { echo '<div class="empty">Anúncio não encontrado.</div>'; layout_end(); return; }
    $selectedDetails = array_map('trim', explode(',', (string)$p['detalhes_estruturados']));
    $methods = array_map('trim', explode(',', (string)$p['metodos_entrega']));
    $market = market_value_for((string)$p['modelo'], (string)$p['armazenamento']);
    ?>
    <div class="page-head"><div><span class="eyebrow">Editar</span><h1><?= e($p['modelo']) ?></h1><p class="page-subtitle">Atualize preço, fotos, estado estruturado, ofertas e métodos de entrega.</p></div></div>
    <section class="metric-grid market-inline"><?php metric_card('Valor de mercado', $market['count'] ? money((float)$market['avg']) : 'Sem histórico', $market['count'] ? (int)$market['count'] . ' referência(s) em ' . $market['source'] : 'Baseia-se nas vendas dos lojistas'); metric_card('Menor referência', $market['min'] ? money((float)$market['min']) : '-'); metric_card('Maior referência', $market['max'] ? money((float)$market['max']) : '-'); metric_card('Seu preço atual', money((float)$p['preco'])); ?></section>
    <?php render_device_datalists(); ?>
    <form method="post" enctype="multipart/form-data" class="panel form-grid"><input type="hidden" name="action" value="update_product"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><label>Tipo<select name="tipo" id="tipoProduto"><option value="seminovo" <?= selected('seminovo', $p['tipo']) ?>>Seminovo</option><option value="lacrado" <?= selected('lacrado', $p['tipo']) ?>>Lacrado</option></select></label><label>Categoria<select name="categoria"><option <?= selected('iPhone', $p['categoria']) ?>>iPhone</option><option <?= selected('Samsung', $p['categoria']) ?>>Samsung</option><option <?= selected('Xiaomi', $p['categoria']) ?>>Xiaomi</option></select></label><label>Marca<input required name="marca" value="<?= e($p['marca']) ?>"></label><label>Modelo<input required name="modelo" list="deviceModels" value="<?= e($p['modelo']) ?>"></label><label>Armazenamento<input required name="armazenamento" list="deviceStorages" value="<?= e($p['armazenamento']) ?>"></label><label>Cor<input required name="cor" list="deviceColors" value="<?= e($p['cor']) ?>"></label><label>Preço<input required name="preco" type="number" step="0.01" value="<?= e((string)$p['preco']) ?>"></label><label>Custo privado<input name="custo_privado" type="number" step="0.01" value="<?= e((string)$p['custo_privado']) ?>"></label><label>Quantidade<input name="quantidade" type="number" min="1" value="<?= (int)$p['quantidade'] ?>"></label><label class="upload-card">Novas fotos (opcional, até 8)<input name="fotos[]" type="file" accept="image/*,.heic,.heif,.pdf" multiple><span>Se enviar novas fotos, elas substituem a galeria atual com watermark LOJIST.</span></label><div class="seminovo-fields"><label>Estado geral<input name="estado_geral" value="<?= e((string)$p['estado_geral']) ?>"></label><label>Saúde da bateria<input name="bateria" value="<?= e((string)$p['bateria']) ?>"></label><label>Face ID<select name="face_id"><option <?= selected('Sim', $p['face_id']) ?>>Sim</option><option <?= selected('Não', $p['face_id']) ?>>Não</option><option <?= selected('Não se aplica', $p['face_id']) ?>>Não se aplica</option></select></label><label>True Tone<select name="true_tone"><option <?= selected('Sim', $p['true_tone']) ?>>Sim</option><option <?= selected('Não', $p['true_tone']) ?>>Não</option><option <?= selected('Não se aplica', $p['true_tone']) ?>>Não se aplica</option></select></label><label>iCloud livre<select name="icloud_livre"><option <?= selected('Sim', $p['icloud_livre']) ?>>Sim</option><option <?= selected('Não', $p['icloud_livre']) ?>>Não</option><option <?= selected('Não se aplica', $p['icloud_livre']) ?>>Não se aplica</option></select></label><label>Tem defeito?<select name="defeito"><option <?= selected('Não', $p['defeito']) ?>>Não</option><option <?= selected('Sim', $p['defeito']) ?>>Sim</option></select></label><label>Peça trocada?<select name="peca_trocada" data-parts-select><option <?= selected('Não', $p['peca_trocada']) ?>>Não</option><option <?= selected('Sim', $p['peca_trocada']) ?>>Sim</option></select></label><label>Serial Number interno<input name="serial_number" value="<?= e((string)($p['serial_number'] ?: $p['imei_interno'])) ?>"></label></div><fieldset class="checks"><legend>Estado estruturado</legend><?php foreach (['Tela original','Tela trocada','Bateria original','Bateria trocada','Tampa original','Tampa trocada','Câmera OK','Câmera com detalhe','Carcaça sem marcas','Carcaça com marcas leves','Face ID OK','True Tone OK','Sem detalhes','Com detalhes','Aparelho revisado','Nacional','Importado','Nota fiscal','Garantia'] as $d): ?><label class="<?= strpos($d, 'trocad') !== false ? 'parts-only' : '' ?>"><input type="checkbox" name="detalhes[]" value="<?= e($d) ?>" <?= checked($d, $selectedDetails) ?>> <?= e($d) ?></label><?php endforeach; ?></fieldset><label class="parts-only">Peças trocadas ou defeitos<input maxlength="120" name="detalhes_curto" placeholder="Complemento curto, sem contato externo"></label><fieldset class="checks"><legend>Métodos de entrega</legend><?php foreach (['Retirada local','Entrega própria','Motoboy parceiro','Transportadora'] as $m): ?><label><input type="checkbox" name="metodos_entrega[]" value="<?= e($m) ?>" <?= checked($m, $methods) ?>> <?= e($m) ?></label><?php endforeach; ?></fieldset><label class="check"><input type="checkbox" name="aceita_oferta" <?= (int)$p['aceita_oferta'] ? 'checked' : '' ?>> Aceitar ofertas?</label><label class="check"><input type="checkbox" name="venda_expressa" <?= (int)$p['venda_expressa'] ? 'checked' : '' ?>> Venda expressa</label><button class="button full">Salvar alterações</button></form>
    <?php layout_end();
}

function render_my_products_page(): void
{
    $user = layout_start('Meus anúncios');
    [$rows, $total] = paged_query(db(), 'SELECT * FROM products WHERE vendedor_id=? ORDER BY data_criacao DESC', 'SELECT COUNT(*) FROM products WHERE vendedor_id=?', [$user['id']]);
    echo '<div class="page-head"><div><span class="eyebrow">Anúncios</span><h1>Meus anúncios</h1><p class="page-subtitle">Gerencie status, edição, preço de mercado, margem e duplicidade dos aparelhos.</p></div><div class="row"><a class="button" href="index.php?p=new-product">Novo anúncio</a><form method="post"><input type="hidden" name="action" value="pause_all"><button class="ghost">Pausar todos</button></form></div></div>';
    echo '<div class="table-wrap"><table><thead><tr><th>Produto</th><th>Status</th><th>Preço</th><th>Valor de mercado</th><th>Custo</th><th>Margem estimada</th><th>Ação</th></tr></thead><tbody>';
    foreach ($rows as $p) {
        [$fee, $net] = calculate_fee((float)$p['preco'], $user['plano']);
        $market = market_value_for((string)$p['modelo'], (string)$p['armazenamento']);
        $profit = $p['custo_privado'] ? $net - (float)$p['custo_privado'] : 0;
        $margin = $p['custo_privado'] && (float)$p['preco'] > 0 ? ($profit / (float)$p['preco']) * 100 : null;
        $marketCell = $market['count'] > 0 ? money((float)$market['avg']) . '<small>' . (int)$market['count'] . ' referência(s) em ' . e($market['source']) . '</small>' : '<span class="muted">Sem histórico</span>';
        echo '<tr><td>' . e($p['modelo']) . '<small>' . e($p['categoria']) . ' • ' . e($p['armazenamento']) . ' • ' . e($p['cidade']) . '/' . e($p['estado']) . '</small></td><td><span class="chip">' . e(status_label($p['status'])) . '</span></td><td>' . money((float)$p['preco']) . '</td><td>' . $marketCell . '</td><td>' . ($p['custo_privado'] ? money((float)$p['custo_privado']) : 'Privado') . '</td><td>' . ($margin !== null ? money($profit) . '<small>' . number_format($margin, 1, ',', '.') . '%</small>' : 'Cadastre custo') . '</td><td><div class="action-stack"><a class="ghost small" href="index.php?p=product&id=' . (int)$p['id'] . '">Ver</a><a class="button small" href="index.php?p=edit-product&id=' . (int)$p['id'] . '">Editar</a><form method="post"><input type="hidden" name="action" value="toggle_product"><input type="hidden" name="id" value="' . (int)$p['id'] . '"><button class="ghost small">' . ($p['status'] === 'pausado' ? 'Reativar' : 'Pausar') . '</button></form><form method="post"><input type="hidden" name="action" value="delete_product"><input type="hidden" name="id" value="' . (int)$p['id'] . '"><button class="danger small">Excluir</button></form></div></td></tr>';
    }
    echo '</tbody></table></div>'; pagination_links($total); layout_end();
}

function render_pdv_page(): void
{
    $user = layout_start('PDV e estoque');
    $pdo = db();
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(valor_bruto),0) bruto, COALESCE(SUM(taxa_plataforma),0) repasse, COALESCE(SUM(valor_liquido),0) liquido, COUNT(*) vendas FROM orders WHERE vendedor_id=? AND status IN ("pagamento_aprovado","preparando_envio","enviado","entregue","finalizado")');
    $stmt->execute([$user['id']]);
    $sales = $stmt->fetch() ?: ['bruto' => 0, 'repasse' => 0, 'liquido' => 0, 'vendas' => 0];

    $stmt = $pdo->prepare('SELECT COALESCE(SUM(preco * quantidade),0) valor_estoque, COUNT(*) total, SUM(status="disponivel") ativos, SUM(status="pausado") pausados, SUM(status="pausado" OR data_criacao < DATE_SUB(NOW(), INTERVAL 30 DAY)) parado FROM products WHERE vendedor_id=?');
    $stmt->execute([$user['id']]);
    $stock = $stmt->fetch() ?: ['valor_estoque' => 0, 'total' => 0, 'ativos' => 0, 'pausados' => 0, 'parado' => 0];

    $stmt = $pdo->prepare('SELECT COALESCE(SUM(o.valor_liquido - COALESCE(p.custo_privado,0)),0) lucro, COALESCE(AVG(CASE WHEN p.custo_privado IS NOT NULL AND o.valor_bruto > 0 THEN ((o.valor_liquido - p.custo_privado) / o.valor_bruto) * 100 END),0) margem FROM orders o JOIN products p ON p.id=o.produto_id WHERE o.vendedor_id=? AND o.status IN ("pagamento_aprovado","preparando_envio","enviado","entregue","finalizado")');
    $stmt->execute([$user['id']]);
    $profit = $stmt->fetch() ?: ['lucro' => 0, 'margem' => 0];

    echo '<div class="page-head"><div><span class="eyebrow">PDV</span><h1>Controle de estoque e rentabilidade</h1><p class="page-subtitle">Seu balcão privado: estoque anunciado, repasse para a plataforma, margem, faturamento e aparelhos parados.</p></div><a class="button" href="index.php?p=new-product">Adicionar aparelho</a></div><section class="metric-grid">';
    foreach (['Faturamento' => money((float)$sales['bruto']), 'Lucro estimado' => money((float)$profit['lucro']), 'Repasse plataforma' => money((float)$sales['repasse']), 'Margem média' => number_format((float)$profit['margem'], 1, ',', '.') . '%', 'Valor em estoque' => money((float)$stock['valor_estoque']), 'Anúncios ativos' => (string)(int)$stock['ativos'], 'Estoque pausado' => (string)(int)$stock['pausados'], 'Estoque parado' => (string)(int)$stock['parado']] as $k => $v) { metric_card($k, $v); }
    echo '</section>';

    $stmt = $pdo->prepare('SELECT categoria, modelo, armazenamento, status, quantidade, preco, custo_privado, data_criacao FROM products WHERE vendedor_id=? ORDER BY status="disponivel" DESC, data_criacao DESC LIMIT 30');
    $stmt->execute([$user['id']]);
    $rows = $stmt->fetchAll();
    echo '<section class="panel"><h2>Estoque do PDV</h2><div class="table-wrap"><table><thead><tr><th>Aparelho</th><th>Status</th><th>Preço</th><th>Mercado</th><th>Custo</th><th>Margem</th><th>Giro</th></tr></thead><tbody>';
    foreach ($rows as $p) {
        $market = market_value_for((string)$p['modelo'], (string)$p['armazenamento']);
        $cost = $p['custo_privado'] ? (float)$p['custo_privado'] : null;
        $profitValue = $cost !== null ? (float)$p['preco'] - $cost : null;
        $margin = $profitValue !== null && (float)$p['preco'] > 0 ? ($profitValue / (float)$p['preco']) * 100 : null;
        $created = strtotime((string)$p['data_criacao']);
        $days = $created ? floor((time() - $created) / 86400) : 0;
        echo '<tr><td>' . e($p['modelo']) . '<small>' . e($p['categoria']) . ' • ' . e($p['armazenamento']) . ' • Qtde ' . (int)$p['quantidade'] . '</small></td><td><span class="chip">' . e(status_label($p['status'])) . '</span></td><td>' . money((float)$p['preco']) . '</td><td>' . ($market['count'] ? money((float)$market['avg']) . '<small>' . (int)$market['count'] . ' referência(s)</small>' : 'Sem dados') . '</td><td>' . ($cost !== null ? money($cost) : 'Não informado') . '</td><td>' . ($margin !== null ? money((float)$profitValue) . '<small>' . number_format($margin, 1, ',', '.') . '%</small>' : 'Sem custo') . '</td><td>' . ($days >= 30 ? '<span class="chip danger-chip">Parado</span>' : '<span class="chip blue">Em giro</span>') . '<small>' . $days . ' dia(s) no estoque</small></td></tr>';
    }
    echo '</tbody></table></div></section>';
    layout_end();
}

function render_checkout_page(): void
{
    $user = layout_start('Pix');
    $stmt = db()->prepare('SELECT o.*, p.modelo, pay.asaas_invoice_url FROM orders o JOIN products p ON p.id=o.produto_id LEFT JOIN payments pay ON pay.order_id=o.id WHERE o.id=? AND o.comprador_id=?');
    $stmt->execute([(int)($_GET['id'] ?? 0), $user['id']]);
    $o = $stmt->fetch();
    echo '<section class="checkout panel">';
    if ($o) { echo '<h1>Pix Asaas gerado</h1><p>' . e($o['modelo']) . ' • ' . money((float)$o['valor_bruto']) . '</p><div class="qr">' . e($o['pix_qrcode']) . '</div>' . (!empty($o['asaas_invoice_url']) ? '<a class="button" target="_blank" href="' . e($o['asaas_invoice_url']) . '">Abrir cobrança Asaas</a>' : '') . '<p class="muted">Quando o Pix for confirmado, o webhook do Asaas aprova o pedido e executa o split automaticamente.</p>'; } else { echo '<p>Pedido não encontrado.</p>'; }
    echo '</section>'; layout_end();
}

function render_plan_checkout_page(): void
{
    $user = layout_start('Pix do plano');
    $stmt = db()->prepare('SELECT * FROM plan_payments WHERE id=? AND user_id=?');
    $stmt->execute([(int)($_GET['id'] ?? 0), $user['id']]);
    $p = $stmt->fetch();
    echo '<section class="checkout panel">';
    if ($p) {
        echo '<h1>Pix do plano</h1><p>' . money((float)$p['valor']) . ' • status ' . e($p['status']) . '</p><div class="qr">' . e((string)$p['pix_qrcode']) . '</div>' . (!empty($p['asaas_invoice_url']) ? '<a class="button" target="_blank" href="' . e($p['asaas_invoice_url']) . '">Abrir cobrança</a>' : '') . '<p class="muted">Seu acesso será renovado automaticamente quando o webhook do gateway confirmar o pagamento.</p>';
    } else {
        echo '<p>Pagamento de plano não encontrado.</p>';
    }
    echo '</section>'; layout_end();
}

function render_tracking_page(): void
{
    $user = layout_start('Acompanhamento do pedido');
    $stmt = db()->prepare('SELECT o.*, p.modelo, p.fotos, vendedor.nome_loja vendedor_loja, comprador.nome_loja comprador_loja FROM orders o JOIN products p ON p.id=o.produto_id JOIN users vendedor ON vendedor.id=o.vendedor_id JOIN users comprador ON comprador.id=o.comprador_id WHERE o.id=? AND (o.comprador_id=? OR o.vendedor_id=?)');
    $stmt->execute([(int)($_GET['id'] ?? 0), $user['id'], $user['id']]);
    $order = $stmt->fetch();
    if (!$order) { echo '<div class="empty">Pedido não encontrado.</div>'; layout_end(); return; }
    $canSeeAddress = $order['status'] !== 'aguardando_pagamento';
    echo '<div class="page-head"><div><span class="eyebrow">Acompanhamento</span><h1>Pedido #' . (int)$order['id'] . ' • ' . e($order['modelo']) . '</h1><p class="page-subtitle">Do Pix gerado até a entrega ao destinatário, cada mudança de status fica registrada.</p></div></div>';
    echo '<section class="panel-grid"><div class="panel premium-panel"><h2>Status atual</h2><span class="chip blue">' . e(status_label($order['status'])) . '</span><p class="price">' . money((float)$order['valor_bruto']) . '</p><p>Pix: ' . e($order['pix_status']) . '</p>' . ($order['codigo_rastreio'] ? '<p>Rastreio: <strong>' . e($order['codigo_rastreio']) . '</strong></p>' : '') . '</div>';
    echo '<div class="panel"><h2>Entrega</h2>';
    if ($canSeeAddress) {
        echo '<p><strong>Destinatário:</strong> ' . e($order['destinatario']) . '</p><p><strong>Telefone:</strong> ' . e($order['telefone_entrega']) . '</p><p><strong>Endereço:</strong> ' . e($order['endereco_entrega']) . ', ' . e($order['complemento_entrega']) . '</p><p>' . e($order['cidade_entrega']) . '/' . e($order['estado_entrega']) . ' • CEP ' . e($order['cep_entrega']) . '</p>';
    } else {
        echo '<p class="muted">Dados completos de entrega ficam protegidos até pagamento aprovado.</p>';
    }
    echo '</div></section><section class="panel tracking-panel"><h2>Linha do tempo</h2><div class="tracking-line">';
    $events = order_events((int)$order['id']);
    if (!$events) { echo '<div><strong>Pedido criado</strong><p>Aguardando primeiro evento.</p></div>'; }
    foreach ($events as $event) {
        echo '<div><span></span><strong>' . e($event['titulo']) . '</strong><p>' . e($event['mensagem']) . '</p><small>' . local_time((string)$event['data']) . '</small></div>';
    }
    echo '</div></section>';
    layout_end();
}

function notifications_list(int $userId): void
{
    $stmt = db()->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY data DESC LIMIT 8');
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();
    if (!$rows) { echo '<p class="muted">Nenhum evento ainda.</p>'; return; }
    echo '<div class="timeline">';
    foreach ($rows as $n) { echo '<div><strong>' . e($n['titulo']) . '</strong><p>' . e($n['mensagem']) . '</p><small>' . local_time((string)$n['data']) . '</small></div>'; }
    echo '</div>';
}

function render_generic_app_page(string $page): void
{
    $user = layout_start(ucfirst($page));
    $pdo = db();
    if ($page === 'offers') {
        $pdo->exec("UPDATE offers o JOIN products p ON p.id=o.produto_id SET o.status='expirada', p.status='disponivel' WHERE o.status='aceita' AND o.expira_em < NOW() AND p.status='reservado'");
        $pdo->exec("UPDATE offers SET status='expirada' WHERE status='pendente' AND cooldown_ate < NOW()");
        [$rows, $total] = paged_query($pdo, 'SELECT o.*, p.modelo, p.metodos_entrega, u.nome_loja comprador_loja, u.score_comprador, u.tempo_medio_pagamento FROM offers o JOIN products p ON p.id=o.produto_id JOIN users u ON u.id=o.comprador_id WHERE o.vendedor_id=? OR o.comprador_id=? ORDER BY o.criada_em DESC', 'SELECT COUNT(*) FROM offers o WHERE o.vendedor_id=? OR o.comprador_id=?', [$user['id'], $user['id']]);
        echo '<div class="page-head"><div><span class="eyebrow">Ofertas</span><h1>Minhas ofertas</h1><p class="page-subtitle">Reservas aceitas duram 30 minutos. Se o comprador não pagar, o aparelho volta para disponível e o cooldown evita spam.</p></div></div><div class="table-wrap"><table><thead><tr><th>Produto</th><th>Valor</th><th>Status</th><th>Comprador</th><th>Ação</th></tr></thead><tbody>';
        foreach ($rows as $o) {
            $isSeller = (int)$o['vendedor_id'] === (int)$user['id'];
            echo '<tr><td>' . e($o['modelo']) . '<small>Criada em ' . e($o['criada_em']) . ($o['expira_em'] ? ' • expira ' . e($o['expira_em']) : '') . '</small></td><td>' . money((float)$o['valor_oferta']) . '</td><td><span class="chip">' . e($o['status']) . '</span></td><td>' . ($isSeller ? e($o['comprador_loja']) . '<small>Score ' . number_format((float)$o['score_comprador'],1,',','.') . ' • paga em ' . e($o['tempo_medio_pagamento']) . '</small>' : 'Você') . '</td><td><div class="action-stack">';
            if ($isSeller && $o['status'] === 'pendente') {
                echo '<form method="post" class="row"><input type="hidden" name="action" value="offer_status"><input type="hidden" name="offer_id" value="' . (int)$o['id'] . '"><button class="button small" name="status" value="aceita">Aceitar</button><button class="ghost small" name="status" value="recusada">Recusar</button></form>';
            } elseif (!$isSeller && $o['status'] === 'aceita') {
                echo '<form method="post" class="offer-pay-form"><input type="hidden" name="action" value="pay_offer"><input type="hidden" name="offer_id" value="' . (int)$o['id'] . '"><select name="metodo_entrega">';
                foreach (array_map('trim', explode(',', $o['metodos_entrega'])) as $method) { echo '<option>' . e($method) . '</option>'; }
                echo '</select>';
                render_delivery_fields($user);
                echo '<label class="check"><input required type="checkbox" name="confirm_delivery" value="1"> Confirmo os dados de entrega</label><button class="button small">Gerar Pix da oferta</button></form>';
            } else {
                echo '<span class="muted">Sem ação pendente</span>';
            }
            echo '</div></td></tr>';
        }
        echo '</tbody></table></div>'; pagination_links($total);
    } elseif ($page === 'sales' || $page === 'purchases') {
        $col = $page === 'sales' ? 'vendedor_id' : 'comprador_id';
        [$rows, $total] = paged_query($pdo, "SELECT o.*, p.modelo, u.nome_loja FROM orders o JOIN products p ON p.id=o.produto_id JOIN users u ON u.id=" . ($page === 'sales' ? 'o.comprador_id' : 'o.vendedor_id') . " WHERE o.$col=? ORDER BY o.data_criacao DESC", "SELECT COUNT(*) FROM orders o WHERE o.$col=?", [$user['id']]);
        echo '<div class="page-head"><div><span class="eyebrow">' . ($page === 'sales' ? 'Minhas vendas' : 'Minhas compras') . '</span><h1>' . ($page === 'sales' ? 'Vendas' : 'Compras') . '</h1></div></div><div class="table-wrap"><table><thead><tr><th>Produto</th><th>Identificação</th><th>Valor</th><th>Taxa</th><th>Status</th><th>Entrega</th><th>Ação</th></tr></thead><tbody>';
        foreach ($rows as $o) {
            $identity = $o['status'] === 'aguardando_pagamento' ? 'Anônimo até pagamento aprovado' : $o['nome_loja'];
            $delivery = e($o['metodo_entrega']);
            if ($page === 'sales' && $o['status'] !== 'aguardando_pagamento') {
                $delivery .= '<small>' . e((string)$o['destinatario']) . ' • ' . e((string)$o['telefone_entrega']) . '</small>';
                $delivery .= '<small>' . e((string)$o['endereco_entrega']) . ($o['complemento_entrega'] ? ', ' . e((string)$o['complemento_entrega']) : '') . '</small>';
                $delivery .= '<small>' . e((string)$o['cidade_entrega']) . '/' . e((string)$o['estado_entrega']) . ' • CEP ' . e((string)$o['cep_entrega']) . '</small>';
            } elseif ($page === 'sales') {
                $delivery .= '<small>Endereço protegido até o Pix ser aprovado.</small>';
            } elseif (!empty($o['codigo_rastreio'])) {
                $delivery .= '<small>Rastreio: ' . e((string)$o['codigo_rastreio']) . '</small>';
            }
            echo '<tr><td>' . e($o['modelo']) . '<small>Pedido #' . (int)$o['id'] . '</small></td><td>' . e($identity) . '</td><td>' . money((float)$o['valor_bruto']) . '</td><td>' . money((float)$o['taxa_plataforma']) . '</td><td><span class="chip">' . e(status_label($o['status'])) . '</span></td><td>' . $delivery . '</td><td><div class="action-stack"><a class="ghost small" href="index.php?p=tracking&id=' . (int)$o['id'] . '">Acompanhar</a>';
            if ($page === 'sales') {
                echo '<form method="post" class="status-actions"><input type="hidden" name="action" value="order_status"><input type="hidden" name="order_id" value="' . (int)$o['id'] . '"><button class="ghost small" name="status" value="preparando_envio">Preparar</button><input name="codigo_rastreio" placeholder="Código de rastreio"><button class="button small" name="status" value="enviado">Enviar</button><button class="ghost small" name="status" value="entregue">Entregue</button><button class="ghost small" name="status" value="finalizado">Finalizar</button><button class="danger small" name="status" value="cancelado">Cancelar</button></form>';
            } elseif (in_array($o['status'], ['entregue','finalizado'], true)) {
                echo '<form method="post" class="review-form"><input type="hidden" name="action" value="review_order"><input type="hidden" name="order_id" value="' . (int)$o['id'] . '"><select name="nota"><option value="5">5</option><option value="4">4</option><option value="3">3</option><option value="2">2</option><option value="1">1</option></select><label><input type="checkbox" name="criterios[]" value="produto conforme"> Produto conforme</label><label><input type="checkbox" name="criterios[]" value="entrega no prazo"> Entrega no prazo</label><button class="button small">Avaliar</button></form>';
            }
            echo '</div></td></tr>';
        }
        echo '</tbody></table></div>'; pagination_links($total);
    } elseif ($page === 'finance') {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(valor_bruto),0) bruto, COALESCE(SUM(taxa_plataforma),0) taxa, COALESCE(SUM(valor_liquido),0) liquido FROM orders WHERE vendedor_id=?');
        $stmt->execute([$user['id']]); $f = $stmt->fetch();
        echo '<div class="page-head"><div><span class="eyebrow">Financeiro</span><h1>Taxas, Pix e repasses</h1></div></div><section class="metric-grid">';
        foreach (['Faturamento bruto'=>(float)$f['bruto'], 'Comissão da plataforma'=>(float)$f['taxa'], 'Valor líquido'=>(float)$f['liquido']] as $k=>$v) { metric_card($k, money($v)); }
        echo '</section><div class="panel"><h2>Gateway Pix</h2><p>Estrutura pronta para Mercado Pago ou outro gateway com QR Code, webhook, split, recibo interno e repasse.</p></div>';
    } elseif ($page === 'notifications') {
        [$rows, $total] = paged_query($pdo, 'SELECT * FROM notifications WHERE user_id=? ORDER BY data DESC', 'SELECT COUNT(*) FROM notifications WHERE user_id=?', [$user['id']]);
        $logsStmt = $pdo->prepare('SELECT * FROM system_logs WHERE user_id=? ORDER BY data DESC LIMIT 30');
        $logsStmt->execute([$user['id']]);
        $logs = $logsStmt->fetchAll();
        echo '<div class="page-head"><div><span class="eyebrow">Logs e notificações</span><h1>Central de auditoria</h1><p class="page-subtitle">Acompanhe aprovações, Pix, ofertas, mudanças de status, duplicidade, perfil e eventos de pedido.</p></div></div><section class="panel-grid"><div class="panel"><h2>Notificações internas</h2><div class="timeline timeline-page">';
        foreach ($rows as $n) { echo '<div><strong>' . e($n['titulo']) . '</strong><p>' . e($n['mensagem']) . '</p><small>' . local_time((string)$n['data']) . '</small></div>'; }
        if (!$rows) { echo '<p class="muted">Nenhuma notificação ainda.</p>'; }
        echo '</div></div><div class="panel"><h2>Logs do sistema</h2><div class="timeline timeline-page">';
        foreach ($logs as $log) { echo '<div><strong>' . e($log['titulo']) . '</strong><p>' . e($log['mensagem']) . '</p><small>' . e($log['tipo']) . ' • ' . local_time((string)$log['data']) . '</small></div>'; }
        if (!$logs) { echo '<p class="muted">Nenhum log registrado ainda.</p>'; }
        echo '</div></div></section>'; pagination_links($total);
    } elseif ($page === 'profile') {
        refresh_user_reputation((int)$user['id']);
        $user = current_user() ?: $user;
        $salesDone = (int)$pdo->query('SELECT COUNT(*) FROM orders WHERE vendedor_id=' . (int)$user['id'] . ' AND status="finalizado"')->fetchColumn();
        $salesOpen = (int)$pdo->query('SELECT COUNT(*) FROM orders WHERE vendedor_id=' . (int)$user['id'] . ' AND status IN ("pagamento_aprovado","preparando_envio","enviado","entregue")')->fetchColumn();
        $purchasesDone = (int)$pdo->query('SELECT COUNT(*) FROM orders WHERE comprador_id=' . (int)$user['id'] . ' AND status IN ("entregue","finalizado")')->fetchColumn();
        $offersPaid = (int)$pdo->query('SELECT COUNT(*) FROM offers WHERE comprador_id=' . (int)$user['id'] . ' AND status="paga"')->fetchColumn();
        $expired = (int)$pdo->query('SELECT COUNT(*) FROM offers WHERE comprador_id=' . (int)$user['id'] . ' AND status="expirada"')->fetchColumn();
        $reviewsCount = (int)$pdo->query('SELECT COUNT(*) FROM reviews WHERE avaliado_id=' . (int)$user['id'])->fetchColumn();
        $badges = [];
        if ((float)$user['score_comprador'] >= 4.7 && $purchasesDone >= 3) { $badges[] = 'Comprador recorrente'; }
        if ((float)$user['score_vendedor'] >= 4.7 && $salesDone >= 3) { $badges[] = 'Vendedor confiável'; }
        if ((float)$user['taxa_cancelamento'] <= 2) { $badges[] = 'Baixo cancelamento'; }
        if ((float)$user['score_comprador'] >= 4.8) { $badges[] = 'Pagamento rápido'; }
        echo '<div class="page-head"><div><span class="eyebrow">Perfil e reputação</span><h1>' . e($user['nome_loja']) . '</h1><p class="page-subtitle">Reputação dupla, score comprador/vendedor, nível e sinais de confiança calculados automaticamente.</p></div><div class="profile-head-actions"><a class="button small" href="index.php?p=edit-profile">Editar perfil</a><div class="level big ' . level_class($user['nivel']) . '">' . e($user['nivel']) . '</div></div></div>';
        echo '<section class="metric-grid">';
        foreach (['Nota geral'=>number_format((float)$user['nota_geral'],1,',','.'), 'Score comprador'=>number_format((float)$user['score_comprador'],1,',','.'), 'Score vendedor'=>number_format((float)$user['score_vendedor'],1,',','.'), 'Cancelamento'=>number_format((float)$user['taxa_cancelamento'],1,',','.').'%', 'Vendas finalizadas'=>(string)$salesDone, 'Compras finalizadas'=>(string)$purchasesDone, 'Ofertas pagas'=>(string)$offersPaid, 'Reservas expiradas'=>(string)$expired] as $k=>$v) { metric_card($k, $v); }
        echo '</section><section class="panel-grid"><div class="panel premium-panel"><h2>Selos e nível</h2><div class="badge-row">';
        $planColor = '#6b7280'; $planName = $user['plano'] ?? 'Free';
        if ($planName === 'Pro') $planColor = 'var(--blue)';
        if ($planName === 'Elite') $planColor = '#F59E0B';
        echo '<span class="chip" style="background: '.$planColor.'; border-color: '.$planColor.'; color: white;"><i class="ph ph-crown" style="margin-right:4px;"></i> '.e($planName).'</span>';
        foreach ($badges ?: ['Primeiras negociações'] as $badge) { echo '<span class="chip blue">' . e($badge) . '</span>'; }
        echo '</div><p>O nível considera vendas, compras, avaliações, taxa de cancelamento, reservas expiradas e consistência de pagamento.</p><div class="progress"><span style="width:' . min(100, max(8, (float)$user['nota_geral'] * 20)) . '%"></span></div></div><div class="panel"><h2>Privacidade</h2><p>Nome da loja, endereço, telefone, Instagram, CPF, CNPJ e Serial Number ficam protegidos antes do pagamento aprovado.</p><p>Dados de entrega só são liberados depois do Pix confirmado.</p></div></section>';
        echo '<section class="panel-grid"><div class="panel"><h2>Score vendedor</h2><p>Vendas abertas: <strong>' . $salesOpen . '</strong></p><p>Tempo médio de envio: <strong>' . e($user['tempo_medio_envio']) . '</strong></p><p>Avaliações recebidas: <strong>' . $reviewsCount . '</strong></p></div><div class="panel"><h2>Score comprador</h2><p>Tempo médio de pagamento: <strong>' . e($user['tempo_medio_pagamento']) . '</strong></p><p>Reservas expiradas: <strong>' . $expired . '</strong></p><p>Compras concluídas: <strong>' . $purchasesDone . '</strong></p></div></section>';
    } elseif ($page === 'plans') {
        $plans = $pdo->query('SELECT * FROM plans WHERE ativo=1 ORDER BY preco_mensal ASC, id ASC')->fetchAll();
        $state = subscription_state($user);
        echo '<div class="page-head"><div><span class="eyebrow">Planos</span><h1>Escolha seu nível LOJIST</h1><p class="page-subtitle">Todo lojista aprovado ganha 1 mês de teste. Depois, escolha entre Free, Pro, Elite ou um plano parceiro especial liberado pelo admin.</p></div></div><section class="metric-grid">';
        metric_card('Status', $state['label'], $state['ends_at'] ? 'Válido até ' . strip_tags(local_time((string)$state['ends_at'])) : 'Sem vencimento');
        metric_card('Plano atual', (string)$user['plano']);
        metric_card('Dias restantes', $state['days_left'] === null ? '-' : (string)$state['days_left']);
        metric_card('Conta', (string)$user['status_conta'], 'Acesso fechado para lojistas aprovados');
        echo '</section><section class="plan-grid">';
        foreach ($plans as $p) {
            $monthly = (float)$p['preco_mensal'];
            $subtitle = $monthly > 0 ? 'R$ ' . number_format($monthly, 2, ',', '.') . '/mês + ' . number_format((float)$p['taxa'], 1, ',', '.') . '% por venda' : number_format((float)$p['taxa'], 1, ',', '.') . '% por venda';
            $limit = $p['limite_anuncios'] === null ? 'anúncios ilimitados' : 'até ' . (int)$p['limite_anuncios'] . ' anúncios ativos';
            $current = (string)$user['plano'] === (string)$p['nome'];
            echo '<div class="plan ' . ($current ? 'popular' : '') . '"><h2>' . e($p['nome']) . '</h2><strong>' . e($subtitle) . '</strong><p>' . e($limit) . '</p><ul><li>Compra e venda entre lojistas aprovados</li><li>' . ((int)$p['filtros_avancados'] ? 'Filtros avançados por cidade, estado e região' : 'Busca padrão por cidade e filtros básicos') . '</li><li>PDV, ofertas, reputação e análise de mercado</li><li>Pix com gateway e repasse protegido</li></ul><form method="post"><input type="hidden" name="action" value="pay_plan"><input type="hidden" name="plan_id" value="' . (int)$p['id'] . '"><button class="button full">' . ($monthly > 0 ? ($current ? 'Renovar plano' : 'Assinar plano') : 'Usar Free') . '</button></form><p class="muted">' . ((int)($p['especial'] ?? 0) ? 'Plano parceiro especial configurado pelo admin.' : 'Taxas editáveis pelo admin master.') . '</p></div>';
        }
        echo '</section>';
    }
    layout_end();
}

function render_edit_profile_page(): void
{
    $user = layout_start('Editar perfil');
    ?>
    <div class="page-head"><div><span class="eyebrow">Perfil</span><h1>Editar dados de envio e retirada</h1><p class="page-subtitle">Esses dados precisam ser confirmados sempre que você vender ou comprar um aparelho.</p></div></div>
    <form method="post" class="panel form-grid"><input type="hidden" name="action" value="update_profile">
        <label class="full">Endereço comercial para retirada<input required name="endereco_completo" value="<?= e((string)$user['endereco_completo']) ?>" placeholder="Endereço completo da loja"></label>
        <label>Nome do destinatário padrão<input required name="entrega_nome" value="<?= e($user['entrega_nome'] ?: $user['nome'] . ' ' . $user['sobrenome']) ?>"></label>
        <label>Telefone para entrega<input required name="entrega_telefone" value="<?= e($user['entrega_telefone'] ?: $user['telefone']) ?>"></label>
        <label>CEP de entrega<input required name="entrega_cep" value="<?= e((string)$user['entrega_cep']) ?>"></label>
        <label class="full">Endereço para receber aparelhos<input required name="entrega_endereco" value="<?= e((string)$user['entrega_endereco']) ?>"></label>
        <label>Cidade<input required name="entrega_cidade" value="<?= e($user['entrega_cidade'] ?: $user['cidade']) ?>"></label>
        <label>Estado<input required maxlength="2" name="entrega_estado" value="<?= e($user['entrega_estado'] ?: $user['estado']) ?>"></label>
        <label>Complemento ou referência<input name="entrega_complemento" value="<?= e((string)$user['entrega_complemento']) ?>"></label>
        <label class="full">Instruções de retirada para vendas<textarea name="retirada_instrucao" placeholder="Ex.: retirada no balcão, horário comercial, peça pelo responsável"><?= e((string)$user['retirada_instrucao']) ?></textarea></label>
        <button class="button full">Salvar perfil</button>
    </form>
    <?php
    layout_end();
}

function render_admin(): void
{
    $adminUser = layout_start('Admin master');
    $pdo = db();
    $tab = $_GET['tab'] ?? 'dashboard';
    echo '<div class="page-head"><div><span class="eyebrow">Admin master</span><h1>Controle completo LOJIST</h1></div></div>';
    if ($tab === 'dashboard') {
        $expected = (float)$pdo->query("SELECT COALESCE(SUM(preco * quantidade),0) FROM products WHERE status IN ('disponivel','reservado','aguardando_pagamento','pagamento_aprovado','preparando_envio','enviado')")->fetchColumn();
        $real = (float)$pdo->query("SELECT COALESCE(SUM(valor_bruto),0) FROM orders WHERE status IN ('pagamento_aprovado','preparando_envio','enviado','entregue','finalizado')")->fetchColumn();
        $profit = (float)$pdo->query("SELECT COALESCE(SUM(taxa_plataforma),0) FROM orders WHERE status IN ('pagamento_aprovado','preparando_envio','enviado','entregue','finalizado')")->fetchColumn();
        echo '<section class="metric-grid">';
        metric_card('GMV esperado', money($expected), '', 'ph-trend-up');
        metric_card('GMV real', money($real), '', 'ph-currency-dollar');
        metric_card('Lucro da plataforma', money($profit), '', 'ph-chart-line-up');
        metric_card('Lojistas aguardando', (string)$pdo->query("SELECT COUNT(*) FROM users WHERE status_conta='aguardando_aprovacao'")->fetchColumn(), '', 'ph-users');
        metric_card('Anúncios ativos', (string)$pdo->query("SELECT COUNT(*) FROM products WHERE status='disponivel'")->fetchColumn(), '', 'ph-device-mobile');
        metric_card('Ticket médio real', money((float)$pdo->query('SELECT COALESCE(AVG(valor_bruto),0) FROM orders WHERE status IN ("pagamento_aprovado","preparando_envio","enviado","entregue","finalizado")')->fetchColumn()), '', 'ph-receipt');
        echo '</section>';
        echo '<div class="chart-container-premium animate-entry"><div class="chart-header"><span class="chart-title"><i class="ph ph-chart-polar" style="color: var(--blue-2); font-size: 1.5rem;"></i> Visão Geral Financeira</span><span class="chip" style="background: rgba(0, 102, 255, 0.1); border-color: var(--blue); color: var(--blue-2);">Plataforma</span></div><div style="height: 250px; width: 100%;"><canvas id="adminChart"></canvas></div></div>';
        echo '<div class="panel premium-panel animate-entry" style="margin-top: 32px;"><h2>Antifraude e anti-desintermediação</h2><p>Sem chat, sem descrição livre, bloqueio de contato externo, Serial Number interno, watermark e dados sensíveis liberados somente após pagamento aprovado.</p></div>';
    } elseif ($tab === 'approvals' || $tab === 'users') {
        $where = $tab === 'approvals' ? "WHERE role='lojista' AND status_conta='aguardando_aprovacao'" : "WHERE role='lojista' AND status_conta='aprovado'";
        [$rows, $total] = paged_query($pdo, "SELECT * FROM users $where ORDER BY " . ($tab === 'users' ? 'aprovado_em DESC, data_cadastro DESC' : 'data_cadastro DESC'), "SELECT COUNT(*) FROM users $where");
        echo '<div class="table-wrap"><table><thead><tr><th>Lojista</th><th>Cidade</th><th>Status</th><th>Plano</th><th>Gateway</th><th>Reputação</th><th>Ações</th></tr></thead><tbody>';
        foreach ($rows as $u) { echo '<tr><td>' . e($u['nome_loja']) . '<small>' . e($u['nome'].' '.$u['sobrenome']) . ' • CPF ' . e($u['cpf']) . ' • ' . e($u['email']) . '</small></td><td>' . e($u['cidade'].'/'.$u['estado']) . '</td><td><span class="chip">' . e($u['status_conta']) . '</span></td><td>' . e($u['plano']) . '</td><td><form method="post" class="inline-form"><input type="hidden" name="action" value="admin_user_wallet"><input type="hidden" name="user_id" value="' . (int)$u['id'] . '"><input name="asaas_wallet_id" value="' . e((string)($u['asaas_wallet_id'] ?? '')) . '" placeholder="walletId Asaas"><button class="ghost small">Salvar</button></form><small>' . (!empty($u['asaas_wallet_id']) ? 'Split Asaas configurado' : 'Sem split Asaas') . '</small></td><td>' . number_format((float)$u['nota_geral'],1,',','.') . '</td><td><div class="action-stack"><form method="post" class="row"><input type="hidden" name="action" value="admin_user_status"><input type="hidden" name="user_id" value="' . (int)$u['id'] . '"><button class="button small" name="status" value="aprovado">Aprovar</button><button class="ghost small" name="status" value="recusado">Recusar</button><button class="ghost small" name="status" value="suspenso">Suspender</button><button class="danger small" name="status" value="banido">Banir</button></form>' . ($u['status_conta'] === 'banido' ? '<form method="post"><input type="hidden" name="action" value="admin_delete_user"><input type="hidden" name="user_id" value="' . (int)$u['id'] . '"><button class="danger small">Excluir cadastro</button></form>' : '') . '</div></td></tr>'; }
        echo '</tbody></table></div>'; pagination_links($total);
    } elseif ($tab === 'products') {
        [$rows, $total] = paged_query($pdo, "SELECT p.*, u.nome_loja FROM products p JOIN users u ON u.id=p.vendedor_id WHERE p.status != 'cancelado' ORDER BY p.data_criacao DESC", "SELECT COUNT(*) FROM products WHERE status != 'cancelado'");
        echo '<div class="table-wrap"><table><thead><tr><th>Anúncio</th><th>Vendedor</th><th>Status</th><th>Serial interno</th><th>Preço</th><th>Ação</th></tr></thead><tbody>';
        foreach ($rows as $p) { echo '<tr><td>' . e($p['modelo']) . '<small>' . e($p['detalhes_estruturados']) . '</small></td><td>' . e($p['nome_loja']) . '</td><td><span class="chip">' . e($p['status']) . '</span></td><td>' . e($p['serial_number'] ?: ($p['imei_interno'] ?: 'Não aplicável')) . '</td><td>' . money((float)$p['preco']) . '</td><td><form method="post"><input type="hidden" name="action" value="admin_delete_product"><input type="hidden" name="product_id" value="' . (int)$p['id'] . '"><button class="danger small">Excluir</button></form></td></tr>'; }
        echo '</tbody></table></div>'; pagination_links($total);
    } elseif ($tab === 'sales') {
        [$rows, $total] = paged_query($pdo, 'SELECT * FROM orders ORDER BY data_criacao DESC', 'SELECT COUNT(*) FROM orders');
        echo '<div class="table-wrap"><table><thead><tr><th>Pedido</th><th>Status</th><th>Pix</th><th>Taxas</th><th>Repasse</th><th>Data</th><th>Ação</th></tr></thead><tbody>';
        foreach ($rows as $o) { echo '<tr><td>#' . (int)$o['id'] . '</td><td><span class="chip">' . e($o['status']) . '</span></td><td>' . e($o['pix_status']) . '</td><td>' . money((float)$o['taxa_plataforma']) . '</td><td>' . e((string)($o['repasse_status'] ?? 'retido')) . (!empty($o['repasse_liberado_em']) ? '<small>Liberar: ' . local_time((string)$o['repasse_liberado_em']) . '</small>' : '') . '</td><td>' . local_time((string)$o['data_criacao']) . '</td><td><form method="post"><input type="hidden" name="action" value="admin_delete_order"><input type="hidden" name="order_id" value="' . (int)$o['id'] . '"><button class="danger small">Excluir</button></form></td></tr>'; }
        echo '</tbody></table></div>'; pagination_links($total);
    } elseif ($tab === 'disputes') {
        echo '<div class="panel"><h2>Disputas e suporte</h2><p>Base pronta para mediação: comprador abriu problema, vendedor abriu problema, atraso, produto não entregue e cancelamentos. As disputas serão paginadas aqui quando houver registros.</p></div>';
    } elseif ($tab === 'logs') {
        [$rows, $total] = paged_query($pdo, 'SELECT l.*, u.nome_loja FROM system_logs l LEFT JOIN users u ON u.id=l.user_id ORDER BY l.data DESC', 'SELECT COUNT(*) FROM system_logs');
        echo '<div class="table-wrap"><table><thead><tr><th>Data</th><th>Tipo</th><th>Lojista</th><th>Evento</th><th>IP</th></tr></thead><tbody>';
        foreach ($rows as $log) { echo '<tr><td>' . local_time((string)$log['data']) . '</td><td><span class="chip">' . e($log['tipo']) . '</span></td><td>' . e($log['nome_loja'] ?: 'Sistema') . '</td><td><strong>' . e($log['titulo']) . '</strong><small>' . e($log['mensagem']) . '</small></td><td>' . e($log['ip'] ?: '-') . '</td></tr>'; }
        echo '</tbody></table></div>'; pagination_links($total);
    } elseif ($tab === 'reports') {
        $topCity = $pdo->query('SELECT CONCAT(cidade_entrega,"/",estado_entrega) cidade, COUNT(*) total FROM orders WHERE cidade_entrega IS NOT NULL GROUP BY cidade_entrega, estado_entrega ORDER BY total DESC LIMIT 1')->fetch();
        $topBrand = $pdo->query('SELECT p.categoria, COUNT(*) total FROM orders o JOIN products p ON p.id=o.produto_id GROUP BY p.categoria ORDER BY total DESC LIMIT 1')->fetch();
        echo '<section class="metric-grid">';
        foreach (['Vendas por cidade'=>($topCity['cidade'] ?? 'Sem vendas'), 'Marca líder'=>($topBrand['categoria'] ?? 'Sem vendas'), 'Lojistas ativos'=>(string)$pdo->query("SELECT COUNT(*) FROM users WHERE role='lojista' AND status_conta='aprovado'")->fetchColumn(), 'Cancelamentos'=>(string)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='cancelado'")->fetchColumn(), 'Ofertas abertas'=>(string)$pdo->query("SELECT COUNT(*) FROM offers WHERE status='pendente'")->fetchColumn(), 'Receita mensal'=>money((float)$pdo->query("SELECT COALESCE(SUM(taxa_plataforma),0) FROM orders WHERE data_criacao >= DATE_FORMAT(NOW(), '%Y-%m-01')")->fetchColumn())] as $k=>$v) { metric_card($k, $v); }
        echo '</section><div class="panel"><h2>Como usar relatórios</h2><p>Esta aba resume liquidez real: cidades com maior giro, marcas que mais vendem, receita da plataforma, cancelamentos e ofertas abertas. Conforme as vendas reais aumentarem, ela vira o painel de decisão para campanhas, planos parceiros e expansão por cidade.</p></div>';
    } elseif ($tab === 'plans') {
        $plans = $pdo->query('SELECT * FROM plans ORDER BY id')->fetchAll();
        echo '<form method="post" class="panel"><input type="hidden" name="action" value="admin_plan"><div class="table-wrap"><table><thead><tr><th>Plano</th><th>Taxa %</th><th>Limite ativos</th><th>Filtros avançados</th><th>Mensalidade</th><th>Ativo</th></tr></thead><tbody>';
        foreach ($plans as $p) { echo '<tr><td>' . e($p['nome']) . ((int)($p['especial'] ?? 0) ? '<small>Especial parceiro</small>' : '') . '</td><td><input name="plans[' . (int)$p['id'] . '][taxa]" value="' . e((string)$p['taxa']) . '"></td><td><input name="plans[' . (int)$p['id'] . '][limite_anuncios]" value="' . e((string)$p['limite_anuncios']) . '" placeholder="Ilimitado"></td><td><input type="checkbox" name="plans[' . (int)$p['id'] . '][filtros_avancados]" ' . ((int)$p['filtros_avancados'] ? 'checked' : '') . '></td><td><input name="plans[' . (int)$p['id'] . '][preco_mensal]" value="' . e((string)$p['preco_mensal']) . '"></td><td><input type="checkbox" name="plans[' . (int)$p['id'] . '][ativo]" ' . ((int)$p['ativo'] ? 'checked' : '') . '></td></tr>'; }
        echo '</tbody></table></div><button class="button">Salvar planos</button></form><form method="post" class="panel form-grid"><input type="hidden" name="action" value="admin_add_plan"><h2 class="full">Adicionar plano especial parceiro</h2><label>Nome<input required name="nome" placeholder="Parceiro Diamond CG"></label><label>Taxa %<input required name="taxa" type="number" step="0.01" value="0.9"></label><label>Mensalidade<input required name="preco_mensal" type="number" step="0.01" value="149.90"></label><label>Limite anúncios<input name="limite_anuncios" placeholder="Ilimitado"></label><label class="check"><input type="checkbox" name="filtros_avancados" checked> Filtros avançados</label><button class="button full">Criar plano especial</button></form>';
    } elseif ($tab === 'settings') {
        $counts = [
            'Usuários que serão removidos' => (int)$pdo->query('SELECT COUNT(*) FROM users WHERE id <> ' . (int)$adminUser['id'])->fetchColumn(),
            'Anúncios' => (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn(),
            'Pedidos/vendas' => (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
            'Ofertas' => (int)$pdo->query('SELECT COUNT(*) FROM offers')->fetchColumn(),
            'Pagamentos' => (int)$pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn(),
            'Notificações e logs' => (int)$pdo->query('SELECT (SELECT COUNT(*) FROM notifications) + (SELECT COUNT(*) FROM system_logs)')->fetchColumn(),
        ];
        echo '<section class="metric-grid">';
        foreach ($counts as $k => $v) { metric_card($k, (string)$v); }
        echo '</section><section class="panel danger-zone"><h2>Limpar banco de dados</h2><p>Esta ação remove todos os lojistas, anúncios, pedidos, vendas, ofertas, pagamentos, avaliações, disputas, notificações, logs e tokens de recuperação. Apenas o usuário admin logado será preservado.</p><p><strong>Esta ação não pode ser desfeita pelo sistema.</strong> Faça backup no phpMyAdmin antes de confirmar.</p><form method="post" class="form-grid"><input type="hidden" name="action" value="admin_reset_platform"><label class="full">Digite LIMPAR LOJIST para confirmar<input required name="confirmacao" autocomplete="off" placeholder="LIMPAR LOJIST"></label><button class="danger full">Limpar banco e manter apenas admin</button></form></section>';
    }
    layout_end();
}


