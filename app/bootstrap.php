<?php
declare(strict_types=1);

date_default_timezone_set('America/Campo_Grande');

const APP_NAME = 'LOJIST';
const APP_ROOT = __DIR__ . '/..';
const CONFIG_FILE = APP_ROOT . '/lojist_config.php';
const UPLOAD_DIR = APP_ROOT . '/uploads';
const SESSION_DIR = APP_ROOT . '/data/sessions';

if (!is_dir(UPLOAD_DIR)) {
    @mkdir(UPLOAD_DIR, 0777, true);
}

if (!file_exists(CONFIG_FILE)) {
    $defaultConfig = <<<'PHP'
<?php
return [
    'db_host' => getenv('DB_HOST') ?: 'localhost',
    'db_port' => getenv('DB_PORT') ?: '3306',
    'db_name' => getenv('DB_NAME') ?: 'lojist',
    'db_user' => getenv('DB_USER') ?: 'root',
    'db_pass' => getenv('DB_PASS') !== false ? getenv('DB_PASS') : '',
    'db_charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
    'app_env' => getenv('APP_ENV') ?: 'local',
    'app_url' => getenv('APP_URL') ?: 'http://localhost/lojist',
    'marketing_lock_enabled' => getenv('MARKETING_LOCK_ENABLED') === 'true',
    'marketing_admin_key' => getenv('MARKETING_ADMIN_KEY') ?: 'lojist-admin-2026',
    'asaas_environment' => getenv('ASAAS_ENVIRONMENT') ?: 'production',
    'asaas_api_key' => getenv('ASAAS_API_KEY') ?: 'COLE_AQUI_SUA_API_KEY_DO_ASAAS',
    'asaas_webhook_token' => getenv('ASAAS_WEBHOOK_TOKEN') ?: 'COLE_AQUI_UM_TOKEN_FORTE_DO_WEBHOOK',
    'payment_provider' => getenv('PAYMENT_PROVIDER') ?: 'asaas',
    'infinitepay_handle' => getenv('INFINITEPAY_HANDLE') ?: 'COLE_AQUI_SEU_HANDLE',
    'infinitepay_webhook_secret' => getenv('INFINITEPAY_WEBHOOK_SECRET') ?: 'COLE_AQUI_UM_TOKEN_FORTE_INFINITYPAY',
    'mail_from' => getenv('MAIL_FROM') ?: 'naoresponda@lojist.com.br',
    'mail_from_name' => getenv('MAIL_FROM_NAME') ?: 'LOJIST',
    'mail_reply_to' => getenv('MAIL_REPLY_TO') ?: 'naoresponda@lojist.com.br',
];
PHP;
    file_put_contents(CONFIG_FILE, $defaultConfig);
}

$cookieSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_name('LOJISTSESSID');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $cookieSecure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_start();

if (empty($_SESSION['_csrf_token'])) {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !csrf_should_skip()) {
    $postedToken = (string)($_POST['_csrf_token'] ?? '');
    if (!hash_equals((string)$_SESSION['_csrf_token'], $postedToken)) {
        http_response_code(419);
        exit('Sessao expirada ou formulario invalido. Recarregue a pagina e tente novamente.');
    }
}

// ob_start('inject_csrf_token'); // Vercel WSOD fix

function csrf_should_skip(): bool
{
    $page = (string)($_GET['p'] ?? '');
    $action = (string)($_POST['action'] ?? '');
    if (in_array($page, ['asaas-webhook', 'infinitepay-webhook'], true)) {
        return true;
    }
    return in_array($action, ['login', 'forgot_password', 'reset_password'], true);
}

function app_config(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }
    $config = require CONFIG_FILE;
    return is_array($config) ? $config : [];
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = app_config();
    $host = $config['db_host'] ?? '127.0.0.1';
    $port = $config['db_port'] ?? '3306';
    $name = $config['db_name'] ?? 'lojist';
    $user = $config['db_user'] ?? 'root';
    $pass = $config['db_pass'] ?? '';
    $charset = $config['db_charset'] ?? 'utf8mb4';

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $name) || !preg_match('/^[a-zA-Z0-9_]+$/', $charset)) {
        throw new RuntimeException('Configuracao de banco invalida.');
    }

    $driver = $config['db_driver'] ?? 'mysql';
    $isPg = $driver === 'pgsql' || str_contains($host, 'supabase.com') || $port == 5432;

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    if ($isPg) {
        $dsn = "pgsql:host={$host};port={$port};dbname={$name}";
        $pdo = new PDO($dsn, $user, $pass, $options);
        $pdo->exec("SET TIME ZONE '-04:00'");
        return $pdo;
    }

    $serverDsn = "mysql:host={$host};port={$port};charset={$charset}";
    if (($config['app_env'] ?? 'local') !== 'production') {
        $server = new PDO($serverDsn, $user, $pass, $options);
        $server->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET {$charset} COLLATE {$charset}_unicode_ci");
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
    $pdo = new PDO($dsn, $user, $pass, $options);
    $pdo->exec('SET time_zone = "-04:00"');
    init_database($pdo);
    return $pdo;
}

