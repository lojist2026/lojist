<?php
declare(strict_types=1);

function check_price_alerts(PDO $pdo, string $modelo, float $preco, int $produtoId): void
{
    $stmt = $pdo->prepare('SELECT * FROM price_alerts WHERE status="ativo" AND modelo=? AND valor_desejado >= ?');
    $stmt->execute([$modelo, $preco]);
    $alerts = $stmt->fetchAll();

    foreach ($alerts as $a) {
        $msg = "Um aparelho {$modelo} foi anunciado por R$ " . number_format($preco, 2, ',', '.') . ", que está dentro do seu alvo de alerta!";
        add_notification((int)$a['user_id'], 'alerta_preco', 'Alerta de Preço Disparado', $msg);
        
        $pdo->prepare('UPDATE price_alerts SET status="disparado" WHERE id=?')->execute([(int)$a['id']]);
    }
}

function features_handle_action(PDO $pdo, string $action): void
{
    $user = current_user();
    if (!$user) return;

    if ($action === 'search_market') {
        if (!in_array($user['plano'], ['Pro', 'Elite'])) {
            $_SESSION['flash'] = 'Pesquisa de mercado disponível apenas para planos Pro e Elite.';
            redirect('plans');
        }
        $marca = trim((string)post('marca'));
        $modelo = trim((string)post('modelo'));
        $armazenamento = trim((string)post('armazenamento'));
        $estado = trim((string)post('estado'));
        $valor = (float)post('valor_pago');

        $pdo->prepare('INSERT INTO market_research_queries (user_id, marca, modelo, armazenamento, estado_aparelho, valor_pago) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$user['id'], $marca, $modelo, $armazenamento, $estado, $valor]);

        $_SESSION['flash'] = 'Pesquisa realizada com sucesso!';
        redirect('market-research', [
            'q' => 1,
            'marca' => $marca,
            'modelo' => $modelo,
            'armazenamento' => $armazenamento,
            'estado' => $estado,
            'valor_pago' => $valor
        ]);
    }

    if ($action === 'create_price_alert') {
        if ($user['plano'] !== 'Elite') {
            $_SESSION['flash'] = 'Alertas de Preço é exclusivo do plano Elite.';
            redirect('plans');
        }
        $modelo = trim((string)post('modelo'));
        $valor = (float)post('valor_desejado');

        $pdo->prepare('INSERT INTO price_alerts (user_id, modelo, valor_desejado) VALUES (?, ?, ?)')
            ->execute([$user['id'], $modelo, $valor]);

        $_SESSION['flash'] = 'Alerta de preço criado com sucesso!';
        redirect('price-alerts');
    }
    
    if ($action === 'delete_price_alert') {
        $pdo->prepare('DELETE FROM price_alerts WHERE id=? AND user_id=?')->execute([(int)post('id'), $user['id']]);
        $_SESSION['flash'] = 'Alerta removido.';
        redirect('price-alerts');
    }

    if ($action === 'inventory_add') {
        if ($user['plano'] !== 'Elite') {
            $_SESSION['flash'] = 'O módulo PDV / Estoque é exclusivo do plano Elite.';
            redirect('plans');
        }
        $marca = trim((string)post('marca'));
        $modelo = trim((string)post('modelo'));
        $imei = trim((string)post('imei'));
        $cor = trim((string)post('cor'));
        $armazenamento = trim((string)post('armazenamento'));
        $valor_custo = (float)post('valor_custo');
        $valor_venda = (float)post('valor_venda');
        $quantidade = (int)post('quantidade');

        $pdo->prepare('INSERT INTO inventory (user_id, marca, modelo, imei, cor, armazenamento, valor_custo, valor_venda, quantidade) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$user['id'], $marca, $modelo, $imei, $cor, $armazenamento, $valor_custo, $valor_venda, $quantidade]);
        
        $inventoryId = $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO inventory_movements (inventory_id, tipo, quantidade) VALUES (?, "entrada", ?)')
            ->execute([$inventoryId, $quantidade]);

        $_SESSION['flash'] = 'Aparelho adicionado ao estoque.';
        redirect('inventory');
    }

    if ($action === 'inventory_sell') {
        if ($user['plano'] !== 'Elite') return;
        
        $inventoryId = (int)post('inventory_id');
        $cliente = trim((string)post('cliente_nome'));
        $valor = (float)post('valor_venda');
        $quantidade = (int)post('quantidade');

        // Verify stock
        $stmt = $pdo->prepare('SELECT quantidade FROM inventory WHERE id=? AND user_id=?');
        $stmt->execute([$inventoryId, $user['id']]);
        $current = (int)$stmt->fetchColumn();

        if ($current < $quantidade) {
            $_SESSION['flash'] = 'Quantidade insuficiente em estoque.';
            redirect('inventory');
        }

        $pdo->prepare('UPDATE inventory SET quantidade = quantidade - ? WHERE id=? AND user_id=?')
            ->execute([$quantidade, $inventoryId, $user['id']]);

        $pdo->prepare('INSERT INTO inventory_movements (inventory_id, tipo, quantidade) VALUES (?, "saida", ?)')
            ->execute([$inventoryId, $quantidade]);

        $pdo->prepare('INSERT INTO sales (user_id, inventory_id, cliente_nome, valor_venda, quantidade) VALUES (?, ?, ?, ?, ?)')
            ->execute([$user['id'], $inventoryId, $cliente, $valor, $quantidade]);

        $_SESSION['flash'] = 'Venda registrada e estoque atualizado com sucesso!';
        redirect('inventory');
    }

    if ($action === 'subscribe_plan') {
        $plano = trim((string)post('plano'));
        $metodo = trim((string)post('metodo'));
        $valor = $plano === 'Elite' ? 199.00 : 99.00;

        $nextMonth = date('Y-m-d H:i:s', strtotime('+1 month'));
        $pdo->prepare('INSERT INTO subscriptions (user_id, plano, metodo, data_renovacao, proxima_cobranca, status) VALUES (?, ?, ?, NOW(), ?, "ativo")')
            ->execute([$user['id'], $plano, $metodo, $nextMonth]);
        $subId = $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO subscription_payments (subscription_id, valor, status) VALUES (?, ?, "pago")')
            ->execute([$subId, $valor]);

        $pdo->prepare('UPDATE users SET plano=? WHERE id=?')->execute([$plano, $user['id']]);
        
        $_SESSION['flash'] = 'Assinatura concluída com sucesso! Seu plano agora é ' . $plano . '.';
        redirect('plans');
    }

    if ($action === 'simulate_overdue') {
        $pdo->prepare('UPDATE subscriptions SET status="atrasado", proxima_cobranca=DATE_SUB(NOW(), INTERVAL 3 DAY) WHERE user_id=? AND status="ativo" ORDER BY id DESC LIMIT 1')->execute([$user['id']]);
        $_SESSION['flash'] = 'Atraso de 3 dias simulado. Recarregue a página para ver o rebaixamento automático.';
        redirect('plans');
    }

    if ($action === 'cancel_subscription') {
        $pdo->prepare('UPDATE subscriptions SET status="cancelado" WHERE user_id=? AND status="ativo" ORDER BY id DESC LIMIT 1')->execute([$user['id']]);
        $pdo->prepare('UPDATE users SET plano="Free" WHERE id=?')->execute([$user['id']]);
        $_SESSION['flash'] = 'Assinatura cancelada e plano rebaixado para Free.';
        redirect('plans');
    }

    if ($action === 'pay_overdue') {
        $sub = $pdo->prepare('SELECT * FROM subscriptions WHERE user_id=? AND status="atrasado" ORDER BY id DESC LIMIT 1');
        $sub->execute([$user['id']]);
        $subscription = $sub->fetch();
        if ($subscription) {
            $valor = $subscription['plano'] === 'Elite' ? 199.00 : 99.00;
            $pdo->prepare('INSERT INTO subscription_payments (subscription_id, valor, status) VALUES (?, ?, "pago")')
                ->execute([$subscription['id'], $valor]);
            $nextMonth = date('Y-m-d H:i:s', strtotime('+1 month'));
            $pdo->prepare('UPDATE subscriptions SET status="ativo", proxima_cobranca=? WHERE id=?')->execute([$nextMonth, $subscription['id']]);
            $_SESSION['flash'] = 'Fatura paga. Assinatura reativada com sucesso!';
        }
        redirect('plans');
    }

    if ($action === 'simulate_trade') {
        if ($user['plano'] !== 'Elite') {
            $_SESSION['flash'] = 'A Simulação de Troca é exclusiva do plano Elite.';
            redirect('plans');
        }
        $sale_value = (float)post('product_sale_value');
        $brand = trim((string)post('brand'));
        $model = trim((string)post('model'));
        $storage = trim((string)post('storage'));
        $condition = post('condition');
        $manual_value = post('manual_market_value') !== '' ? (float)post('manual_market_value') : null;
        
        $factors = ['excelente' => 1.0, 'bom' => 0.9, 'regular' => 0.75, 'ruim' => 0.6];
        $factor = $factors[$condition] ?? 1.0;
        
        $stmt = $pdo->prepare('SELECT * FROM device_market_prices WHERE brand=? AND model=? AND storage=? AND active=1 LIMIT 1');
        $stmt->execute([$brand, $model, $storage]);
        $device = $stmt->fetch();
        
        if ($device) {
            $market_value = (float)$device['average_market_value'];
            $condition_value = $market_value * $factor;
            $complement_value = max(0.0, $sale_value - $condition_value);
            
            $ins = $pdo->prepare('INSERT INTO trade_simulations (user_id, device_market_price_id, brand, model, storage, product_sale_value, market_value, condition_factor, condition_value, complement_value) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $ins->execute([$user['id'], $device['id'], $brand, $model, $storage, $sale_value, $market_value, $condition, $condition_value, $complement_value]);
            
            redirect('trade-simulation', [
                'simulated' => 1,
                'sale_value' => $sale_value,
                'brand' => $brand,
                'model' => $model,
                'storage' => $storage,
                'condition' => $condition,
                'market_value' => $market_value,
                'condition_value' => $condition_value,
                'complement_value' => $complement_value,
                'found' => 1
            ]);
        } else {
            if ($manual_value !== null) {
                $condition_value = $manual_value * $factor;
                $complement_value = max(0.0, $sale_value - $condition_value);
                
                $ins = $pdo->prepare('INSERT INTO trade_simulations (user_id, device_market_price_id, brand, model, storage, product_sale_value, market_value, condition_factor, condition_value, complement_value) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?)');
                $ins->execute([$user['id'], $brand, $model, $storage, $sale_value, $manual_value, $condition, $condition_value, $complement_value]);
                
                redirect('trade-simulation', [
                    'simulated' => 1,
                    'sale_value' => $sale_value,
                    'brand' => $brand,
                    'model' => $model,
                    'storage' => $storage,
                    'condition' => $condition,
                    'market_value' => $manual_value,
                    'condition_value' => $condition_value,
                    'complement_value' => $complement_value,
                    'found' => 0
                ]);
            } else {
                redirect('trade-simulation', [
                    'not_found' => 1,
                    'sale_value' => $sale_value,
                    'brand' => $brand,
                    'model' => $model,
                    'storage' => $storage,
                    'condition' => $condition
                ]);
            }
        }
    }

    if ($action === 'suggest_device_price') {
        if ($user['plano'] !== 'Elite') {
            $_SESSION['flash'] = 'Apenas lojistas Elite podem sugerir aparelhos.';
            redirect('plans');
        }
        $brand = trim((string)post('brand'));
        $model = trim((string)post('model'));
        $storage = trim((string)post('storage'));
        
        if ($brand !== '' && $model !== '' && $storage !== '') {
            $stmt = $pdo->prepare('INSERT INTO device_market_suggestions (user_id, brand, model, storage) VALUES (?, ?, ?, ?)');
            $stmt->execute([$user['id'], $brand, $model, $storage]);
            $_SESSION['flash'] = 'Obrigado! Sua sugestão de inclusão foi enviada aos administradores.';
        } else {
            $_SESSION['flash'] = 'Preencha todos os campos da sugestão.';
        }
        redirect('trade-simulation');
    }
}

function render_market_research_page() {
    $user = layout_start('Pesquisa de Mercado');
    if (!in_array($user['plano'], ['Pro', 'Elite'])) {
        echo '<div class="panel text-center" style="padding: 4rem 2rem;">
            <i class="ph ph-lock-key" style="font-size: 3rem; color: var(--blue); margin-bottom: 1rem; opacity: 0.8;"></i>
            <h2>Recurso Exclusivo</h2>
            <p style="color: #A0AEC0; margin-bottom: 2rem;">A Pesquisa de Mercado está disponível apenas para lojistas Pro e Elite.</p>
            <a href="index.php?p=plans" class="button">Ver Planos</a>
        </div>';
        layout_end(); return;
    }

    echo '<div class="page-head">
        <div><span class="eyebrow">Inteligência de Vendas</span><h1>Pesquisa de Mercado</h1><p class="page-subtitle">Calcule o lucro estimado e veja preços praticados em tempo real na plataforma.</p></div>
    </div>';

    echo '<section class="panel form-grid" style="margin-bottom: 2rem;">
        <h2 class="full">Calcular margem e preços</h2>
        <form method="post" class="form-grid full" style="margin: 0; padding: 0; border: none; box-shadow: none;">
            <input type="hidden" name="action" value="search_market">
            <label>Marca <select name="marca" required><option value="Apple">Apple</option><option value="Samsung">Samsung</option><option value="Xiaomi">Xiaomi</option><option value="Motorola">Motorola</option></select></label>
            <label>Modelo (Ex: iPhone 13 Pro) <input type="text" name="modelo" required value="'.e((string)($_GET['modelo'] ?? '')).'"></label>
            <label>Armazenamento <input type="text" name="armazenamento" required placeholder="128GB" value="'.e((string)($_GET['armazenamento'] ?? '')).'"></label>
            <label>Estado do aparelho <select name="estado" required><option value="Seminovo">Seminovo</option><option value="Lacrado">Lacrado</option></select></label>
            <label>Valor pago na troca (R$) <input type="number" step="0.01" name="valor_pago" required value="'.e((string)($_GET['valor_pago'] ?? '')).'"></label>
            <button class="button full" style="margin-top: 1.5rem;">Calcular e Pesquisar</button>
        </form>
    </section>';

    if (!empty($_GET['q'])) {
        $pdo = db();
        $modelo = trim((string)$_GET['modelo']);
        $armazenamento = trim((string)$_GET['armazenamento']);
        $valorPago = (float)$_GET['valor_pago'];

        $stmt = $pdo->prepare('SELECT MIN(preco) as min_p, MAX(preco) as max_p, AVG(preco) as avg_p, COUNT(*) as qtd FROM products WHERE modelo LIKE ? AND armazenamento LIKE ? AND status="disponivel"');
        $stmt->execute(['%'.$modelo.'%', '%'.$armazenamento.'%']);
        $stats = $stmt->fetch();

        $precoMedio = $stats['qtd'] > 0 ? (float)$stats['avg_p'] : 0;
        $lucroStr = 'N/A';
        $margemStr = 'N/A';

        if ($precoMedio > 0) {
            $lucro = $precoMedio - $valorPago;
            $margem = $valorPago > 0 ? ($lucro / $precoMedio) * 100 : 100;
            $lucroStr = money($lucro);
            $margemStr = number_format($margem, 1, ',', '.') . '%';
        }

        echo '<section class="metric-grid">';
        metric_card('Preço Médio', $stats['qtd'] > 0 ? money($precoMedio) : 'Sem dados', '', 'ph-scales');
        metric_card('Menor Anúncio', $stats['qtd'] > 0 ? money((float)$stats['min_p']) : '-', '', 'ph-trend-down');
        metric_card('Maior Anúncio', $stats['qtd'] > 0 ? money((float)$stats['max_p']) : '-', '', 'ph-trend-up');
        metric_card('Ativos na Plataforma', (string)$stats['qtd'], '', 'ph-device-mobile');
        metric_card('Lucro Estimado', $lucroStr, '', 'ph-currency-dollar');
        metric_card('Margem Percentual', $margemStr, '', 'ph-percent');
        echo '</section>';
    }

    layout_end();
}

function render_price_alerts_page() {
    $user = layout_start('Alertas de Preço');
    if ($user['plano'] !== 'Elite') {
        echo '<div class="panel text-center" style="padding: 4rem 2rem;">
            <i class="ph ph-lock-key" style="font-size: 3rem; color: var(--blue); margin-bottom: 1rem; opacity: 0.8;"></i>
            <h2>Recurso Exclusivo Elite</h2>
            <p style="color: #A0AEC0; margin-bottom: 2rem;">O Alerta de Preços é exclusivo do plano Elite.</p>
            <a href="index.php?p=plans" class="button">Ver Planos</a>
        </div>';
        layout_end(); return;
    }

    $pdo = db();
    $alerts = $pdo->prepare('SELECT * FROM price_alerts WHERE user_id=? ORDER BY data_criacao DESC');
    $alerts->execute([$user['id']]);
    $alerts = $alerts->fetchAll();

    echo '<div class="page-head">
        <div><span class="eyebrow">Automação Elite</span><h1>Alertas de Preço</h1><p class="page-subtitle">Receba notificações assim que um aparelho for anunciado abaixo do seu valor desejado.</p></div>
    </div>';

    echo '<section class="panel form-grid" style="margin-bottom: 2rem;">
        <h2 class="full">Novo Alerta</h2>
        <form method="post" class="form-grid full" style="margin: 0; padding: 0; border: none; box-shadow: none;">
            <input type="hidden" name="action" value="create_price_alert">
            <label>Modelo desejado (Ex: iPhone 14 Pro Max) <input type="text" name="modelo" required></label>
            <label>Valor máximo desejado (R$) <input type="number" step="0.01" name="valor_desejado" required></label>
            <button class="button full" style="margin-top: 1.5rem;">Ativar Alerta Automático</button>
        </form>
    </section>';

    echo '<section class="panel"><h2>Seus alertas ativos</h2><div class="table-wrap"><table><thead><tr><th>Modelo</th><th>Valor alvo</th><th>Status</th><th>Criado em</th><th>Ação</th></tr></thead><tbody>';
    foreach ($alerts as $a) {
        echo '<tr>
            <td><strong>'.e($a['modelo']).'</strong></td>
            <td>'.money((float)$a['valor_desejado']).'</td>
            <td><span class="chip">'.e($a['status']).'</span></td>
            <td>'.date('d/m/Y', strtotime($a['data_criacao'])).'</td>
            <td><form method="post"><input type="hidden" name="action" value="delete_price_alert"><input type="hidden" name="id" value="'.(int)$a['id'].'"><button class="danger small">Remover</button></form></td>
        </tr>';
    }
    if (!$alerts) {
        echo '<tr><td colspan="5" class="text-center" style="padding: 2rem; color: #666;">Nenhum alerta cadastrado.</td></tr>';
    }
    echo '</tbody></table></div></section>';

    layout_end();
}

function render_inventory_page() {
    $user = layout_start('PDV e Estoque Avançado');
    if ($user['plano'] !== 'Elite') {
        echo '<div class="panel text-center" style="padding: 4rem 2rem;">
            <i class="ph ph-lock-key" style="font-size: 3rem; color: var(--blue); margin-bottom: 1rem; opacity: 0.8;"></i>
            <h2>Módulo Exclusivo Elite</h2>
            <p style="color: #A0AEC0; margin-bottom: 2rem;">O PDV Completo com controle de imei, custo e margem é exclusivo do plano Elite.</p>
            <a href="index.php?p=plans" class="button">Fazer Upgrade</a>
        </div>';
        layout_end(); return;
    }

    $pdo = db();
    $inventory = $pdo->prepare('SELECT * FROM inventory WHERE user_id=? ORDER BY data_cadastro DESC');
    $inventory->execute([$user['id']]);
    $inventory = $inventory->fetchAll();

    $totalEstoque = 0;
    $custoEstoque = 0;
    $totalVendidos = 0;
    $lucroAcumulado = 0;

    foreach ($inventory as $i) {
        $totalEstoque += ($i['valor_venda'] * $i['quantidade']);
        $custoEstoque += ($i['valor_custo'] * $i['quantidade']);
    }

    $sales = $pdo->prepare('SELECT s.*, i.valor_custo FROM sales s JOIN inventory i ON i.id = s.inventory_id WHERE s.user_id=?');
    $sales->execute([$user['id']]);
    foreach ($sales->fetchAll() as $s) {
        $totalVendidos += $s['quantidade'];
        $lucroAcumulado += ($s['valor_venda'] - $s['valor_custo']) * $s['quantidade'];
    }

    echo '<div class="page-head">
        <div><span class="eyebrow">Gestão Integrada</span><h1>Controle de Estoque e PDV</h1><p class="page-subtitle">Controle preciso do seu balcão físico, independentemente da plataforma.</p></div>
    </div>';

    echo '<section class="metric-grid">';
    metric_card('Valor em Estoque', money($totalEstoque), '', 'ph-package');
    metric_card('Custo do Estoque', money($custoEstoque), '', 'ph-money');
    metric_card('Aparelhos Vendidos', (string)$totalVendidos, '', 'ph-shopping-bag');
    metric_card('Lucro Acumulado', money($lucroAcumulado), '', 'ph-trend-up');
    echo '</section>';

    echo '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-top: 2rem;">';
    
    // Add form
    echo '<section class="panel form-grid">
        <h2 class="full">Dar entrada no estoque</h2>
        <form method="post" class="form-grid full" style="margin: 0; padding: 0; border: none; box-shadow: none;">
            <input type="hidden" name="action" value="inventory_add">
            <label>Marca <select name="marca" required><option value="Apple">Apple</option><option value="Samsung">Samsung</option><option value="Xiaomi">Xiaomi</option><option value="Motorola">Motorola</option></select></label>
            <label>Modelo <input type="text" name="modelo" required></label>
            <label>IMEI / Serial <input type="text" name="imei"></label>
            <label>Cor <input type="text" name="cor"></label>
            <label>Armazenamento <input type="text" name="armazenamento" required></label>
            <label>Quantidade <input type="number" name="quantidade" value="1" min="1" required></label>
            <label>Custo (R$) <input type="number" step="0.01" name="valor_custo" required></label>
            <label>Valor Venda (R$) <input type="number" step="0.01" name="valor_venda" required></label>
            <button class="button full" style="margin-top: 1rem;">Adicionar Aparelho</button>
        </form>
    </section>';

    // Sell form
    echo '<section class="panel form-grid">
        <h2 class="full">Registrar Venda (PDV)</h2>
        <form method="post" class="form-grid full" style="margin: 0; padding: 0; border: none; box-shadow: none;">
            <input type="hidden" name="action" value="inventory_sell">
            <label class="full">Selecione o Aparelho <select name="inventory_id" required>';
            foreach ($inventory as $i) {
                if ($i['quantidade'] > 0) {
                    echo '<option value="'.(int)$i['id'].'">'.e($i['modelo']).' '.e($i['armazenamento']).' (Estoque: '.(int)$i['quantidade'].')</option>';
                }
            }
            echo '</select></label>
            <label class="full">Nome do Cliente <input type="text" name="cliente_nome" required></label>
            <label>Quantidade <input type="number" name="quantidade" value="1" min="1" required></label>
            <label>Valor da Venda Final (R$) <input type="number" step="0.01" name="valor_venda" required></label>
            <button class="button full" style="margin-top: 1rem;">Confirmar Venda Física</button>
        </form>
    </section>';

    echo '</div>'; // End grid

    echo '<section class="panel" style="margin-top: 2rem;"><h2>Seu Estoque Atual</h2><div class="table-wrap"><table><thead><tr><th>Modelo/Info</th><th>IMEI</th><th>Custo</th><th>Venda</th><th>Margem</th><th>Estoque</th></tr></thead><tbody>';
    foreach ($inventory as $i) {
        $margin = $i['valor_venda'] > 0 ? (($i['valor_venda'] - $i['valor_custo']) / $i['valor_venda']) * 100 : 0;
        echo '<tr>
            <td><strong>'.e($i['modelo']).'</strong><br><small>'.e($i['armazenamento']).' • '.e($i['cor']).'</small></td>
            <td>'.e($i['imei'] ?: 'N/A').'</td>
            <td>'.money((float)$i['valor_custo']).'</td>
            <td>'.money((float)$i['valor_venda']).'</td>
            <td><span class="chip blue">'.number_format($margin, 1, ',', '.').'%</span></td>
            <td><span class="chip '.($i['quantidade'] > 0 ? 'success-chip' : 'danger-chip').'">'.(int)$i['quantidade'].' un.</span></td>
        </tr>';
    }
    if (!$inventory) {
        echo '<tr><td colspan="6" class="text-center" style="padding: 2rem; color: #666;">Seu estoque físico está vazio.</td></tr>';
    }
    echo '</tbody></table></div></section>';

    layout_end();
}

function render_referrals_page() {
    $user = layout_start('Sistema de Indicações');
    $pdo = db();

    // Check if user has a referral code, if not generate one.
    if (empty($user['referral_code'])) {
        $code = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $user['nome']), 0, 4) . rand(1000, 9999));
        $pdo->prepare('UPDATE users SET referral_code=? WHERE id=?')->execute([$code, $user['id']]);
        $user['referral_code'] = $code;
    }

    $referralLink = 'https://lojist.com.br/index.php?p=register&ref=' . e($user['referral_code']);

    $refs = $pdo->prepare('SELECT r.status, u.nome_loja, r.data_indicacao FROM referrals r JOIN users u ON u.id = r.referred_id WHERE r.referrer_id = ? ORDER BY r.data_indicacao DESC');
    $refs->execute([$user['id']]);
    $refs = $refs->fetchAll();

    $totalIndicados = count($refs);
    $ativos = 0;
    foreach ($refs as $r) {
        if ($r['status'] === 'valida') $ativos++;
    }

    echo '<div class="page-head">
        <div><span class="eyebrow">Cresça a Rede</span><h1>Indique e Ganhe</h1><p class="page-subtitle">Convide outros lojistas e ganhe meses gratuitos nos planos Pro e Elite.</p></div>
    </div>';

    echo '<section class="metric-grid">';
    metric_card('Total de Indicados', (string)$totalIndicados, '', 'ph-users');
    metric_card('Indicados Ativos (Válidos)', (string)$ativos, '', 'ph-check-circle');
    metric_card('Recompensas Recebidas', '0', 'Em breve histórico completo', 'ph-gift');
    echo '</section>';

    echo '<section class="panel" style="margin-top: 2rem; background: linear-gradient(135deg, rgba(0, 102, 255, 0.1) 0%, rgba(0, 0, 0, 0) 100%);">
        <h2>Seu link de convite</h2>
        <p style="margin-bottom: 1rem; color: #A0AEC0;">Envie este link para outros lojistas se cadastrarem. Quando o lojista assinar um plano, a indicação se torna válida.</p>
        <div style="display: flex; gap: 1rem; align-items: center;">
            <input type="text" readonly value="'.e($referralLink).'" style="flex: 1; padding: 1rem; background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; color: white; font-family: monospace;">
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-top: 2rem;">
            <div style="padding: 1.5rem; background: rgba(255,255,255,0.03); border-radius: 12px; border: 1px solid rgba(255,255,255,0.05);">
                <h3 style="color: var(--blue-2); margin-bottom: 0.5rem;"><i class="ph ph-star"></i> 3 Indicações Válidas/Mês</h3>
                <p>Ganha 1 mês de plano <strong>PRO</strong> gratuitamente.</p>
            </div>
            <div style="padding: 1.5rem; background: rgba(255,255,255,0.03); border-radius: 12px; border: 1px solid rgba(255,255,255,0.05);">
                <h3 style="color: #F59E0B; margin-bottom: 0.5rem;"><i class="ph ph-crown"></i> 5 Indicações Válidas/Mês</h3>
                <p>Ganha 1 mês de plano <strong>ELITE</strong> gratuitamente.</p>
            </div>
        </div>
    </section>';

    echo '<section class="panel" style="margin-top: 2rem;"><h2>Seus Indicados</h2><div class="table-wrap"><table><thead><tr><th>Lojista</th><th>Status da Indicação</th><th>Data</th></tr></thead><tbody>';
    foreach ($refs as $r) {
        echo '<tr>
            <td><strong>'.e($r['nome_loja']).'</strong></td>
            <td><span class="chip '.($r['status'] === 'valida' ? 'success-chip' : '').'">'.e(ucfirst($r['status'])).'</span></td>
            <td>'.date('d/m/Y', strtotime($r['data_indicacao'])).'</td>
        </tr>';
    }
    if (!$refs) {
        echo '<tr><td colspan="3" class="text-center" style="padding: 2rem; color: #666;">Você ainda não tem indicados. Compartilhe seu link!</td></tr>';
    }
    echo '</tbody></table></div></section>';

    layout_end();
}

