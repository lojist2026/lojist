<?php
require_once __DIR__ . "/../app/bootstrap.php";

$pdo = db();
$schema = <<<SQL
DROP TABLE IF EXISTS plans CASCADE;
DROP TABLE IF EXISTS users CASCADE;
DROP TABLE IF EXISTS products CASCADE;
DROP TABLE IF EXISTS orders CASCADE;
DROP TABLE IF EXISTS order_events CASCADE;
DROP TABLE IF EXISTS offers CASCADE;
DROP TABLE IF EXISTS payments CASCADE;
DROP TABLE IF EXISTS plan_payments CASCADE;
DROP TABLE IF EXISTS notifications CASCADE;
DROP TABLE IF EXISTS email_logs CASCADE;
DROP TABLE IF EXISTS system_logs CASCADE;
DROP TABLE IF EXISTS password_reset_tokens CASCADE;
DROP TABLE IF EXISTS price_alerts CASCADE;
DROP TABLE IF EXISTS market_research_queries CASCADE;
DROP TABLE IF EXISTS reviews CASCADE;
DROP TABLE IF EXISTS disputes CASCADE;

CREATE TABLE IF NOT EXISTS plans (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(30) NOT NULL UNIQUE,
    taxa DECIMAL(6,3) NOT NULL,
    limite_anuncios INT NULL,
    filtros_avancados SMALLINT NOT NULL DEFAULT 0,
    preco_mensal DECIMAL(10,2) NOT NULL DEFAULT 0,
    especial SMALLINT NOT NULL DEFAULT 0,
    ativo SMALLINT NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
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
    role VARCHAR(20) NOT NULL DEFAULT 'lojista' CHECK (role IN ('lojista','admin')),
    status_conta VARCHAR(30) NOT NULL DEFAULT 'aguardando_aprovacao' CHECK (status_conta IN ('aguardando_aprovacao','aprovado','recusado','suspenso','banido')),
    plano VARCHAR(30) NOT NULL DEFAULT 'Free',
    trial_ends_at TIMESTAMP NULL,
    paid_until TIMESTAMP NULL,
    subscription_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    last_plan_payment_at TIMESTAMP NULL,
    nivel VARCHAR(20) NOT NULL DEFAULT 'Bronze' CHECK (nivel IN ('Bronze','Silver','Gold','Diamond')),
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
    data_cadastro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    aprovado_em TIMESTAMP NULL
);

CREATE INDEX IF NOT EXISTS idx_users_status ON users (status_conta);
CREATE INDEX IF NOT EXISTS idx_users_cidade_estado ON users (cidade, estado);