function init_database(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS plans (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(30) NOT NULL UNIQUE,
            taxa DECIMAL(6,3) NOT NULL,
            limite_anuncios INT NULL,
            filtros_avancados TINYINT(1) NOT NULL DEFAULT 0,
            preco_mensal DECIMAL(10,2) NOT NULL DEFAULT 0,
            especial TINYINT(1) NOT NULL DEFAULT 0,
            ativo TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(120) NOT NULL,
            sobrenome VARCHAR(120) NOT NULL,
            cpf VARCHAR(20) NOT NULL UNIQUE,
            cnpj VARCHAR(20) NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            telefone VARCHAR(30) NOT NULL,
            nome_loja VARCHAR(160) NOT NULL,
            instagram_loja VARCHAR(120) NULL,
            cidade VARCHAR(120) NOT NULL,
            estado CHAR(2) NOT NULL,
            endereco_completo TEXT NOT NULL,
            entrega_nome VARCHAR(160) NULL,
            entrega_telefone VARCHAR(30) NULL,
            entrega_cep VARCHAR(20) NULL,
            entrega_endereco TEXT NULL,
            entrega_cidade VARCHAR(120) NULL,
            entrega_estado CHAR(2) NULL,
            entrega_complemento VARCHAR(160) NULL,
            retirada_instrucao TEXT NULL,
            comprovante VARCHAR(255) NULL,
            documento VARCHAR(255) NULL,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('lojista','admin') NOT NULL DEFAULT 'lojista',
            status_conta ENUM('aguardando_aprovacao','aprovado','recusado','suspenso','banido') NOT NULL DEFAULT 'aguardando_aprovacao',
            plano VARCHAR(30) NOT NULL DEFAULT 'Free',
            trial_ends_at DATETIME NULL,
            paid_until DATETIME NULL,
            subscription_status VARCHAR(30) NOT NULL DEFAULT 'pending',
            last_plan_payment_at DATETIME NULL,
            nivel ENUM('Bronze','Silver','Gold','Diamond') NOT NULL DEFAULT 'Bronze',
            nota_geral DECIMAL(3,2) NOT NULL DEFAULT 5,
            vendas_concluidas INT NOT NULL DEFAULT 0,
            compras_concluidas INT NOT NULL DEFAULT 0,
            taxa_cancelamento DECIMAL(6,2) NOT NULL DEFAULT 0,
            tempo_medio_envio VARCHAR(80) NOT NULL DEFAULT 'Ainda sem historico',
            tempo_medio_pagamento VARCHAR(80) NOT NULL DEFAULT 'Ainda sem historico',
            score_comprador DECIMAL(3,2) NOT NULL DEFAULT 5,
            score_vendedor DECIMAL(3,2) NOT NULL DEFAULT 5,
            reservas_expiradas INT NOT NULL DEFAULT 0,
            duplicate_warnings INT NOT NULL DEFAULT 0,
            asaas_customer_id VARCHAR(80) NULL,
            asaas_wallet_id VARCHAR(80) NULL,
            data_cadastro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            aprovado_em DATETIME NULL,
            INDEX idx_users_status (status_conta),
            INDEX idx_users_cidade_estado (cidade, estado)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS products (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            vendedor_id INT UNSIGNED NOT NULL,
            categoria ENUM('iPhone','Samsung','Xiaomi') NOT NULL,
            marca VARCHAR(80) NOT NULL,
            modelo VARCHAR(160) NOT NULL,
            armazenamento VARCHAR(60) NOT NULL,
            cor VARCHAR(80) NOT NULL,
            tipo ENUM('seminovo','lacrado') NOT NULL,
            preco DECIMAL(12,2) NOT NULL,
            custo_privado DECIMAL(12,2) NULL,
            quantidade INT NOT NULL DEFAULT 1,
            estado_geral VARCHAR(80) NULL,
            bateria VARCHAR(40) NULL,
            face_id VARCHAR(30) NULL,
            true_tone VARCHAR(30) NULL,
            icloud_livre VARCHAR(30) NULL,
            defeito VARCHAR(30) NULL,
            peca_trocada VARCHAR(30) NULL,
            detalhes_estruturados TEXT NULL,
            imei_interno VARCHAR(40) NULL,
            serial_number VARCHAR(80) NULL,
            fotos TEXT NULL,
            status ENUM('disponivel','pausado','reservado','aguardando_pagamento','pagamento_aprovado','preparando_envio','enviado','entregue','finalizado','cancelado') NOT NULL DEFAULT 'disponivel',
            aceita_oferta TINYINT(1) NOT NULL DEFAULT 0,
            venda_expressa TINYINT(1) NOT NULL DEFAULT 0,
            metodos_entrega VARCHAR(255) NOT NULL,
            cidade VARCHAR(120) NOT NULL,
            estado CHAR(2) NOT NULL,
            data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            data_atualizacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            preco_ajustado_recentemente TINYINT(1) NOT NULL DEFAULT 0,
            INDEX idx_products_feed (status, cidade, estado, categoria, preco),
            INDEX idx_products_vendedor (vendedor_id),
            CONSTRAINT fk_products_vendedor FOREIGN KEY (vendedor_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS orders (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            produto_id INT UNSIGNED NOT NULL,
            comprador_id INT UNSIGNED NOT NULL,
            vendedor_id INT UNSIGNED NOT NULL,
            valor_bruto DECIMAL(12,2) NOT NULL,
            taxa_plataforma DECIMAL(12,2) NOT NULL,
            valor_liquido DECIMAL(12,2) NOT NULL,
            metodo_entrega VARCHAR(80) NOT NULL,
            status ENUM('aguardando_pagamento','pagamento_aprovado','preparando_envio','enviado','entregue','finalizado','cancelado') NOT NULL DEFAULT 'aguardando_pagamento',
            pix_qrcode TEXT NULL,
            pix_status ENUM('pendente','aprovado','cancelado','expirado') NOT NULL DEFAULT 'pendente',
            pagamento_aprovado_em DATETIME NULL,
            enviado_em DATETIME NULL,
            entregue_em DATETIME NULL,
            finalizado_em DATETIME NULL,
            cancelado_em DATETIME NULL,
            motivo_cancelamento TEXT NULL,
            destinatario VARCHAR(160) NULL,
            telefone_entrega VARCHAR(30) NULL,
            cep_entrega VARCHAR(20) NULL,
            endereco_entrega TEXT NULL,
            cidade_entrega VARCHAR(120) NULL,
            estado_entrega CHAR(2) NULL,
            complemento_entrega VARCHAR(160) NULL,
            codigo_rastreio VARCHAR(120) NULL,
            repasse_liberado_em DATETIME NULL,
            repasse_status ENUM('retido','liberado','pago') NOT NULL DEFAULT 'retido',
            data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_orders_comprador (comprador_id),
            INDEX idx_orders_vendedor (vendedor_id),
            CONSTRAINT fk_orders_produto FOREIGN KEY (produto_id) REFERENCES products(id),
            CONSTRAINT fk_orders_comprador FOREIGN KEY (comprador_id) REFERENCES users(id),
            CONSTRAINT fk_orders_vendedor FOREIGN KEY (vendedor_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS order_events (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            status VARCHAR(60) NOT NULL,
            titulo VARCHAR(160) NOT NULL,
            mensagem TEXT NOT NULL,
            criado_por INT UNSIGNED NULL,
            data DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_order_events_order (order_id, data),
            CONSTRAINT fk_order_events_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS offers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            produto_id INT UNSIGNED NOT NULL,
            comprador_id INT UNSIGNED NOT NULL,
            vendedor_id INT UNSIGNED NOT NULL,
            valor_oferta DECIMAL(12,2) NOT NULL,
            status ENUM('pendente','aceita','recusada','expirada','paga') NOT NULL DEFAULT 'pendente',
            criada_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            aceita_em DATETIME NULL,
            expira_em DATETIME NULL,
            paga_em DATETIME NULL,
            cooldown_ate DATETIME NULL,
            INDEX idx_offers_produto_comprador (produto_id, comprador_id, cooldown_ate),
            CONSTRAINT fk_offers_produto FOREIGN KEY (produto_id) REFERENCES products(id) ON DELETE CASCADE,
            CONSTRAINT fk_offers_comprador FOREIGN KEY (comprador_id) REFERENCES users(id),
            CONSTRAINT fk_offers_vendedor FOREIGN KEY (vendedor_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS payments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            gateway VARCHAR(80) NOT NULL DEFAULT 'Pix Simulado',
            valor DECIMAL(12,2) NOT NULL,
            taxa_plataforma DECIMAL(12,2) NOT NULL,
            taxa_gateway DECIMAL(12,2) NOT NULL DEFAULT 0,
            status ENUM('pendente','aprovado','cancelado','estornado') NOT NULL DEFAULT 'pendente',
            pix_qrcode TEXT NULL,
            asaas_payment_id VARCHAR(80) NULL,
            asaas_invoice_url VARCHAR(255) NULL,
            infinitepay_order_nsu VARCHAR(80) NULL,
            infinitepay_checkout_url VARCHAR(255) NULL,
            webhook_data JSON NULL,
            aprovado_em DATETIME NULL,
            INDEX idx_payments_asaas (asaas_payment_id),
            CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS plan_payments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            plan_id INT UNSIGNED NOT NULL,
            valor DECIMAL(10,2) NOT NULL,
            status ENUM('pendente','aprovado','cancelado') NOT NULL DEFAULT 'aprovado',
            pix_qrcode VARCHAR(160) NULL,
            asaas_payment_id VARCHAR(80) NULL,
            asaas_invoice_url VARCHAR(255) NULL,
            webhook_data JSON NULL,
            periodo_inicio DATETIME NOT NULL,
            periodo_fim DATETIME NOT NULL,
            data DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_plan_payments_user (user_id, data),
            CONSTRAINT fk_plan_payments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_plan_payments_plan FOREIGN KEY (plan_id) REFERENCES plans(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS notifications (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            tipo VARCHAR(80) NOT NULL,
            titulo VARCHAR(160) NOT NULL,
            mensagem TEXT NOT NULL,
            lida TINYINT(1) NOT NULL DEFAULT 0,
            data DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_notifications_user (user_id, lida, data),
            CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS email_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            email VARCHAR(190) NOT NULL,
            assunto VARCHAR(190) NOT NULL,
            tipo VARCHAR(80) NOT NULL,
            status ENUM('enviado','falhou') NOT NULL,
            erro TEXT NULL,
            data DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_email_logs_user (user_id, data),
            INDEX idx_email_logs_tipo (tipo, data)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            ip VARCHAR(45) NULL,
            data DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_password_reset_user (user_id, expires_at),
            CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS system_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            tipo VARCHAR(80) NOT NULL,
            entidade VARCHAR(80) NULL,
            entidade_id INT UNSIGNED NULL,
            titulo VARCHAR(160) NOT NULL,
            mensagem TEXT NOT NULL,
            ip VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            data DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_system_logs_user (user_id, data),
            INDEX idx_system_logs_tipo (tipo, data)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS price_history (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            produto_id INT UNSIGNED NOT NULL,
            preco_antigo DECIMAL(12,2) NOT NULL,
            preco_novo DECIMAL(12,2) NOT NULL,
            data DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            mostrar_publicamente_apenas_como_indicador TINYINT(1) NOT NULL DEFAULT 1,
            CONSTRAINT fk_price_history_produto FOREIGN KEY (produto_id) REFERENCES products(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS reviews (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            avaliador_id INT UNSIGNED NOT NULL,
            avaliado_id INT UNSIGNED NOT NULL,
            nota TINYINT UNSIGNED NOT NULL,
            criterios TEXT NOT NULL,
            data DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_reviews_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS disputes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            aberto_por INT UNSIGNED NOT NULL,
            motivo TEXT NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'aberta',
            resolucao TEXT NULL,
            admin_responsavel INT UNSIGNED NULL,
            data DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_disputes_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS admin_settings (
            id TINYINT UNSIGNED PRIMARY KEY,
            taxa_free DECIMAL(6,3) NOT NULL DEFAULT 2,
            taxa_pro DECIMAL(6,3) NOT NULL DEFAULT 1.5,
            taxa_elite DECIMAL(6,3) NOT NULL DEFAULT 1,
            taxa_gateway DECIMAL(6,3) NOT NULL DEFAULT 0,
            tempo_reserva_oferta INT NOT NULL DEFAULT 30,
            tempo_pagamento_compra_agora INT NOT NULL DEFAULT 30,
            limites TEXT NULL,
            regras_score TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS migrations (
            id VARCHAR(120) PRIMARY KEY,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS device_market_prices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            brand VARCHAR(100) NOT NULL,
            model VARCHAR(200) NOT NULL,
            storage VARCHAR(50) NOT NULL,
            color VARCHAR(100) NULL,
            average_market_value DECIMAL(10,2) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            active TINYINT(1) DEFAULT 1,
            INDEX idx_brand (brand),
            INDEX idx_model (model),
            INDEX idx_storage (storage),
            INDEX idx_brand_model_storage (brand, model, storage)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS device_market_suggestions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            brand VARCHAR(100) NOT NULL,
            model VARCHAR(200) NOT NULL,
            storage VARCHAR(50) NOT NULL,
            suggested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            status ENUM('pendente','aceita','recusada') DEFAULT 'pendente',
            INDEX idx_user_id (user_id),
            CONSTRAINT fk_suggestions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS trade_simulations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            device_market_price_id INT NULL,
            brand VARCHAR(100) NOT NULL,
            model VARCHAR(200) NOT NULL,
            storage VARCHAR(50) NOT NULL,
            product_sale_value DECIMAL(10,2) NOT NULL,
            market_value DECIMAL(10,2) NULL,
            condition_factor VARCHAR(20) NOT NULL,
            condition_value DECIMAL(10,2) NULL,
            complement_value DECIMAL(10,2) NULL,
            simulated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_simulated_at (simulated_at),
            CONSTRAINT fk_simulations_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    ensure_schema_updates($pdo);
    migrate_existing_times_to_campo_grande($pdo);
    seed_database($pdo);
}

function ensure_schema_updates(PDO $pdo): void
{
    $userColumns = [
        'cnpj' => 'VARCHAR(20) NULL AFTER cpf',
        'duplicate_warnings' => 'INT NOT NULL DEFAULT 0 AFTER reservas_expiradas',
        'trial_ends_at' => 'DATETIME NULL AFTER plano',
        'paid_until' => 'DATETIME NULL AFTER trial_ends_at',
        'subscription_status' => 'VARCHAR(30) NOT NULL DEFAULT "pending" AFTER paid_until',
        'last_plan_payment_at' => 'DATETIME NULL AFTER subscription_status',
        'entrega_nome' => 'VARCHAR(160) NULL AFTER endereco_completo',
        'entrega_telefone' => 'VARCHAR(30) NULL AFTER entrega_nome',
        'entrega_cep' => 'VARCHAR(20) NULL AFTER entrega_telefone',
        'entrega_endereco' => 'TEXT NULL AFTER entrega_cep',
        'entrega_cidade' => 'VARCHAR(120) NULL AFTER entrega_endereco',
        'entrega_estado' => 'CHAR(2) NULL AFTER entrega_cidade',
        'entrega_complemento' => 'VARCHAR(160) NULL AFTER entrega_estado',
        'retirada_instrucao' => 'TEXT NULL AFTER entrega_complemento',
        'asaas_customer_id' => 'VARCHAR(80) NULL AFTER duplicate_warnings',
        'asaas_wallet_id' => 'VARCHAR(80) NULL AFTER asaas_customer_id',
        'referral_code_used' => 'VARCHAR(20) NULL',
    ];
    foreach ($userColumns as $column => $definition) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "users" AND COLUMN_NAME = ?');
        $stmt->execute([$column]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE users ADD COLUMN {$column} {$definition}");
        }
    }

    $planColumns = [
        'especial' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER preco_mensal',
    ];
    foreach ($planColumns as $column => $definition) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "plans" AND COLUMN_NAME = ?');
        $stmt->execute([$column]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE plans ADD COLUMN {$column} {$definition}");
        }
    }

    $productColumns = [
        'serial_number' => 'VARCHAR(80) NULL AFTER imei_interno',
    ];
    foreach ($productColumns as $column => $definition) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "products" AND COLUMN_NAME = ?');
        $stmt->execute([$column]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE products ADD COLUMN {$column} {$definition}");
        }
    }

    $columns = [
        'destinatario' => 'VARCHAR(160) NULL',
        'telefone_entrega' => 'VARCHAR(30) NULL',
        'cep_entrega' => 'VARCHAR(20) NULL',
        'endereco_entrega' => 'TEXT NULL',
        'cidade_entrega' => 'VARCHAR(120) NULL',
        'estado_entrega' => 'CHAR(2) NULL',
        'complemento_entrega' => 'VARCHAR(160) NULL',
        'codigo_rastreio' => 'VARCHAR(120) NULL',
        'repasse_liberado_em' => 'DATETIME NULL',
        'repasse_status' => "ENUM('retido','liberado','pago') NOT NULL DEFAULT 'retido'",
    ];

    foreach ($columns as $column => $definition) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "orders" AND COLUMN_NAME = ?');
        $stmt->execute([$column]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN {$column} {$definition}");
        }
    }

    $paymentColumns = [
        'asaas_payment_id' => 'VARCHAR(80) NULL AFTER pix_qrcode',
        'asaas_invoice_url' => 'VARCHAR(255) NULL AFTER asaas_payment_id',
        'infinitepay_order_nsu' => 'VARCHAR(80) NULL AFTER asaas_invoice_url',
        'infinitepay_checkout_url' => 'VARCHAR(255) NULL AFTER infinitepay_order_nsu',
    ];
    foreach ($paymentColumns as $column => $definition) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "payments" AND COLUMN_NAME = ?');
        $stmt->execute([$column]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE payments ADD COLUMN {$column} {$definition}");
        }
    }

    $planPaymentColumns = [
        'asaas_payment_id' => 'VARCHAR(80) NULL AFTER pix_qrcode',
        'asaas_invoice_url' => 'VARCHAR(255) NULL AFTER asaas_payment_id',
        'webhook_data' => 'JSON NULL AFTER asaas_invoice_url',
    ];
    foreach ($planPaymentColumns as $column => $definition) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "plan_payments" AND COLUMN_NAME = ?');
        $stmt->execute([$column]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE plan_payments ADD COLUMN {$column} {$definition}");
        }
    }
}

function migrate_existing_times_to_campo_grande(PDO $pdo): void
{
    $migrationId = '2026_05_14_shift_brasilia_to_campo_grande';
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM migrations WHERE id = ?');
    $stmt->execute([$migrationId]);
    if ((int)$stmt->fetchColumn() > 0) {
        return;
    }

    $tables = [
        'users' => ['data_cadastro', 'aprovado_em', 'trial_ends_at', 'paid_until', 'last_plan_payment_at'],
        'products' => ['data_criacao', 'data_atualizacao'],
        'orders' => ['pagamento_aprovado_em', 'enviado_em', 'entregue_em', 'finalizado_em', 'cancelado_em', 'data_criacao'],
        'order_events' => ['data'],
        'offers' => ['criada_em', 'aceita_em', 'expira_em', 'paga_em', 'cooldown_ate'],
        'payments' => ['aprovado_em'],
        'plan_payments' => ['periodo_inicio', 'periodo_fim', 'data'],
        'notifications' => ['data'],
        'system_logs' => ['data'],
        'price_history' => ['data'],
        'reviews' => ['data'],
        'disputes' => ['data'],
    ];

    foreach ($tables as $table => $columns) {
        foreach ($columns as $column) {
            $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $check->execute([$table, $column]);
            if ((int)$check->fetchColumn() > 0) {
                $pdo->exec("UPDATE `{$table}` SET `{$column}` = DATE_SUB(`{$column}`, INTERVAL 1 HOUR) WHERE `{$column}` IS NOT NULL");
            }
        }
    }

    $pdo->prepare('INSERT INTO migrations (id) VALUES (?)')->execute([$migrationId]);
    log_system(null, 'migracao_horario', 'sistema', null, 'Horário ajustado para Campo Grande/MS', 'Datas existentes foram convertidas para o horário oficial da plataforma.');
}

function seed_database(PDO $pdo): void
{
    $stmt = $pdo->prepare('INSERT INTO plans (nome, taxa, limite_anuncios, filtros_avancados, preco_mensal, especial, ativo) VALUES (?, ?, ?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE taxa=VALUES(taxa), limite_anuncios=VALUES(limite_anuncios), filtros_avancados=VALUES(filtros_avancados), preco_mensal=VALUES(preco_mensal), especial=VALUES(especial), ativo=1');
    $stmt->execute(['Free', 1.5, 5, 0, 0, 0]);
    $stmt->execute(['Pro', 1.2, 20, 1, 49.90, 0]);
    $stmt->execute(['Elite', 1.0, null, 1, 89.90, 0]);
    $pdo->exec("UPDATE users SET plano='Free' WHERE plano='Plano Lojista' OR plano=''");
    $trial = date('Y-m-d H:i:s', strtotime('+1 month'));
    $pdo->prepare("UPDATE users SET trial_ends_at=?, subscription_status='trialing' WHERE role='lojista' AND status_conta='aprovado' AND trial_ends_at IS NULL AND paid_until IS NULL")->execute([$trial]);

    $pdo->exec('INSERT IGNORE INTO admin_settings (id) VALUES (1)');

    $hash = password_hash('admin123', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO users
        (nome, sobrenome, cpf, email, telefone, nome_loja, instagram_loja, cidade, estado, endereco_completo, password_hash, role, status_conta, plano, nivel, aprovado_em)
        VALUES ('Admin', 'Master', '00000000000', 'admin@lojist.com', '00000000000', 'LOJIST Controle', '@lojist', 'Campo Grande', 'MS', 'Painel administrativo', ?, 'admin', 'aprovado', 'Elite', 'Diamond', NOW())
    ");
    $stmt->execute([$hash]);

    $sellerHash = password_hash('lojista123', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO users
        (nome, sobrenome, cpf, email, telefone, nome_loja, instagram_loja, cidade, estado, endereco_completo, password_hash, status_conta, plano, nivel, nota_geral, vendas_concluidas, compras_concluidas, tempo_medio_envio, tempo_medio_pagamento, score_comprador, score_vendedor, aprovado_em)
        VALUES ('Marina', 'Alves', '11111111111', 'lojista@lojist.com', '67999999999', 'Mobile Prime', '@mobileprime', 'Campo Grande', 'MS', 'Rua Centro, 100', ?, 'aprovado', 'Pro', 'Gold', 4.9, 38, 14, '2h 20min', '4min', 4.8, 4.9, NOW())
    ");
    $stmt->execute([$sellerHash]);

    $count = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    if ($count === 0) {
        $seller = (int)$pdo->query("SELECT id FROM users WHERE email = 'lojista@lojist.com'")->fetchColumn();
        $samples = [
            [$seller, 'iPhone', 'Apple', 'iPhone 15 Pro', '256 GB', 'Titanio Azul', 'seminovo', 6190, 5400, 1, 'Excelente', '91%', 'Sim', 'Sim', 'Sim', 'Nao', 'Nao', 'Tela original, Bateria original, Face ID OK, Sem detalhes, Venda expressa', '359000000000001', '', 'disponivel', 1, 1, 'Retirada local, Motoboy parceiro', 'Campo Grande', 'MS', 1],
            [$seller, 'iPhone', 'Apple', 'iPhone 14', '128 GB', 'Meia-noite', 'lacrado', 3990, 3600, 2, null, null, null, null, null, null, null, 'Nacional, Nota fiscal, Garantia', '', '', 'disponivel', 0, 1, 'Retirada local, Entrega propria', 'Campo Grande', 'MS', 0],
            [$seller, 'Samsung', 'Samsung', 'Galaxy S24', '256 GB', 'Grafite', 'seminovo', 3790, 3300, 1, 'Muito bom', null, 'Nao se aplica', 'Nao se aplica', 'Nao se aplica', 'Nao', 'Nao', 'Camera OK, Carcaca com marcas leves, Aparelho revisado', '358000000000002', '', 'disponivel', 1, 0, 'Retirada local, Transportadora', 'Campo Grande', 'MS', 0],
        ];
        $stmt = $pdo->prepare("
            INSERT INTO products
            (vendedor_id, categoria, marca, modelo, armazenamento, cor, tipo, preco, custo_privado, quantidade, estado_geral, bateria, face_id, true_tone, icloud_livre, defeito, peca_trocada, detalhes_estruturados, imei_interno, serial_number, fotos, status, aceita_oferta, venda_expressa, metodos_entrega, cidade, estado, preco_ajustado_recentemente)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($samples as $sample) {
            array_splice($sample, 19, 0, [$sample[18]]);
            $stmt->execute($sample);
        }
    }

    // Seed device market prices if empty
    $countDevicePrices = (int)$pdo->query('SELECT COUNT(*) FROM device_market_prices')->fetchColumn();
    if ($countDevicePrices === 0) {
        $stmtDevice = $pdo->prepare('INSERT INTO device_market_prices (brand, model, storage, average_market_value, active) VALUES (?, ?, ?, ?, 1)');
        $stmtDevice->execute(['Apple', 'iPhone 13', '128GB', 2900.00]);
        $stmtDevice->execute(['Samsung', 'Galaxy S23', '256GB', 2500.00]);
        $stmtDevice->execute(['Apple', 'iPhone 14 Pro', '256GB', 4800.00]);
        $stmtDevice->execute(['Apple', 'iPhone 15 Pro Max', '256GB', 6200.00]);
        $stmtDevice->execute(['Samsung', 'Galaxy S24 Ultra', '512GB', 5500.00]);
        $stmtDevice->execute(['Apple', 'iPhone 11', '64GB', 1400.00]);
        $stmtDevice->execute(['Apple', 'iPhone 12', '128GB', 2200.00]);
    }

    seed_demo_marketplace($pdo);
}

function seed_demo_marketplace(PDO $pdo): void
{
    $count = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE email='bytecell@lojist.com'")->fetchColumn();
    if ($count > 0) {
        return;
    }

    $users = [
        ['Carlos', 'Mendes', '22211133344', 'iphonecenter@lojist.com', '67991110001', 'iPhone Center CG', '@iphonecentercg', 'Campo Grande', 'MS', 'Avenida Afonso Pena, 2100', 'Pro', 'Gold', 4.9, 44, 18, '1h 40min', '3min', 4.9, 4.8, 'teste123'],
        ['Bianca', 'Rocha', '33322244455', 'bytecell@lojist.com', '11992220002', 'ByteCell Premium', '@bytecellpremium', 'São Paulo', 'SP', 'Rua Vergueiro, 1300', 'Elite', 'Diamond', 5.0, 96, 42, '55min', '2min', 5.0, 4.9, 'teste123'],
        ['Rafael', 'Costa', '44433355566', 'mobifast@lojist.com', '21993330003', 'MobiFast RJ', '@mobifastrj', 'Rio de Janeiro', 'RJ', 'Rua da Assembleia, 80', 'Free', 'Silver', 4.7, 27, 11, '3h 10min', '8min', 4.6, 4.7, 'teste123'],
        ['Larissa', 'Nunes', '55544466677', 'xiaomipro@lojist.com', '31994440004', 'Xiaomi Pro BH', '@xiaomiprobh', 'Belo Horizonte', 'MG', 'Avenida Amazonas, 920', 'Free', 'Bronze', 4.5, 12, 8, '5h 20min', '12min', 4.4, 4.5, 'teste123'],
        ['Eduardo', 'Lima', '66655577788', 'samsungprime@lojist.com', '41995550005', 'Samsung Prime Sul', '@samsungprimesul', 'Curitiba', 'PR', 'Rua XV de Novembro, 450', 'Pro', 'Gold', 4.8, 58, 30, '2h 05min', '5min', 4.8, 4.8, 'teste123'],
        ['Fernanda', 'Pires', '77766688899', 'appletrade@lojist.com', '51996660006', 'Apple Trade POA', '@appletradepoa', 'Porto Alegre', 'RS', 'Rua Mostardeiro, 700', 'Elite', 'Gold', 4.9, 63, 22, '1h 55min', '4min', 4.9, 4.9, 'teste123'],
    ];

    $stmt = $pdo->prepare('INSERT IGNORE INTO users (nome, sobrenome, cpf, email, telefone, nome_loja, instagram_loja, cidade, estado, endereco_completo, password_hash, status_conta, plano, nivel, nota_geral, vendas_concluidas, compras_concluidas, tempo_medio_envio, tempo_medio_pagamento, score_comprador, score_vendedor, aprovado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "aprovado", ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    foreach ($users as $u) {
        $stmt->execute([$u[0], $u[1], $u[2], $u[3], $u[4], $u[5], $u[6], $u[7], $u[8], $u[9], password_hash($u[19], PASSWORD_DEFAULT), $u[10], $u[11], $u[12], $u[13], $u[14], $u[15], $u[16], $u[17], $u[18]]);
    }

    $products = [
        ['iphonecenter@lojist.com', 'iPhone', 'Apple', 'iPhone 15 Pro Max', '256 GB', 'Titânio Natural', 'seminovo', 6890, 6100, 'Excelente', '94%', 'Tela original, Bateria original, Face ID OK, True Tone OK, Sem detalhes, Venda expressa', '359900000000101', 1, 1, 'Retirada local, Motoboy parceiro'],
        ['iphonecenter@lojist.com', 'iPhone', 'Apple', 'iPhone 13', '128 GB', 'Azul', 'seminovo', 2890, 2480, 'Muito bom', '88%', 'Carcaça com marcas leves, Câmera OK, Aparelho revisado', '359900000000102', 1, 0, 'Retirada local, Transportadora'],
        ['bytecell@lojist.com', 'iPhone', 'Apple', 'iPhone 15', '128 GB', 'Preto', 'lacrado', 4790, 4300, null, null, 'Nacional, Nota fiscal, Garantia', '', 0, 1, 'Entrega própria, Transportadora'],
        ['bytecell@lojist.com', 'iPhone', 'Apple', 'iPhone 14 Pro', '256 GB', 'Roxo Profundo', 'seminovo', 5290, 4700, 'Excelente', '90%', 'Tela original, Tampa original, Face ID OK, Sem detalhes', '359900000000103', 1, 1, 'Motoboy parceiro, Transportadora'],
        ['mobifast@lojist.com', 'Samsung', 'Samsung', 'Galaxy S24 Ultra', '512 GB', 'Titânio Cinza', 'seminovo', 5790, 5150, 'Excelente', null, 'Câmera OK, Carcaça sem marcas, Aparelho revisado', '359900000000104', 1, 1, 'Retirada local, Motoboy parceiro'],
        ['mobifast@lojist.com', 'iPhone', 'Apple', 'iPhone 12 Pro', '128 GB', 'Grafite', 'seminovo', 2690, 2300, 'Bom', '84%', 'Tela original, Bateria trocada, Face ID OK, Com detalhes', '359900000000105', 1, 0, 'Retirada local'],
        ['xiaomipro@lojist.com', 'Xiaomi', 'Xiaomi', 'Xiaomi 13T Pro', '512 GB', 'Preto', 'lacrado', 3190, 2800, null, null, 'Importado, Garantia, Nota fiscal', '', 1, 1, 'Transportadora'],
        ['samsungprime@lojist.com', 'Samsung', 'Samsung', 'Galaxy Z Flip5', '256 GB', 'Lavanda', 'seminovo', 3190, 2750, 'Muito bom', null, 'Tela original, Câmera OK, Carcaça com marcas leves', '359900000000106', 1, 0, 'Retirada local, Entrega própria'],
        ['appletrade@lojist.com', 'iPhone', 'Apple', 'iPhone 11', '64 GB', 'Branco', 'seminovo', 1790, 1450, 'Bom', '82%', 'Aparelho revisado, Carcaça com marcas leves, True Tone OK', '359900000000107', 1, 0, 'Retirada local, Transportadora'],
        ['appletrade@lojist.com', 'iPhone', 'Apple', 'iPhone 15 Pro', '128 GB', 'Titânio Azul', 'lacrado', 5990, 5500, null, null, 'Nacional, Nota fiscal, Garantia, Venda expressa', '', 0, 1, 'Entrega própria, Motoboy parceiro, Transportadora'],
    ];

    $sellerStmt = $pdo->prepare('SELECT id, cidade, estado FROM users WHERE email = ?');
    $exists = $pdo->prepare('SELECT COUNT(*) FROM products WHERE imei_interno = ? OR serial_number = ? OR (modelo = ? AND vendedor_id = ? AND preco = ?)');
    $insert = $pdo->prepare('INSERT INTO products (vendedor_id, categoria, marca, modelo, armazenamento, cor, tipo, preco, custo_privado, quantidade, estado_geral, bateria, face_id, true_tone, icloud_livre, defeito, peca_trocada, detalhes_estruturados, imei_interno, serial_number, fotos, status, aceita_oferta, venda_expressa, metodos_entrega, cidade, estado, preco_ajustado_recentemente) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "disponivel", ?, ?, ?, ?, ?, ?)');
    foreach ($products as $p) {
        $sellerStmt->execute([$p[0]]);
        $seller = $sellerStmt->fetch();
        if (!$seller) { continue; }
        $imeiCheck = trim((string)$p[12]) !== '' ? $p[12] : '__sem_imei_' . $p[3] . '_' . $seller['id'] . '_' . $p[7];
        $exists->execute([$imeiCheck, $imeiCheck, $p[3], $seller['id'], $p[7]]);
        if ((int)$exists->fetchColumn() > 0) { continue; }
        $photos = 'assets/img/phone-placeholder.svg,assets/img/logo.png,assets/img/phone-placeholder.svg';
        $insert->execute([$seller['id'], $p[1], $p[2], $p[3], $p[4], $p[5], $p[6], $p[7], $p[8], $p[9], $p[10], 'Sim', 'Sim', 'Sim', 'Não', 'Não', $p[11], $p[12], $p[12], $photos, $p[13], $p[14], $p[15], $seller['cidade'], $seller['estado'], rand(0, 1)]);
    }

    $buyer = (int)$pdo->query("SELECT id FROM users WHERE email='bytecell@lojist.com'")->fetchColumn();
    $seller = (int)$pdo->query("SELECT id FROM users WHERE email='iphonecenter@lojist.com'")->fetchColumn();
    $product = (int)$pdo->query("SELECT id FROM products WHERE modelo='iPhone 13' LIMIT 1")->fetchColumn();
    if ($buyer && $seller && $product) {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE produto_id=$product AND comprador_id=$buyer")->fetchColumn();
        if ($count === 0) {
            $pdo->prepare('INSERT INTO orders (produto_id, comprador_id, vendedor_id, valor_bruto, taxa_plataforma, valor_liquido, metodo_entrega, status, pix_qrcode, pix_status, pagamento_aprovado_em, enviado_em, entregue_em, destinatario, telefone_entrega, cep_entrega, endereco_entrega, cidade_entrega, estado_entrega, complemento_entrega, codigo_rastreio) VALUES (?, ?, ?, 2890, 43.35, 2846.65, "Transportadora", "entregue", "PIX-DEMO-LOJIST", "aprovado", NOW(), NOW(), NOW(), "Bianca Rocha", "11992220002", "01000-000", "Rua Vergueiro, 1300", "Sao Paulo", "SP", "Portaria comercial", "BRLOJIST123456")')->execute([$product, $buyer, $seller]);
            $orderId = (int)$pdo->lastInsertId();
            add_order_event($orderId, 'aguardando_pagamento', 'Pix gerado', 'Compra criada e aguardando pagamento Pix.', $buyer);
            add_order_event($orderId, 'pagamento_aprovado', 'Pagamento aprovado', 'Pix confirmado. Dados de entrega liberados ao vendedor.', $buyer);
            add_order_event($orderId, 'preparando_envio', 'Pedido em preparo', 'Vendedor iniciou a separacao do aparelho.', $seller);
            add_order_event($orderId, 'enviado', 'Produto enviado', 'Pedido despachado com rastreio BRLOJIST123456.', $seller);
            add_order_event($orderId, 'entregue', 'Produto entregue', 'Entrega marcada como concluida.', $seller);
        } else {
            $orderId = (int)$pdo->query("SELECT id FROM orders WHERE produto_id=$product AND comprador_id=$buyer LIMIT 1")->fetchColumn();
            if ($orderId) {
                $pdo->prepare('UPDATE orders SET destinatario=COALESCE(NULLIF(destinatario, ""), "Bianca Rocha"), telefone_entrega=COALESCE(NULLIF(telefone_entrega, ""), "11992220002"), cep_entrega=COALESCE(NULLIF(cep_entrega, ""), "01000-000"), endereco_entrega=COALESCE(NULLIF(endereco_entrega, ""), "Rua Vergueiro, 1300"), cidade_entrega=COALESCE(NULLIF(cidade_entrega, ""), "Sao Paulo"), estado_entrega=COALESCE(NULLIF(estado_entrega, ""), "SP"), complemento_entrega=COALESCE(NULLIF(complemento_entrega, ""), "Portaria comercial"), codigo_rastreio=COALESCE(NULLIF(codigo_rastreio, ""), "BRLOJIST123456") WHERE id=?')->execute([$orderId]);
                $events = (int)$pdo->query("SELECT COUNT(*) FROM order_events WHERE order_id=$orderId")->fetchColumn();
                if ($events === 0) {
                    add_order_event($orderId, 'aguardando_pagamento', 'Pix gerado', 'Compra criada e aguardando pagamento Pix.', $buyer);
                    add_order_event($orderId, 'pagamento_aprovado', 'Pagamento aprovado', 'Pix confirmado. Dados de entrega liberados ao vendedor.', $buyer);
                    add_order_event($orderId, 'preparando_envio', 'Pedido em preparo', 'Vendedor iniciou a separacao do aparelho.', $seller);
                    add_order_event($orderId, 'enviado', 'Produto enviado', 'Pedido despachado com rastreio BRLOJIST123456.', $seller);
                    add_order_event($orderId, 'entregue', 'Produto entregue', 'Entrega marcada como concluida.', $seller);
                }
            }
        }
    }

    foreach ($users as $u) {
        $id = (int)$pdo->query("SELECT id FROM users WHERE email=" . $pdo->quote($u[3]))->fetchColumn();
        if ($id) {
            add_notification($id, 'conta_aprovada', 'Conta aprovada', 'Seu acesso de teste está ativo para comprar, vender, ofertar e acompanhar Pix.');
        }
    }
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect('login');
    }
    if ($user['role'] !== 'admin' && $user['status_conta'] !== 'aprovado') {
        $_SESSION['flash'] = 'Sua conta ainda esta em analise. Voce sera notificado apos aprovacao.';
        redirect('login');
    }
    $page = $_GET['p'] ?? 'dashboard';
    $postAction = $_POST['action'] ?? '';
    if ($user['role'] !== 'admin' && !subscription_is_active($user) && !in_array($page, ['plans'], true) && $postAction !== 'pay_plan') {
        $_SESSION['flash'] = 'Seu teste gratuito expirou. Escolha um plano para continuar acessando a plataforma.';
        redirect('plans');
    }
    return $user;
}

function require_admin(): array
{
    $user = require_login();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        exit('Acesso restrito ao admin master.');
    }
    return $user;
}

function subscription_is_active(array $user): bool
{
    return subscription_state($user)['active'];
}

function subscription_state(array $user): array
{
    if (($user['role'] ?? '') === 'admin') {
        return ['active' => true, 'status' => 'admin', 'label' => 'Admin master', 'ends_at' => null, 'days_left' => null];
    }
    $now = new DateTimeImmutable('now');
    $paidUntil = !empty($user['paid_until']) ? new DateTimeImmutable((string)$user['paid_until']) : null;
    if ($paidUntil && $paidUntil >= $now) {
        return ['active' => true, 'status' => 'active', 'label' => 'Plano ativo', 'ends_at' => $paidUntil->format('Y-m-d H:i:s'), 'days_left' => max(0, (int)$now->diff($paidUntil)->format('%a'))];
    }
    $trialEnds = !empty($user['trial_ends_at']) ? new DateTimeImmutable((string)$user['trial_ends_at']) : null;
    if ($trialEnds && $trialEnds >= $now) {
        return ['active' => true, 'status' => 'trialing', 'label' => 'Teste grátis', 'ends_at' => $trialEnds->format('Y-m-d H:i:s'), 'days_left' => max(0, (int)$now->diff($trialEnds)->format('%a'))];
    }
    return ['active' => false, 'status' => 'expired', 'label' => 'Plano expirado', 'ends_at' => $paidUntil?->format('Y-m-d H:i:s') ?: $trialEnds?->format('Y-m-d H:i:s'), 'days_left' => 0];
}

function redirect(string $page, array $params = []): void
{
    $params = array_merge(['p' => $page], $params);
    $url = 'index.php?' . http_build_query($params);
    if (!headers_sent()) {
        header('Location: ' . $url);
    } else {
        echo '<!doctype html><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=' . e($url) . '"><script>location.replace(' . json_encode($url) . ');</script>';
    }
    exit;
}

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money(float $value): string
{
    return 'R$ ' . number_format($value, 2, ',', '.');
}

function local_time(?string $datetime): string
{
    if (!$datetime) {
        return '';
    }
    $iso = str_replace(' ', 'T', $datetime) . '-04:00';
    return '<time class="local-time" datetime="' . e($iso) . '">' . e($datetime) . '</time>';
}

function app_url(string $page, array $params = []): string
{
    $config = app_config();
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    $isLocalHost = $host !== '' && (str_contains($host, 'localhost') || str_contains($host, '127.0.0.1') || str_starts_with($host, '192.168.'));
    if ($isLocalHost) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    } else {
        $base = rtrim((string)($config['app_url'] ?? ''), '/');
        if ($base === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }
    }
    return $base . '/index.php?' . http_build_query(array_merge(['p' => $page], $params));
}

function send_transactional_email(?int $userId, string $to, string $subject, string $html, string $type = 'geral'): bool
{
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $config = app_config();
    $from = (string)($config['mail_from'] ?? 'naoresponda@lojist.com.br');
    $fromName = (string)($config['mail_from_name'] ?? 'LOJIST');
    $replyTo = (string)($config['mail_reply_to'] ?? $from);
    $plain = trim(preg_replace('/\s+/', ' ', strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html))) ?? '');
    $body = '<!doctype html><html><head><meta charset="utf-8"></head><body style="margin:0;background:#07111f;color:#fff;font-family:Arial,sans-serif">'
        . '<div style="max-width:640px;margin:0 auto;padding:32px 22px">'
        . '<div style="font-size:24px;font-weight:800;letter-spacing:.04em;color:#28a8ff">LOJIST</div>'
        . '<div style="margin-top:22px;padding:24px;border:1px solid #163457;border-radius:14px;background:#0d1b2d">'
        . $html
        . '</div><p style="color:#7fa8d7;font-size:12px;margin-top:20px">Mensagem automatica enviada por ' . e($from) . '. Nao responda este e-mail.</p>'
        . '</div></body></html>';

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . safe_mime_header($fromName) . ' <' . $from . '>',
        'Reply-To: ' . $replyTo,
        'X-Mailer: LOJIST',
    ];

    $sent = false;
    $error = null;
    try {
        if (($config['app_env'] ?? 'local') === 'local') {
            // Local environment: bypass mail to prevent sendmail timeouts hanging the application
            $sent = true;
            $error = 'Email bypassed on local environment.';
        } else {
            $sent = @mail($to, safe_mime_header($subject), $body, implode("\r\n", $headers));
            if (!$sent) {
                $error = 'A funcao mail() retornou falso. Verifique o SMTP/sendmail da hospedagem.';
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    try {
        db()->prepare('INSERT INTO email_logs (user_id, email, assunto, tipo, status, erro) VALUES (?, ?, ?, ?, ?, ?)')->execute([
            $userId,
            $to,
            $subject,
            $type,
            $sent ? 'enviado' : 'falhou',
            $error,
        ]);
    } catch (Throwable) {
        // E-mail nao pode derrubar o fluxo principal da plataforma.
    }

    if (!$sent) {
        log_system($userId, 'email_falhou', 'email', null, 'Falha ao enviar e-mail', $subject . ' para ' . $to . '. ' . ($error ?? ''));
    }

    return $sent;
}

function safe_mime_header(string $value): string
{
    if (function_exists('mb_encode_mimeheader')) {
        return mb_encode_mimeheader($value, 'UTF-8');
    }
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function safe_substr(string $value, int $start, int $length): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, $start, $length, 'UTF-8');
    }
    return substr($value, $start, $length);
}

function email_user(int $userId, string $subject, string $html, string $type = 'geral'): bool
{
    $stmt = db()->prepare('SELECT email FROM users WHERE id=?');
    $stmt->execute([$userId]);
    $email = (string)$stmt->fetchColumn();
    return send_transactional_email($userId, $email, $subject, $html, $type);
}

function send_password_reset_email(array $user): void
{
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    db()->prepare('DELETE FROM password_reset_tokens WHERE user_id=? AND (used_at IS NOT NULL OR expires_at < NOW())')->execute([(int)$user['id']]);
    $expires = date('Y-m-d H:i:s', strtotime('+45 minutes'));
    db()->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, ip) VALUES (?, ?, ?, ?)')->execute([
        $user['id'],
        $hash,
        $expires,
        $_SERVER['REMOTE_ADDR'] ?? ''
    ]);
    $url = app_url('reset-password', ['token' => $token]);
    send_transactional_email((int)$user['id'], (string)$user['email'], 'Recuperacao de senha LOJIST',
        '<h1 style="margin:0 0 14px">Recuperar senha</h1><p>Recebemos uma solicitacao para redefinir sua senha na LOJIST.</p><p>O link abaixo expira em 45 minutos e so pode ser usado uma vez.</p><p><a style="display:inline-block;background:#10a9ff;color:#fff;text-decoration:none;padding:12px 18px;border-radius:10px;font-weight:700" href="' . e($url) . '">Redefinir senha</a></p><p style="color:#9bb7d8">Se voce nao solicitou, ignore este e-mail.</p>',
        'recuperacao_senha'
    );
}

function flash(): ?string
{
    $msg = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $msg;
}

function post(string $key, mixed $default = ''): mixed
{
    return $_POST[$key] ?? $default;
}

function selected(string $value, ?string $current): string
{
    return $value === $current ? 'selected' : '';
}

function checked(string $value, array $current): string
{
    return in_array($value, $current, true) ? 'checked' : '';
}

function plan_for(string $name): array
{
    $stmt = db()->prepare('SELECT * FROM plans WHERE nome = ?');
    $stmt->execute([$name]);
    return $stmt->fetch() ?: ['nome' => 'Free', 'taxa' => 1.5, 'limite_anuncios' => 5, 'filtros_avancados' => 0, 'preco_mensal' => 0, 'especial' => 0];
}

function active_products_count(int $userId): int
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM products WHERE vendedor_id = ? AND status = 'disponivel'");
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

function duplicate_product_exists(int $sellerId, string $modelo, string $armazenamento, string $cor, string $serial = '', ?int $ignoreId = null): bool
{
    $where = 'vendedor_id = ? AND status NOT IN ("cancelado","finalizado") AND (LOWER(modelo)=LOWER(?) AND LOWER(armazenamento)=LOWER(?) AND LOWER(cor)=LOWER(?)';
    $args = [$sellerId, trim($modelo), trim($armazenamento), trim($cor)];
    if (trim($serial) !== '') {
        $where .= ' OR imei_interno = ? OR serial_number = ?';
        $args[] = trim($serial);
        $args[] = trim($serial);
    }
    $where .= ')';
    if ($ignoreId !== null) {
        $where .= ' AND id <> ?';
        $args[] = $ignoreId;
    }
    $stmt = db()->prepare("SELECT COUNT(*) FROM products WHERE {$where}");
    $stmt->execute($args);
    return (int)$stmt->fetchColumn() > 0;
}

function register_duplicate_warning(int $sellerId, string $productLabel): int
{
    $pdo = db();
    $pdo->prepare('UPDATE users SET duplicate_warnings = duplicate_warnings + 1 WHERE id=?')->execute([$sellerId]);
    $stmt = $pdo->prepare('SELECT duplicate_warnings FROM users WHERE id=?');
    $stmt->execute([$sellerId]);
    $warnings = (int)$stmt->fetchColumn();
    add_notification($sellerId, 'anuncio_duplicado', 'Aviso de anúncio duplicado', 'O sistema detectou tentativa de duplicar "' . $productLabel . '". Com 3 avisos a conta é suspensa para análise.');
    if ($warnings >= 3) {
        $pdo->prepare("UPDATE users SET status_conta='suspenso' WHERE id=? AND role='lojista'")->execute([$sellerId]);
        add_notification($sellerId, 'conta_suspensa', 'Conta suspensa para análise', 'Sua conta recebeu 3 avisos de duplicidade e foi removida temporariamente da plataforma para revisão do admin.');
    }
    return $warnings;
}

function market_value_for(string $modelo, string $armazenamento = ''): array
{
    $pdo = db();
    $model = trim($modelo);
    $storage = trim($armazenamento);
    if ($model === '') {
        return ['avg' => 0.0, 'min' => 0.0, 'max' => 0.0, 'count' => 0, 'source' => 'sem dados'];
    }
    $args = ['%' . $model . '%'];
    $storageSql = '';
    if ($storage !== '') {
        $storageSql = ' AND p.armazenamento LIKE ?';
        $args[] = '%' . $storage . '%';
    }
    $stmt = $pdo->prepare('SELECT AVG(o.valor_bruto) media, MIN(o.valor_bruto) minimo, MAX(o.valor_bruto) maximo, COUNT(*) total FROM orders o JOIN products p ON p.id=o.produto_id WHERE p.modelo LIKE ?' . $storageSql . ' AND o.status IN ("pagamento_aprovado","preparando_envio","enviado","entregue","finalizado")');
    $stmt->execute($args);
    $row = $stmt->fetch() ?: [];
    if ((int)($row['total'] ?? 0) > 0) {
        return ['avg' => (float)$row['media'], 'min' => (float)$row['minimo'], 'max' => (float)$row['maximo'], 'count' => (int)$row['total'], 'source' => 'vendas concluídas e Pix aprovados'];
    }
    $stmt = $pdo->prepare('SELECT AVG(preco) media, MIN(preco) minimo, MAX(preco) maximo, COUNT(*) total FROM products p WHERE p.modelo LIKE ?' . $storageSql . ' AND p.status IN ("disponivel","reservado","pagamento_aprovado","enviado","entregue","finalizado")');
    $stmt->execute($args);
    $row = $stmt->fetch() ?: [];
    return ['avg' => (float)($row['media'] ?? 0), 'min' => (float)($row['minimo'] ?? 0), 'max' => (float)($row['maximo'] ?? 0), 'count' => (int)($row['total'] ?? 0), 'source' => 'anúncios ativos da plataforma'];
}

function market_insight_for(string $modelo, string $armazenamento, float $price, ?float $cost = null): array
{
    $market = market_value_for($modelo, $armazenamento);
    $avg = (float)($market['avg'] ?? 0);
    $suggested = $avg > 0 ? round($avg, 2) : round($price * 1.08, 2);
    $spread = round($suggested - $price, 2);
    $buyerMargin = $suggested > 0 ? round(($spread / $suggested) * 100, 1) : 0.0;
    $sellerProfit = $cost !== null ? round($price - $cost, 2) : null;
    $sellerMargin = $cost !== null && $price > 0 ? round(($sellerProfit / $price) * 100, 1) : null;
    $verdict = $spread >= ($suggested * 0.08) ? 'Boa oportunidade' : ($spread >= 0 ? 'Margem apertada' : 'Acima do mercado');
    return [
        'market' => $market,
        'suggested' => $suggested,
        'spread' => $spread,
        'buyer_margin' => $buyerMargin,
        'seller_profit' => $sellerProfit,
        'seller_margin' => $sellerMargin,
        'verdict' => $verdict,
    ];
}

function blocks_external_contact(string $text): bool
{
    $patterns = [
        '/\b(whatsapp|telefone|zap|wpp|insta|instagram|chama|me procura|loja\s+\w+)\b/i',
        '/@\w+/',
        '/https?:\/\//i',
        '/www\./i',
        '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i',
        '/(?:\(?\d{2}\)?\s?)?(?:9\d{4}|\d{4})[-\s]?\d{4}/',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text)) {
            return true;
        }
    }
    return false;
}

function store_upload(string $field): ?string
{
    if (empty($_FILES[$field]['name']) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $tmp = $_FILES[$field]['tmp_name'];
    $info = @getimagesize($tmp);
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'pdf'];
    if (!in_array($ext, $allowed, true)) {
        return null;
    }
    if (!$info && !in_array($ext, ['heic', 'heif', 'pdf'], true)) {
        return null;
    }
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0777, true);
    }
    if ($ext === 'pdf' || in_array($ext, ['heic', 'heif'], true)) {
        $name = uniqid('lojist_', true) . '.' . $ext;
        $dest = UPLOAD_DIR . '/' . $name;
        return move_uploaded_file($tmp, $dest) ? 'uploads/' . $name : null;
    }
    $name = uniqid('lojist_', true) . '.' . (function_exists('imagecreatetruecolor') ? 'jpg' : ($ext === 'jpeg' ? 'jpg' : $ext));
    $dest = UPLOAD_DIR . '/' . $name;
    if (function_exists('imagecreatetruecolor') && $info) {
        watermark_image($tmp, $dest, $info['mime']);
    } else {
        move_uploaded_file($tmp, $dest);
    }
    return 'uploads/' . $name;
}