function render_subscriptions_page() {
    $user = layout_start('Assinatura e Cobrança');
    $pdo = db();

    // Logic for downgrading if 3 days overdue
    $sub = $pdo->prepare('SELECT * FROM subscriptions WHERE user_id=? ORDER BY id DESC LIMIT 1');
    $sub->execute([$user['id']]);
    $subscription = $sub->fetch();

    if ($subscription && $subscription['status'] === 'atrasado') {
        $daysOverdue = (time() - strtotime($subscription['proxima_cobranca'])) / 86400;
        if ($daysOverdue >= 3 && $user['plano'] !== 'Free') {
            $pdo->prepare('UPDATE users SET plano="Free" WHERE id=?')->execute([$user['id']]);
            $user['plano'] = 'Free';
            $pdo->prepare('UPDATE subscriptions SET status="cancelado" WHERE id=?')->execute([$subscription['id']]);
            $subscription['status'] = 'cancelado';
            add_notification((int)$user['id'], 'plano_rebaixado', 'Plano Rebaixado', 'Seu plano foi rebaixado para Free por falta de pagamento após 3 dias.');
        }
    }

    $payments = $pdo->prepare('SELECT * FROM subscription_payments WHERE subscription_id=? ORDER BY data_pagamento DESC');
    $payments->execute([$subscription ? $subscription['id'] : 0]);
    $payments = $payments->fetchAll();

    echo '<div class="page-head">
        <div><span class="eyebrow">Assinatura</span><h1>Meu Plano</h1><p class="page-subtitle">Gerencie sua assinatura, visualize histórico de pagamentos e próximas cobranças.</p></div>
    </div>';

    echo '<section class="metric-grid">';
    metric_card('Plano Atual', (string)$user['plano'], '', 'ph-crown');
    metric_card('Status da Assinatura', $subscription ? e(ucfirst($subscription['status'])) : 'Sem assinatura', '', 'ph-check-circle');
    metric_card('Próxima Cobrança', $subscription && $subscription['status'] !== 'cancelado' ? date('d/m/Y', strtotime($subscription['proxima_cobranca'])) : '-', '', 'ph-calendar');
    metric_card('Método', $subscription ? e(ucfirst($subscription['metodo'])) : '-', '', 'ph-credit-card');
    echo '</section>';

    if (!$subscription || $subscription['status'] === 'cancelado') {
        echo '<section class="panel form-grid" style="margin-top: 2rem;">
            <h2 class="full">Assinar Novo Plano (Simulação)</h2>
            <form method="post" class="form-grid full" style="margin: 0; padding: 0; border: none; box-shadow: none;">
                <input type="hidden" name="action" value="subscribe_plan">
                <label>Escolha o Plano <select name="plano"><option value="Pro">Pro - R$ 99/mês</option><option value="Elite">Elite - R$ 199/mês</option></select></label>
                <label>Método de Pagamento <select name="metodo"><option value="pix">Pix (Mensal)</option><option value="cartao">Cartão de Crédito (Recorrente)</option></select></label>
                <button class="button full" style="margin-top: 1rem;">Confirmar Assinatura</button>
            </form>
        </section>';
    } else if ($subscription['status'] === 'ativo') {
        echo '<section class="panel form-grid" style="margin-top: 2rem;">
            <h2 class="full">Gerenciar Assinatura</h2>
            <form method="post" class="inline-form">
                <input type="hidden" name="action" value="simulate_overdue">
                <button class="button small" style="background: #F59E0B; border-color: #F59E0B; color: white;">Simular Atraso (Teste de 3 dias)</button>
            </form>
            <form method="post" class="inline-form" style="margin-top: 1rem;">
                <input type="hidden" name="action" value="cancel_subscription">
                <button class="danger small">Cancelar Assinatura</button>
            </form>
        </section>';
    } else if ($subscription['status'] === 'atrasado') {
        echo '<section class="panel form-grid" style="margin-top: 2rem; background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3);">
            <h2 class="full" style="color: #EF4444;">Pagamento Atrasado</h2>
            <p class="full">Sua assinatura está atrasada. O plano será rebaixado para Free automaticamente se o atraso chegar a 3 dias.</p>
            <form method="post" class="inline-form">
                <input type="hidden" name="action" value="pay_overdue">
                <button class="button small">Pagar Fatura Pendente (Simular)</button>
            </form>
        </section>';
    }

    echo '<section class="panel" style="margin-top: 2rem;"><h2>Histórico de Pagamentos</h2><div class="table-wrap"><table><thead><tr><th>Data</th><th>Valor</th><th>Status</th></tr></thead><tbody>';
    foreach ($payments as $p) {
        echo '<tr>
            <td>'.date('d/m/Y H:i', strtotime($p['data_pagamento'])).'</td>
            <td>'.money((float)$p['valor']).'</td>
            <td><span class="chip '.($p['status'] === 'pago' ? 'success-chip' : ($p['status'] === 'pendente' ? 'warning-chip' : 'danger-chip')).'">'.e(ucfirst($p['status'])).'</span></td>
        </tr>';
    }
    if (!$payments) {
        echo '<tr><td colspan="3" class="text-center" style="padding: 2rem; color: #666;">Nenhum pagamento registrado.</td></tr>';
    }
    echo '</tbody></table></div></section>';

    layout_end();
}