CREATE TABLE IF NOT EXISTS products (
    id SERIAL PRIMARY KEY,
    vendedor_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    categoria VARCHAR(30) NOT NULL CHECK (categoria IN ('iPhone','Samsung','Xiaomi')),
    marca VARCHAR(80) NOT NULL,
    modelo VARCHAR(160) NOT NULL,
    armazenamento VARCHAR(60) NOT NULL,
    cor VARCHAR(80) NOT NULL,
    tipo VARCHAR(30) NOT NULL CHECK (tipo IN ('seminovo','lacrado')),
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
    status VARCHAR(30) NOT NULL DEFAULT 'disponivel' CHECK (status IN ('disponivel','pausado','reservado','aguardando_pagamento','pagamento_aprovado','preparando_envio','enviado','entregue','finalizado','cancelado')),
    aceita_oferta SMALLINT NOT NULL DEFAULT 0,
    venda_expressa SMALLINT NOT NULL DEFAULT 0,
    metodos_entrega VARCHAR(255) NOT NULL,
    cidade VARCHAR(120) NOT NULL,
    estado CHAR(2) NOT NULL,
    data_criacao TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_atualizacao TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    preco_ajustado_recentemente SMALLINT NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_products_feed ON products (status, cidade, estado, categoria, preco);
CREATE INDEX IF NOT EXISTS idx_products_vendedor ON products (vendedor_id);

CREATE TABLE IF NOT EXISTS orders (
    id SERIAL PRIMARY KEY,
    produto_id INT NOT NULL REFERENCES products(id),
    comprador_id INT NOT NULL REFERENCES users(id),
    vendedor_id INT NOT NULL REFERENCES users(id),
    valor_bruto DECIMAL(12,2) NOT NULL,
    taxa_plataforma DECIMAL(12,2) NOT NULL,
    valor_liquido DECIMAL(12,2) NOT NULL,
    metodo_entrega VARCHAR(80) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'aguardando_pagamento' CHECK (status IN ('aguardando_pagamento','pagamento_aprovado','preparando_envio','enviado','entregue','finalizado','cancelado')),
    pix_qrcode TEXT NULL,
    pix_status VARCHAR(20) NOT NULL DEFAULT 'pendente' CHECK (pix_status IN ('pendente','aprovado','cancelado','expirado')),
    pagamento_aprovado_em TIMESTAMP NULL,
    enviado_em TIMESTAMP NULL,
    entregue_em TIMESTAMP NULL,
    finalizado_em TIMESTAMP NULL,
    cancelado_em TIMESTAMP NULL,
    motivo_cancelamento TEXT NULL,
    destinatario VARCHAR(160) NULL,
    telefone_entrega VARCHAR(30) NULL,
    cep_entrega VARCHAR(20) NULL,
    endereco_entrega TEXT NULL,
    cidade_entrega VARCHAR(120) NULL,
    estado_entrega CHAR(2) NULL,
    complemento_entrega VARCHAR(160) NULL,
    codigo_rastreio VARCHAR(120) NULL,
    repasse_liberado_em TIMESTAMP NULL,
    repasse_status VARCHAR(20) NOT NULL DEFAULT 'retido' CHECK (repasse_status IN ('retido','liberado','pago')),
    data_criacao TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_orders_comprador ON orders (comprador_id);
CREATE INDEX IF NOT EXISTS idx_orders_vendedor ON orders (vendedor_id);

CREATE TABLE IF NOT EXISTS order_events (
    id SERIAL PRIMARY KEY,
    order_id INT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    status VARCHAR(60) NOT NULL,
    titulo VARCHAR(160) NOT NULL,
    mensagem TEXT NOT NULL,
    criado_por INT NULL,
    data TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_order_events_order ON order_events (order_id, data);

CREATE TABLE IF NOT EXISTS offers (
    id SERIAL PRIMARY KEY,
    produto_id INT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    comprador_id INT NOT NULL REFERENCES users(id),
    vendedor_id INT NOT NULL REFERENCES users(id),
    valor_oferta DECIMAL(12,2) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pendente' CHECK (status IN ('pendente','aceita','recusada','expirada','paga')),
    criada_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    aceita_em TIMESTAMP NULL,
    expira_em TIMESTAMP NULL,
    paga_em TIMESTAMP NULL,
    cooldown_ate TIMESTAMP NULL
);

CREATE INDEX IF NOT EXISTS idx_offers_produto_comprador ON offers (produto_id, comprador_id, cooldown_ate);

CREATE TABLE IF NOT EXISTS payments (
    id SERIAL PRIMARY KEY,
    order_id INT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    gateway VARCHAR(80) NOT NULL DEFAULT 'Pix Simulado',
    valor DECIMAL(12,2) NOT NULL,
    taxa_plataforma DECIMAL(12,2) NOT NULL,
    taxa_gateway DECIMAL(12,2) NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'pendente' CHECK (status IN ('pendente','aprovado','cancelado','estornado')),
    pix_qrcode TEXT NULL,
    asaas_payment_id VARCHAR(80) NULL,
    asaas_invoice_url VARCHAR(255) NULL,
    infinitepay_order_nsu VARCHAR(80) NULL,
    infinitepay_checkout_url VARCHAR(255) NULL,
    webhook_data JSON NULL,
    aprovado_em TIMESTAMP NULL
);

CREATE INDEX IF NOT EXISTS idx_payments_asaas ON payments (asaas_payment_id);

CREATE TABLE IF NOT EXISTS plan_payments (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    plan_id INT NOT NULL REFERENCES plans(id),
    valor DECIMAL(10,2) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'aprovado' CHECK (status IN ('pendente','aprovado','cancelado')),
    pix_qrcode VARCHAR(160) NULL,
    asaas_payment_id VARCHAR(80) NULL,
    asaas_invoice_url VARCHAR(255) NULL,
    webhook_data JSON NULL,
    periodo_inicio TIMESTAMP NOT NULL,
    periodo_fim TIMESTAMP NOT NULL,
    data TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_plan_payments_user ON plan_payments (user_id, data);

CREATE TABLE IF NOT EXISTS notifications (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    tipo VARCHAR(80) NOT NULL,
    titulo VARCHAR(160) NOT NULL,
    mensagem TEXT NOT NULL,
    lida SMALLINT NOT NULL DEFAULT 0,
    data TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications (user_id, lida, data);

CREATE TABLE IF NOT EXISTS email_logs (
    id SERIAL PRIMARY KEY,
    user_id INT NULL,
    email VARCHAR(190) NOT NULL,
    assunto VARCHAR(190) NOT NULL,
    corpo TEXT NOT NULL,
    enviado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS system_logs (
    id SERIAL PRIMARY KEY,
    user_id INT NULL,
    tipo VARCHAR(60) NOT NULL,
    entidade_tipo VARCHAR(40) NULL,
    entidade_id INT NULL,
    titulo VARCHAR(160) NOT NULL,
    detalhes TEXT NULL,
    data TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token VARCHAR(120) NOT NULL UNIQUE,
    expira_em TIMESTAMP NOT NULL,
    usado SMALLINT NOT NULL DEFAULT 0,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_password_reset_token ON password_reset_tokens (token);

CREATE TABLE IF NOT EXISTS price_alerts (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    marca VARCHAR(80) NOT NULL,
    modelo VARCHAR(160) NOT NULL,
    valor_desejado DECIMAL(12,2) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'ativo' CHECK (status IN ('ativo','disparado','desativado')),
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_price_alerts_modelo ON price_alerts (modelo, valor_desejado, status);

CREATE TABLE IF NOT EXISTS market_research_queries (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    marca VARCHAR(80) NOT NULL,
    modelo VARCHAR(160) NOT NULL,
    armazenamento VARCHAR(60) NOT NULL,
    estado_aparelho VARCHAR(10) NOT NULL,
    valor_pago DECIMAL(12,2) NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS reviews (
    id SERIAL PRIMARY KEY,
    order_id INT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    avaliador_id INT NOT NULL REFERENCES users(id),
    avaliado_id INT NOT NULL REFERENCES users(id),
    nota INT NOT NULL CHECK (nota >= 1 AND nota <= 5),
    comentario TEXT NULL,
    data TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_reviews_avaliado ON reviews (avaliado_id);

CREATE TABLE IF NOT EXISTS disputes (
    id SERIAL PRIMARY KEY,
    order_id INT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    aberta_por INT NOT NULL REFERENCES users(id),
    motivo VARCHAR(80) NOT NULL,
    descricao TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'aberta' CHECK (status IN ('aberta','em_analise','resolvida_comprador','resolvida_vendedor')),
    resolucao TEXT NULL,
    data_abertura TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_resolucao TIMESTAMP NULL
);
SQL;

try {
    $pdo->exec($schema);
    echo "Tabelas criadas com sucesso!<br>";

    // Populate plans
    $plans = [
        ['Free', 2.9, 10, 0, 0, 0],
        ['Pro', 1.9, 50, 1, 99.90, 0],
        ['Vip', 0.9, null, 1, 299.90, 1]
    ];
    $stmt = $pdo->prepare("INSERT INTO plans (nome, taxa, limite_anuncios, filtros_avancados, preco_mensal, especial) VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT (nome) DO NOTHING");
    foreach ($plans as $p) {
        $stmt->execute($p);
    }
    
    // Populate dummy users
    $users = [
        ['Admin Master', 'admin', '00000000000', 'admin@lojist.com', password_hash('admin123', PASSWORD_DEFAULT), 'admin', 'aprovado'],
        ['Lojista Teste', 'Silva', '11111111111', 'lojista@lojist.com', password_hash('lojista123', PASSWORD_DEFAULT), 'lojista', 'aprovado'],
        ['Lojista Bloqueado', 'Silva', '22222222222', 'bloqueado@lojist.com', password_hash('senha123', PASSWORD_DEFAULT), 'lojista', 'banido']
    ];
    $stmt = $pdo->prepare("INSERT INTO users (nome, sobrenome, cpf, email, password_hash, role, status_conta, telefone, nome_loja, cidade, estado, endereco_completo) VALUES (?, ?, ?, ?, ?, ?, ?, '11999999999', 'Loja Teste', 'São Paulo', 'SP', 'Rua Teste') ON CONFLICT (email) DO NOTHING");
    foreach ($users as $u) {
        $stmt->execute($u);
    }

    echo "Dados iniciais inseridos com sucesso!<br>";
    echo "Você já pode voltar e fazer o login!";

} catch (Exception $e) {
    echo "Erro ao criar banco de dados: " . $e->getMessage();
}