function store_uploads(string $field): array
{
    if (empty($_FILES[$field]['name'])) {
        return [];
    }

    $saved = [];
    $names = $_FILES[$field]['name'];
    if (!is_array($names)) {
        $one = store_upload($field);
        return $one ? [$one] : [];
    }

    $count = min(count($names), 8);
    for ($i = 0; $i < $count; $i++) {
        if (($_FILES[$field]['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }
        $_FILES['_single_upload'] = [
            'name' => $_FILES[$field]['name'][$i],
            'type' => $_FILES[$field]['type'][$i],
            'tmp_name' => $_FILES[$field]['tmp_name'][$i],
            'error' => $_FILES[$field]['error'][$i],
            'size' => $_FILES[$field]['size'][$i],
        ];
        $path = store_upload('_single_upload');
        if ($path) {
            $saved[] = $path;
        }
    }
    unset($_FILES['_single_upload']);
    return $saved;
}

function watermark_image(string $tmp, string $dest, string $mime): void
{
    $src = match ($mime) {
        'image/jpeg' => imagecreatefromjpeg($tmp),
        'image/png' => imagecreatefrompng($tmp),
        'image/webp' => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($tmp) : null,
        default => null,
    };
    if (!$src) {
        move_uploaded_file($tmp, $dest);
        return;
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $blue = imagecolorallocatealpha($src, 0, 133, 255, 72);
    $white = imagecolorallocatealpha($src, 255, 255, 255, 70);
    $shadow = imagecolorallocatealpha($src, 0, 194, 255, 84);
    $word = 'LOJIST';
    $x = max(22, (int)($w / 2) - 36);
    $y = min($h - 22, (int)($h / 2) + 92);
    imagestringup($src, 5, $x + 3, $y + 3, $word, $shadow);
    imagestringup($src, 5, $x, $y, $word, $white);
    imagestring($src, 3, 18, max(16, $h - 36), 'LOJIST ID ' . substr(session_id(), 0, 8), $blue);
    imagejpeg($src, $dest, 88);
    imagedestroy($src);
}

function product_image(array $product): string
{
    $photos = array_filter(explode(',', (string)$product['fotos']));
    $first = trim((string)($photos[0] ?? ''));
    if ($first === '' || str_contains($first, 'phone-placeholder.svg') || str_contains($first, 'assets/img/logo')) {
        return 'assets/img/product-default.png';
    }
    return $first;
}

function product_images(array $product): array
{
    $photos = array_values(array_filter(array_map('trim', explode(',', (string)$product['fotos']))));
    $photos = array_values(array_filter($photos, static fn(string $photo): bool => !str_contains($photo, 'phone-placeholder.svg') && !str_contains($photo, 'assets/img/logo')));
    return $photos ?: ['assets/img/product-default.png'];
}

function add_notification(int $userId, string $tipo, string $titulo, string $mensagem): void
{
    $stmt = db()->prepare('INSERT INTO notifications (user_id, tipo, titulo, mensagem) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId, $tipo, $titulo, $mensagem]);
    log_system($userId, $tipo, 'notificacao', (int)db()->lastInsertId(), $titulo, $mensagem);
    $emailTypes = [
        'cadastro_aprovado',
        'cadastro_recusado',
        'conta_suspensa',
        'oferta_recebida',
        'oferta_aceita',
        'oferta_recusada',
        'pix_gerado',
        'pagamento_aprovado',
        'pedido_preparando_envio',
        'pedido_enviado',
        'pedido_entregue',
        'pedido_finalizado',
        'pedido_cancelado',
        'plano_pix_gerado',
        'plano_pago',
        'avaliacao_recebida',
        'duplicidade_detectada',
        'nova_solicitacao',
    ];
    if (in_array($tipo, $emailTypes, true)) {
        email_user($userId, 'LOJIST - ' . $titulo, '<h1 style="margin:0 0 14px">' . e($titulo) . '</h1><p>' . e($mensagem) . '</p><p><a style="display:inline-block;background:#10a9ff;color:#fff;text-decoration:none;padding:12px 18px;border-radius:10px;font-weight:700" href="' . e(app_url('notifications')) . '">Abrir plataforma</a></p>', $tipo);
    }
}

function log_system(?int $userId, string $tipo, ?string $entidade, ?int $entidadeId, string $titulo, string $mensagem): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $agent = isset($_SERVER['HTTP_USER_AGENT']) ? safe_substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : null;
    $stmt = db()->prepare('INSERT INTO system_logs (user_id, tipo, entidade, entidade_id, titulo, mensagem, ip, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$userId, $tipo, $entidade, $entidadeId, $titulo, $mensagem, $ip, $agent]);
}

function add_order_event(int $orderId, string $status, string $titulo, string $mensagem, ?int $userId = null): void
{
    $stmt = db()->prepare('INSERT INTO order_events (order_id, status, titulo, mensagem, criado_por) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$orderId, $status, $titulo, $mensagem, $userId]);
    log_system($userId, 'pedido_' . $status, 'pedido', $orderId, $titulo, $mensagem);
}

function order_events(int $orderId): array
{
    $stmt = db()->prepare('SELECT * FROM order_events WHERE order_id = ? ORDER BY data ASC');
    $stmt->execute([$orderId]);
    return $stmt->fetchAll();
}

function refresh_user_reputation(int $userId): void
{
    $pdo = db();
    $salesDone = (int)$pdo->query('SELECT COUNT(*) FROM orders WHERE vendedor_id=' . (int)$userId . ' AND status="finalizado"')->fetchColumn();
    $salesCancelled = (int)$pdo->query('SELECT COUNT(*) FROM orders WHERE vendedor_id=' . (int)$userId . ' AND status="cancelado"')->fetchColumn();
    $purchasesDone = (int)$pdo->query('SELECT COUNT(*) FROM orders WHERE comprador_id=' . (int)$userId . ' AND status IN ("pagamento_aprovado","preparando_envio","enviado","entregue","finalizado")')->fetchColumn();
    $expired = (int)$pdo->query('SELECT COUNT(*) FROM offers WHERE comprador_id=' . (int)$userId . ' AND status="expirada"')->fetchColumn();
    $reviews = $pdo->query('SELECT COALESCE(AVG(nota), 5) media FROM reviews WHERE avaliado_id=' . (int)$userId)->fetch();
    $note = round((float)($reviews['media'] ?? 5), 2);

    $cancelRate = ($salesDone + $salesCancelled) > 0 ? round(($salesCancelled / ($salesDone + $salesCancelled)) * 100, 2) : 0;
    $sellerScore = max(1, min(5, round($note - ($cancelRate / 50) + min(0.35, $salesDone / 120), 2)));
    $buyerScore = max(1, min(5, round(5 - min(2, $expired * 0.18) + min(0.3, $purchasesDone / 100), 2)));
    $general = round(($note + $sellerScore + $buyerScore) / 3, 2);
    $level = 'Bronze';
    if ($general >= 4.9 && ($salesDone + $purchasesDone) >= 35 && $cancelRate <= 2) {
        $level = 'Diamond';
    } elseif ($general >= 4.75 && ($salesDone + $purchasesDone) >= 18 && $cancelRate <= 5) {
        $level = 'Gold';
    } elseif ($general >= 4.4 && ($salesDone + $purchasesDone) >= 6) {
        $level = 'Silver';
    }

    $stmt = $pdo->prepare('UPDATE users SET nota_geral=?, vendas_concluidas=?, compras_concluidas=?, taxa_cancelamento=?, score_vendedor=?, score_comprador=?, reservas_expiradas=?, nivel=? WHERE id=?');
    $stmt->execute([$general, $salesDone, $purchasesDone, $cancelRate, $sellerScore, $buyerScore, $expired, $level, $userId]);
}

function calculate_fee(float $value, string $plan): array
{
    $planData = plan_for($plan);
    $fee = round($value * ((float)$planData['taxa'] / 100), 2);
    return [$fee, round($value - $fee, 2), (float)$planData['taxa']];
}

function active_payment_provider(): string
{
    $provider = strtolower((string)(app_config()['payment_provider'] ?? 'asaas'));
    return in_array($provider, ['asaas', 'infinitepay'], true) ? $provider : 'asaas';
}

function create_gateway_order_payment(int $orderId): array
{
    return active_payment_provider() === 'infinitepay'
        ? infinitepay_create_order_payment($orderId)
        : asaas_create_order_payment($orderId);
}

function create_gateway_plan_payment(int $planPaymentId): array
{
    return active_payment_provider() === 'infinitepay'
        ? infinitepay_create_plan_payment($planPaymentId)
        : asaas_create_plan_payment($planPaymentId);
}

function gateway_is_configured(): bool
{
    return active_payment_provider() === 'infinitepay' ? infinitepay_is_configured() : asaas_is_configured();
}

function gateway_name(): string
{
    return active_payment_provider() === 'infinitepay' ? 'InfinitePay' : 'Asaas';
}

function asaas_is_configured(): bool
{
    $config = app_config();
    $key = trim((string)($config['asaas_api_key'] ?? ''));
    return $key !== '' && !str_starts_with($key, 'COLE_AQUI');
}

function infinitepay_is_configured(): bool
{
    $handle = trim((string)(app_config()['infinitepay_handle'] ?? ''));
    return $handle !== '' && !str_starts_with($handle, 'COLE_AQUI');
}

function infinitepay_request(string $method, string $path, array $payload = []): array
{
    if (!infinitepay_is_configured()) {
        throw new RuntimeException('Configure o handle da InfinitePay em lojist_config.php.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('A extensao cURL do PHP precisa estar ativa para conectar na InfinitePay.');
    }
    $ch = curl_init('https://api.checkout.infinitepay.io' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'User-Agent: LOJIST/1.0'],
        CURLOPT_TIMEOUT => 35,
    ]);
    if ($payload !== []) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        throw new RuntimeException('Falha ao conectar na InfinitePay: ' . $error);
    }
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        $data = ['raw' => $raw];
    }
    if ($status < 200 || $status >= 300) {
        $message = $data['message'] ?? $data['error'] ?? 'Erro retornado pela InfinitePay.';
        throw new RuntimeException('InfinitePay HTTP ' . $status . ': ' . $message);
    }
    return $data;
}

function asaas_base_url(): string
{
    $config = app_config();
    return ($config['asaas_environment'] ?? 'production') === 'sandbox'
        ? 'https://api-sandbox.asaas.com/v3'
        : 'https://api.asaas.com/v3';
}

function asaas_request(string $method, string $path, array $payload = []): array
{
    if (!asaas_is_configured()) {
        throw new RuntimeException('Configure a chave da API do Asaas em lojist_config.php.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('A extensao cURL do PHP precisa estar ativa para conectar no Asaas.');
    }
    $config = app_config();
    $ch = curl_init(asaas_base_url() . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'User-Agent: LOJIST/1.0',
            'access_token: ' . (string)$config['asaas_api_key'],
        ],
        CURLOPT_TIMEOUT => 35,
    ]);
    if ($payload !== []) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        throw new RuntimeException('Falha ao conectar no Asaas: ' . $error);
    }
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        $data = ['raw' => $raw];
    }
    if ($status < 200 || $status >= 300) {
        $message = $data['errors'][0]['description'] ?? $data['message'] ?? 'Erro retornado pelo Asaas.';
        throw new RuntimeException('Asaas HTTP ' . $status . ': ' . $message);
    }
    return $data;
}