function render_top_header(array $user) {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id=? AND lida=0 ORDER BY data DESC LIMIT 10');
    $stmt->execute([$user['id']]);
    $notifications = $stmt->fetchAll();
    $unreadCount = count($notifications);

    echo '<div style="display: flex; justify-content: flex-end; padding: 1rem 2rem; border-bottom: 1px solid rgba(255,255,255,0.05); position: relative; z-index: 50;">
        <div style="position: relative; cursor: pointer;" onclick="document.getElementById(\'notif-dropdown\').classList.toggle(\'show\');">
            <i class="ph ph-bell" style="font-size: 1.5rem; color: #A0AEC0; transition: color 0.2s;" onmouseover="this.style.color=\'white\'" onmouseout="this.style.color=\'#A0AEC0\'"></i>
            '.($unreadCount > 0 ? '<span style="position: absolute; top: -2px; right: -4px; background: #EF4444; color: white; font-size: 0.7rem; font-weight: bold; border-radius: 50%; width: 18px; height: 18px; display: flex; align-items: center; justify-content: center; box-shadow: 0 0 0 2px var(--bg);">'.$unreadCount.'</span>' : '').'
        </div>
        <div id="notif-dropdown" style="display: none; position: absolute; top: 3.5rem; right: 2rem; width: 320px; background: #1F2937; border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); overflow: hidden; z-index: 100;">
            <div style="padding: 1rem; border-bottom: 1px solid rgba(255,255,255,0.05); display: flex; justify-content: space-between; align-items: center;">
                <strong style="color: white; font-size: 0.95rem;">Notificações</strong>
                <a href="index.php?p=notifications" style="font-size: 0.8rem; color: var(--blue);">Ver todas</a>
            </div>
            <div style="max-height: 300px; overflow-y: auto;">';
    
    if ($unreadCount > 0) {
        foreach ($notifications as $n) {
            echo '<div style="padding: 1rem; border-bottom: 1px solid rgba(255,255,255,0.05); transition: background 0.2s;" onmouseover="this.style.background=\'rgba(255,255,255,0.02)\'" onmouseout="this.style.background=\'transparent\'">
                <strong style="display: block; color: white; font-size: 0.85rem; margin-bottom: 4px;">'.e($n['titulo']).'</strong>
                <p style="color: #9CA3AF; font-size: 0.8rem; margin: 0; line-height: 1.4;">'.e($n['mensagem']).'</p>
                <small style="color: #6B7280; font-size: 0.7rem; margin-top: 6px; display: block;">'.local_time((string)$n['data']).'</small>
            </div>';
        }
    } else {
        echo '<div style="padding: 2rem 1rem; text-align: center; color: #6B7280; font-size: 0.9rem;">Nenhuma notificação nova.</div>';
    }

    echo '  </div>
        </div>
        <script>
            document.addEventListener("click", function(e) {
                const drop = document.getElementById("notif-dropdown");
                if (drop && !e.target.closest("#notif-dropdown") && !e.target.closest(".ph-bell")) {
                    drop.classList.remove("show");
                }
            });
            const style = document.createElement("style");
            style.innerHTML = "#notif-dropdown.show { display: block !important; }";
            document.head.appendChild(style);
        </script>
    </div>';
}