function only_digits(?string $value): string
{
    return preg_replace('/\D+/', '', (string)$value);
}

function asaas_ensure_customer(array $user): string
{
    if (!empty($user['asaas_customer_id'])) {
        return (string)$user['asaas_customer_id'];
    }
    $payload = [
        'name' => trim((string)($user['nome_loja'] ?: ($user['nome'] . ' ' . $user['sobrenome']))),
        'email' => (string)$user['email'],
        'cpfCnpj' => only_digits((string)($user['cnpj'] ?: $user['cpf'])),
        'mobilePhone' => only_digits((string)$user['telefone']),
        'externalReference' => 'lojist_user_' . (int)$user['id'],
        'notificationDisabled' => true,
    ];
    $customer = asaas_request('POST', '/customers', $payload);
    if (empty($customer['id'])) {
        throw new RuntimeException('O Asaas não retornou o ID do cliente.');
    }
    db()->prepare('UPDATE users SET asaas_customer_id=? WHERE id=?')->execute([(string)$customer['id'], (int)$user['id']]);
    return (string)$customer['id'];
}

function asaas_create_order_payment(int $orderId): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT o.*, p.modelo, comprador.*, vendedor.asaas_wallet_id, vendedor.plano vendedor_plano FROM orders o JOIN products p ON p.id=o.produto_id JOIN users comprador ON comprador.id=o.comprador_id JOIN users vendedor ON vendedor.id=o.vendedor_id WHERE o.id=?');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) {
        throw new RuntimeException('Pedido não encontrado para cobrança Asaas.');
    }
    if (empty($order['asaas_wallet_id'])) {
        throw new RuntimeException('O vendedor ainda não possui walletId do Asaas configurado para receber split.');
    }
    $customerId = asaas_ensure_customer($order);
    $plan = plan_for((string)$order['vendedor_plano']);
    $sellerPercent = max(0, round(100 - max(0, min(100, (float)$plan['taxa'])), 4));
    $payment = asaas_request('POST', '/payments', [
        'customer' => $customerId,
        'billingType' => 'PIX',
        'value' => (float)$order['valor_bruto'],
        'dueDate' => date('Y-m-d', strtotime('+1 day')),
        'description' => 'LOJIST - Pedido #' . $orderId . ' - ' . (string)$order['modelo'],
        'externalReference' => 'lojist_order_' . $orderId,
        'postalService' => false,
        'split' => [
            ['walletId' => (string)$order['asaas_wallet_id'], 'percentualValue' => $sellerPercent],
        ],
    ]);
    $paymentId = (string)($payment['id'] ?? '');
    if ($paymentId === '') {
        throw new RuntimeException('O Asaas não retornou o ID da cobrança.');
    }
    $qr = asaas_request('GET', '/payments/' . rawurlencode($paymentId) . '/pixQrCode');
    $pix = (string)($qr['payload'] ?? $qr['encodedImage'] ?? $payment['invoiceUrl'] ?? $paymentId);
    $pdo->prepare('UPDATE payments SET gateway="Asaas", pix_qrcode=?, asaas_payment_id=?, asaas_invoice_url=?, webhook_data=? WHERE order_id=?')->execute([
        $pix,
        $paymentId,
        (string)($payment['invoiceUrl'] ?? ''),
        json_encode(['payment' => $payment, 'pixQrCode' => $qr], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $orderId,
    ]);
    $pdo->prepare('UPDATE orders SET pix_qrcode=? WHERE id=?')->execute([$pix, $orderId]);
    log_system((int)$order['comprador_id'], 'asaas_pix_criado', 'order', $orderId, 'Pix Asaas gerado', 'Cobrança Pix com split criada no Asaas para o pedido #' . $orderId . '.');
    return ['payment' => $payment, 'pixQrCode' => $qr, 'pix' => $pix];
}

function asaas_create_plan_payment(int $planPaymentId): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT pp.*, u.* FROM plan_payments pp JOIN users u ON u.id=pp.user_id WHERE pp.id=?');
    $stmt->execute([$planPaymentId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Pagamento de plano não encontrado.');
    }
    $customerId = asaas_ensure_customer($row);
    $payment = asaas_request('POST', '/payments', [
        'customer' => $customerId,
        'billingType' => 'PIX',
        'value' => (float)$row['valor'],
        'dueDate' => date('Y-m-d', strtotime('+1 day')),
        'description' => 'LOJIST - Plano mensal',
        'externalReference' => 'lojist_plan_' . $planPaymentId,
        'postalService' => false,
    ]);
    $paymentId = (string)($payment['id'] ?? '');
    if ($paymentId === '') {
        throw new RuntimeException('O Asaas não retornou o ID da cobrança do plano.');
    }
    $qr = asaas_request('GET', '/payments/' . rawurlencode($paymentId) . '/pixQrCode');
    $pix = (string)($qr['payload'] ?? $qr['encodedImage'] ?? $payment['invoiceUrl'] ?? $paymentId);
    $pdo->prepare('UPDATE plan_payments SET status="pendente", pix_qrcode=?, asaas_payment_id=?, asaas_invoice_url=?, webhook_data=? WHERE id=?')->execute([
        $pix,
        $paymentId,
        (string)($payment['invoiceUrl'] ?? ''),
        json_encode(['payment' => $payment, 'pixQrCode' => $qr], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $planPaymentId,
    ]);
    return ['payment' => $payment, 'pixQrCode' => $qr, 'pix' => $pix];
}

function infinitepay_create_order_payment(int $orderId): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT o.*, p.modelo, comprador.email, comprador.nome, comprador.sobrenome FROM orders o JOIN products p ON p.id=o.produto_id JOIN users comprador ON comprador.id=o.comprador_id WHERE o.id=?');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) {
        throw new RuntimeException('Pedido não encontrado para checkout InfinitePay.');
    }
    $handle = trim((string)app_config()['infinitepay_handle']);
    $orderNsu = 'lojist_order_' . $orderId;
    $payload = [
        'handle' => $handle,
        'redirect_url' => app_url('checkout', ['id' => $orderId]),
        'webhook_url' => app_url('infinitepay-webhook'),
        'order_nsu' => $orderNsu,
        'items' => [[
            'name' => 'LOJIST - Pedido #' . $orderId . ' - ' . (string)$order['modelo'],
            'quantity' => 1,
            'price' => (int)round((float)$order['valor_bruto'] * 100),
        ]],
        'customer' => [
            'name' => trim((string)$order['nome'] . ' ' . (string)$order['sobrenome']),
            'email' => (string)$order['email'],
        ],
        'payment_methods' => ['pix'],
    ];
    $checkout = infinitepay_request('POST', '/links', $payload);
    $url = (string)($checkout['url'] ?? $checkout['checkout_url'] ?? $checkout['link'] ?? '');
    if ($url === '') {
        throw new RuntimeException('A InfinitePay não retornou o link de pagamento.');
    }
    $pdo->prepare('UPDATE payments SET gateway="InfinitePay", pix_qrcode=?, infinitepay_order_nsu=?, infinitepay_checkout_url=?, webhook_data=? WHERE order_id=?')->execute([
        $url,
        $orderNsu,
        $url,
        json_encode(['checkout' => $checkout], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $orderId,
    ]);
    $pdo->prepare('UPDATE orders SET pix_qrcode=? WHERE id=?')->execute([$url, $orderId]);
    log_system((int)$order['comprador_id'], 'infinitepay_checkout_criado', 'order', $orderId, 'Checkout InfinitePay gerado', 'Link de pagamento de teste criado para o pedido #' . $orderId . '.');
    return ['checkout' => $checkout, 'pix' => $url];
}