function render_trade_simulation_page() {
    $user = layout_start('Simular Troca');
    if ($user['plano'] !== 'Elite') {
        echo '<div class="panel text-center" style="padding: 4rem 2rem;">
            <i class="ph ph-lock-key" style="font-size: 3rem; color: var(--blue); margin-bottom: 1rem; opacity: 0.8;"></i>
            <h2>Módulo Exclusivo Elite</h2>
            <p style="color: #A0AEC0; margin-bottom: 2rem;">A Simulação Inteligente de Troca com base de preços é exclusiva do plano Elite.</p>
            <a href="index.php?p=plans" class="button">Fazer Upgrade</a>
        </div>';
        layout_end(); return;
    }

    $pdo = db();
    
    $simulated = !empty($_GET['simulated']);
    $not_found = !empty($_GET['not_found']);
    
    $sale_value = isset($_GET['sale_value']) ? (float)$_GET['sale_value'] : null;
    $brand = isset($_GET['brand']) ? (string)$_GET['brand'] : '';
    $model = isset($_GET['model']) ? (string)$_GET['model'] : '';
    $storage = isset($_GET['storage']) ? (string)$_GET['storage'] : '';
    $condition = isset($_GET['condition']) ? (string)$_GET['condition'] : '';
    
    $market_value = isset($_GET['market_value']) ? (float)$_GET['market_value'] : null;
    $condition_value = isset($_GET['condition_value']) ? (float)$_GET['condition_value'] : null;
    $complement_value = isset($_GET['complement_value']) ? (float)$_GET['complement_value'] : null;
    
    echo '<div class="page-head">
        <div><span class="eyebrow">Funcionalidade Elite</span><h1>Simular Troca Inteligente</h1><p class="page-subtitle">Calcule o valor ideal de aparelhos usados recebidos como parte do pagamento.</p></div>
    </div>';
    
    render_device_datalists();
    
    echo '<div style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 2rem; align-items: start; margin-top: 2rem;">';
    
    echo '<section class="panel form-grid">
        <h2 class="full">Dados da Negociação</h2>
        <form method="post" class="form-grid full" style="margin: 0; padding: 0; border: none; box-shadow: none;">
            <input type="hidden" name="action" value="simulate_trade">
            
            <label class="full">Valor do Produto Vendido (R$) 
                <input required type="number" step="0.01" name="product_sale_value" value="' . e($sale_value !== null ? (string)$sale_value : '') . '" placeholder="Ex: 7000.00">
            </label>
            
            <label>Marca do Aparelho Recebido 
                <select name="brand" required>
                    <option value="Apple" ' . ($brand === 'Apple' ? 'selected' : '') . '>Apple</option>
                    <option value="Samsung" ' . ($brand === 'Samsung' ? 'selected' : '') . '>Samsung</option>
                    <option value="Xiaomi" ' . ($brand === 'Xiaomi' ? 'selected' : '') . '>Xiaomi</option>
                    <option value="Motorola" ' . ($brand === 'Motorola' ? 'selected' : '') . '>Motorola</option>
                </select>
            </label>
            
            <label>Modelo 
                <input required name="model" list="deviceModels" value="' . e($model) . '" placeholder="Ex: iPhone 13">
            </label>
            
            <label>Armazenamento 
                <input required name="storage" list="deviceStorages" value="' . e($storage) . '" placeholder="Ex: 128GB">
            </label>
            
            <label>Condição Geral 
                <select name="condition" required>
                    <option value="excelente" ' . ($condition === 'excelente' ? 'selected' : '') . '>Excelente (100% do mercado)</option>
                    <option value="bom" ' . ($condition === 'bom' ? 'selected' : '') . '>Bom (90% do mercado)</option>
                    <option value="regular" ' . ($condition === 'regular' ? 'selected' : '') . '>Regular (75% do mercado)</option>
                    <option value="ruim" ' . ($condition === 'ruim' ? 'selected' : '') . '>Ruim (60% do mercado)</option>
                </select>
            </label>';
            
            if ($not_found) {
                echo '<div class="full" style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 12px; padding: 1rem; margin-bottom: 1rem;">
                    <p style="color: #EF4444; margin: 0; font-weight: 500; font-size: 0.95rem;">Aparelho não encontrado na base de referência.</p>
                    <p style="color: #A0AEC0; font-size: 0.85rem; margin: 4px 0 12px 0;">Você pode preencher o valor de mercado manualmente abaixo para calcular ou sugerir sua inclusão.</p>
                    <label style="display:block; margin:0;"><span style="color:white; display:block; margin-bottom:6px;">Valor Médio de Mercado Manual (R$)</span>
                        <input required type="number" step="0.01" name="manual_market_value" placeholder="Ex: 2900.00" style="background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.1); color: white; border-radius: 8px; padding: 10px 14px; width: 100%;">
                    </label>
                </div>';
            }
            
            echo '<button class="button full" style="margin-top: 1rem;">Calcular Troca</button>
        </form>';
        
        if ($not_found) {
            echo '<form method="post" class="full" style="margin-top: 1rem; padding:0; border:none; box-shadow:none;">
                <input type="hidden" name="action" value="suggest_device_price">
                <input type="hidden" name="brand" value="' . e($brand) . '">
                <input type="hidden" name="model" value="' . e($model) . '">
                <input type="hidden" name="storage" value="' . e($storage) . '">
                <button class="button ghost full" style="border-color: #F59E0B; color: #F59E0B;"><i class="ph ph-lightbulb" style="margin-right:6px;"></i> Sugerir inclusão na base</button>
            </form>';
        }
        
    echo '</section>';
    
    echo '<section class="panel" style="min-height: 380px; display: flex; flex-direction: column; justify-content: center;">';
    
    if ($simulated && $market_value !== null) {
        $condLabels = ['excelente' => 'Excelente (100%)', 'bom' => 'Bom (90%)', 'regular' => 'Regular (75%)', 'ruim' => 'Ruim (60%)'];
        $condText = $condLabels[$condition] ?? ucfirst($condition);
        
        echo '<div>
            <span class="eyebrow" style="color:var(--blue-2);">Cálculo Efetuado</span>
            <h2 style="margin-bottom: 1.5rem;">Resultado da Simulação</h2>
            
            <div style="background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; padding: 1.5rem; margin-bottom: 2rem;">
                <p style="margin: 0 0 8px 0; color: #A0AEC0; font-size: 0.9rem;">Aparelho de Entrada</p>
                <strong style="font-size: 1.25rem; color: white;">' . e($brand) . ' ' . e($model) . ' ' . e($storage) . '</strong>
                <p style="margin: 6px 0 0 0; color: #E2E8F0; font-size: 0.85rem;"><span class="chip" style="background:rgba(255,255,255,0.05);">' . e($condText) . '</span></p>
            </div>
            
            <div style="display: flex; flex-direction: column; gap: 1rem;">
                <div style="display: flex; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 0.75rem;">
                    <span style="color:#A0AEC0;">Produto Vendido:</span>
                    <strong style="color:white;">' . money($sale_value) . '</strong>
                </div>
                <div style="display: flex; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 0.75rem;">
                    <span style="color:#A0AEC0;">Valor Médio de Mercado:</span>
                    <strong style="color:white;">' . money($market_value) . '</strong>
                </div>
                <div style="display: flex; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 0.75rem; align-items: center;">
                    <span style="color:#A0AEC0;">Valor Considerado (Recebido):</span>
                    <span style="font-size: 1.15rem; font-weight:700; color: #10B981; background: rgba(16, 185, 129, 0.1); padding: 4px 10px; border-radius: 8px;">' . money($condition_value) . '</span>
                </div>
                
                <div style="margin-top: 1.5rem; background: linear-gradient(135deg, rgba(0, 102, 255, 0.15) 0%, rgba(0, 0, 0, 0) 100%); border: 1px solid rgba(0, 102, 255, 0.25); border-radius: 12px; padding: 1.5rem; text-align: center;">
                    <span style="color: #93C5FD; font-size: 0.85rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 6px;">Diferença a Complementar</span>
                    <strong style="font-size: 2.2rem; color: #3B82F6; letter-spacing: -1px; display: block;">' . money($complement_value) . '</strong>
                    <span style="color: #A0AEC0; font-size: 0.8rem; margin-top: 4px; display: block;">O cliente precisa pagar este valor para concluir a troca.</span>
                </div>
            </div>
        </div>';
    } else {
        echo '<div style="text-align: center; color: #666; padding: 2rem;">
            <i class="ph ph-scales" style="font-size: 3.5rem; color: var(--blue); margin-bottom: 1rem; opacity: 0.4;"></i>
            <h3>Aguardando Simulação</h3>
            <p style="font-size: 0.9rem; max-width: 280px; margin: 8px auto 0 auto; color:#A0AEC0;">Preencha os dados à esquerda e clique em "Calcular Troca" para visualizar a análise.</p>
        </div>';
    }
    
    echo '</section>';
    echo '</div>';
    
    layout_end();
}