function infinitepay_create_plan_payment(int $planPaymentId): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT pp.*, u.email, u.nome, u.sobrenome FROM plan_payments pp JOIN users u ON u.id=pp.user_id WHERE pp.id=?');
    $stmt->execute([$planPaymentId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Pagamento de plano não encontrado.');
    }
    $handle = trim((string)app_config()['infinitepay_handle']);
    $orderNsu = 'lojist_plan_' . $planPaymentId;
    $checkout = infinitepay_request('POST', '/links', [
        'handle' => $handle,
        'redirect_url' => app_url('plan-checkout', ['id' => $planPaymentId]),
        'webhook_url' => app_url('infinitepay-webhook'),
        'order_nsu' => $orderNsu,
        'items' => [[
            'name' => 'LOJIST - Plano mensal',
            'quantity' => 1,
            'price' => (int)round((float)$row['valor'] * 100),
        ]],
        'customer' => [
            'name' => trim((string)$row['nome'] . ' ' . (string)$row['sobrenome']),
            'email' => (string)$row['email'],
        ],
        'payment_methods' => ['pix'],
    ]);
    $url = (string)($checkout['url'] ?? $checkout['checkout_url'] ?? $checkout['link'] ?? '');
    if ($url === '') {
        throw new RuntimeException('A InfinitePay não retornou o link do plano.');
    }
    $pdo->prepare('UPDATE plan_payments SET status="pendente", pix_qrcode=?, asaas_payment_id=NULL, asaas_invoice_url=?, webhook_data=? WHERE id=?')->execute([
        $url,
        $url,
        json_encode(['checkout' => $checkout, 'order_nsu' => $orderNsu], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $planPaymentId,
    ]);
    return ['checkout' => $checkout, 'pix' => $url];
}

function process_asaas_webhook(): void
{
    $config = app_config();
    $expected = (string)($config['asaas_webhook_token'] ?? '');
    $received = $_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] ?? $_SERVER['HTTP_ACCESS_TOKEN'] ?? '';
    if ($expected !== '' && !str_starts_with($expected, 'COLE_AQUI') && !hash_equals($expected, (string)$received)) {
        http_response_code(401);
        echo 'unauthorized';
        return;
    }
    $raw = file_get_contents('php://input') ?: '';
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo 'invalid json';
        return;
    }
    $event = (string)($payload['event'] ?? '');
    $payment = $payload['payment'] ?? [];
    $asaasId = (string)($payment['id'] ?? '');
    $external = (string)($payment['externalReference'] ?? '');
    $pdo = db();
    if ($asaasId !== '' && str_starts_with($external, 'lojist_order_')) {
        $orderId = (int)str_replace('lojist_order_', '', $external);
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id=?');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if ($order) {
            $pdo->prepare('UPDATE payments SET webhook_data=?, status = CASE WHEN ? IN ("PAYMENT_RECEIVED","PAYMENT_CONFIRMED") THEN "aprovado" ELSE status END, aprovado_em = CASE WHEN ? IN ("PAYMENT_RECEIVED","PAYMENT_CONFIRMED") THEN NOW() ELSE aprovado_em END WHERE asaas_payment_id=?')->execute([$raw, $event, $event, $asaasId]);
            if (in_array($event, ['PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED'], true) && $order['pix_status'] !== 'aprovado') {
                $pdo->prepare("UPDATE orders SET status='pagamento_aprovado', pix_status='aprovado', pagamento_aprovado_em=NOW() WHERE id=?")->execute([$orderId]);
                $pdo->prepare("UPDATE products SET status='pagamento_aprovado' WHERE id=?")->execute([$order['produto_id']]);
                add_order_event($orderId, 'pagamento_aprovado', 'Pagamento aprovado pelo Asaas', 'Webhook confirmou o Pix e o split automático foi acionado pelo Asaas.', (int)$order['comprador_id']);
                add_notification((int)$order['vendedor_id'], 'pagamento_aprovado', 'Pagamento aprovado', 'O Asaas confirmou o Pix e processou o split do pedido #' . $orderId . '.');
                add_notification((int)$order['comprador_id'], 'pagamento_aprovado', 'Pagamento aprovado', 'Seu Pix foi confirmado e os dados de entrega foram liberados para o vendedor. Pedido #' . $orderId . '.');
            }
            if (in_array($event, ['PAYMENT_OVERDUE', 'PAYMENT_DELETED'], true)) {
                $pdo->prepare("UPDATE payments SET status='cancelado' WHERE asaas_payment_id=?")->execute([$asaasId]);
                $pdo->prepare("UPDATE orders SET pix_status='expirado', status='cancelado' WHERE id=? AND pix_status <> 'aprovado'")->execute([$orderId]);
                $pdo->prepare("UPDATE products SET status='disponivel' WHERE id=? AND status='aguardando_pagamento'")->execute([$order['produto_id']]);
                add_notification((int)$order['comprador_id'], 'pedido_cancelado', 'Pix expirado', 'O Pix do pedido #' . $orderId . ' expirou ou foi cancelado. O aparelho voltou para o estoque.');
            }
        }
    } elseif ($asaasId !== '' && str_starts_with($external, 'lojist_plan_')) {
        $planPaymentId = (int)str_replace('lojist_plan_', '', $external);
        $stmt = $pdo->prepare('SELECT * FROM plan_payments WHERE id=?');
        $stmt->execute([$planPaymentId]);
        $planPayment = $stmt->fetch();
        if ($planPayment) {
            $pdo->prepare('UPDATE plan_payments SET webhook_data=?, status = CASE WHEN ? IN ("PAYMENT_RECEIVED","PAYMENT_CONFIRMED") THEN "aprovado" ELSE status END WHERE asaas_payment_id=?')->execute([$raw, $event, $asaasId]);
            if (in_array($event, ['PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED'], true)) {
                $pdo->prepare("UPDATE users SET subscription_status='active', paid_until=?, last_plan_payment_at=NOW(), plano=(SELECT nome FROM plans WHERE id=?) WHERE id=?")->execute([$planPayment['periodo_fim'], $planPayment['plan_id'], $planPayment['user_id']]);
                add_notification((int)$planPayment['user_id'], 'plano_pago', 'Plano ativado', 'Pagamento Pix confirmado pelo Asaas.');
            }
        }
    }
    http_response_code(200);
    echo 'ok';
}

function process_infinitepay_webhook(): void
{
    $config = app_config();
    $expected = (string)($config['infinitepay_webhook_secret'] ?? '');
    $received = $_SERVER['HTTP_X_LOJIST_TOKEN'] ?? $_GET['token'] ?? '';
    if ($expected !== '' && !str_starts_with($expected, 'COLE_AQUI') && !hash_equals($expected, (string)$received)) {
        http_response_code(401);
        echo 'unauthorized';
        return;
    }
    $raw = file_get_contents('php://input') ?: '';
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    $orderNsu = (string)($payload['order_nsu'] ?? $payload['orderNsu'] ?? $payload['reference'] ?? '');
    $status = strtolower((string)($payload['status'] ?? $payload['payment_status'] ?? ''));
    $paid = in_array($status, ['paid', 'approved', 'confirmed', 'completed', 'pago', 'aprovado'], true);
    $pdo = db();
    if ($orderNsu !== '' && str_starts_with($orderNsu, 'lojist_order_')) {
        $orderId = (int)str_replace('lojist_order_', '', $orderNsu);
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id=?');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if ($order) {
            $pdo->prepare('UPDATE payments SET webhook_data=?, status = CASE WHEN ? THEN "aprovado" ELSE status END, aprovado_em = CASE WHEN ? THEN NOW() ELSE aprovado_em END WHERE order_id=?')->execute([$raw ?: json_encode($payload), $paid, $paid, $orderId]);
            if ($paid && $order['pix_status'] !== 'aprovado') {
                $pdo->prepare("UPDATE orders SET status='pagamento_aprovado', pix_status='aprovado', pagamento_aprovado_em=NOW() WHERE id=?")->execute([$orderId]);
                $pdo->prepare("UPDATE products SET status='pagamento_aprovado' WHERE id=?")->execute([$order['produto_id']]);
                add_order_event($orderId, 'pagamento_aprovado', 'Pagamento aprovado pela InfinitePay', 'Webhook confirmou o pagamento de teste.', (int)$order['comprador_id']);
                add_notification((int)$order['vendedor_id'], 'pagamento_aprovado', 'Pagamento aprovado', 'A InfinitePay confirmou o pagamento do pedido #' . $orderId . '.');
            }
        }
    } elseif ($orderNsu !== '' && str_starts_with($orderNsu, 'lojist_plan_')) {
        $planPaymentId = (int)str_replace('lojist_plan_', '', $orderNsu);
        $stmt = $pdo->prepare('SELECT * FROM plan_payments WHERE id=?');
        $stmt->execute([$planPaymentId]);
        $planPayment = $stmt->fetch();
        if ($planPayment) {
            $pdo->prepare('UPDATE plan_payments SET webhook_data=?, status = CASE WHEN ? THEN "aprovado" ELSE status END WHERE id=?')->execute([$raw ?: json_encode($payload), $paid, $planPaymentId]);
            if ($paid) {
                $pdo->prepare("UPDATE users SET subscription_status='active', paid_until=?, last_plan_payment_at=NOW(), plano=(SELECT nome FROM plans WHERE id=?) WHERE id=?")->execute([$planPayment['periodo_fim'], $planPayment['plan_id'], $planPayment['user_id']]);
                add_notification((int)$planPayment['user_id'], 'plano_pago', 'Plano ativado', 'Pagamento confirmado pela InfinitePay.');
            }
        }
    }
    http_response_code(200);
    echo 'ok';
}

function level_class(string $level): string
{
    return strtolower($level) === 'diamond' ? 'diamond' : strtolower($level);
}

function status_label(string $status): string
{
    return str_replace('_', ' ', ucfirst($status));
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf_token" value="' . e((string)$_SESSION['_csrf_token']) . '">';
}

function inject_csrf_token(string $html): string
{
    if (stripos($html, '<form') === false || stripos($html, 'method="post"') === false) {
        return $html;
    }

    return (string)preg_replace_callback(
        '~(<form\b(?=[^>]*\bmethod=["\']?post["\']?)[^>]*>)~i',
        static fn(array $match): string => $match[1] . csrf_field(),
        $html
    );
}
