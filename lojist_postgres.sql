-- PostgreSQL Schema for Lojist
CREATE TABLE IF NOT EXISTS plans (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(30) NOT NULL UNIQUE,
    taxa DECIMAL(6,3) NOT NULL,
    limite_anuncios INT NULL,
    filtros_avancados BOOLEAN NOT NULL DEFAULT false,
    preco_mensal DECIMAL(10,2) NOT NULL DEFAULT 0,
    especial BOOLEAN NOT NULL DEFAULT false,
    ativo BOOLEAN NOT NULL DEFAULT true
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
    role VARCHAR(20) NOT NULL DEFAULT 'lojista',
    status_conta VARCHAR(40) NOT NULL DEFAULT 'aguardando_aprovacao',
    plano VARCHAR(30) NOT NULL DEFAULT 'Free',
    trial_ends_at TIMESTAMP NULL,
    paid_until TIMESTAMP NULL,
    subscription_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    last_plan_payment_at TIMESTAMP NULL,
    nivel VARCHAR(20) NOT NULL DEFAULT 'Bronze',
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
CREATE INDEX idx_users_status ON users(status_conta);
CREATE INDEX idx_users_cidade_estado ON users(cidade, estado);

CREATE TABLE IF NOT EXISTS products (
    id SERIAL PRIMARY KEY,
    vendedor_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    categoria VARCHAR(40) NOT NULL,
    marca VARCHAR(80) NOT NULL,
    modelo VARCHAR(160) NOT NULL,
    armazenamento VARCHAR(60) NOT NULL,
    cor VARCHAR(80) NOT NULL,
    tipo VARCHAR(20) NOT NULL,
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
    status VARCHAR(40) NOT NULL DEFAULT 'disponivel',
    aceita_oferta BOOLEAN NOT NULL DEFAULT false,
    venda_expressa BOOLEAN NOT NULL DEFAULT false,
    metodos_entrega VARCHAR(255) NOT NULL,
    cidade VARCHAR(120) NOT NULL,
    estado CHAR(2) NOT NULL,
    data_criacao TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_atualizacao TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    preco_ajustado_recentemente BOOLEAN NOT NULL DEFAULT false
);
CREATE INDEX idx_products_feed ON products(status, cidade, estado, categoria, preco);
CREATE INDEX idx_products_vendedor ON products(vendedor_id);

-- PostgreSQL trigger to handle data_atualizacao
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
   NEW.data_atualizacao = NOW();
   RETURN NEW;
END;
$$ language 'plpgsql';

CREATE TRIGGER update_products_modtime
BEFORE UPDATE ON products
FOR EACH ROW
EXECUTE FUNCTION update_updated_at_column();

CREATE TABLE IF NOT EXISTS orders (
    id SERIAL PRIMARY KEY,
    produto_id INT NOT NULL REFERENCES products(id),
    comprador_id INT NOT NULL REFERENCES users(id),
    vendedor_id INT NOT NULL REFERENCES users(id),
    valor_bruto DECIMAL(12,2) NOT NULL,
    taxa_plataforma DECIMAL(12,2) NOT NULL,
    valor_liquido DECIMAL(12,2) NOT NULL,
    metodo_entrega VARCHAR(80) NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'aguardando_pagamento',
    pix_qrcode TEXT NULL,
    pix_status VARCHAR(20) NOT NULL DEFAULT 'pendente',
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
    repasse_status VARCHAR(20) NOT NULL DEFAULT 'retido',
    data_criacao TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_orders_comprador ON orders(comprador_id);
CREATE INDEX idx_orders_vendedor ON orders(vendedor_id);

CREATE TABLE IF NOT EXISTS order_events (
    id SERIAL PRIMARY KEY,
    order_id INT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    status VARCHAR(60) NOT NULL,
    titulo VARCHAR(160) NOT NULL,
    mensagem TEXT NOT NULL,
    criado_por INT NULL,
    data TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_order_events_order ON order_events(order_id, data);

CREATE TABLE IF NOT EXISTS offers (
    id SERIAL PRIMARY KEY,
    produto_id INT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    comprador_id INT NOT NULL REFERENCES users(id),
    vendedor_id INT NOT NULL REFERENCES users(id),
    valor_oferta DECIMAL(12,2) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pendente',
    criada_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    aceita_em TIMESTAMP NULL,
    expira_em TIMESTAMP NULL,
    paga_em TIMESTAMP NULL,
    cooldown_ate TIMESTAMP NULL
);
CREATE INDEX idx_offers_produto_comprador ON offers(produto_id, comprador_id, cooldown_ate);

CREATE TABLE IF NOT EXISTS payments (
    id SERIAL PRIMARY KEY,
    order_id INT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    gateway VARCHAR(80) NOT NULL DEFAULT 'Pix Simulado',
    valor DECIMAL(12,2) NOT NULL,
    taxa_plataforma DECIMAL(12,2) NOT NULL,
    taxa_gateway DECIMAL(12,2) NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'pendente',
    pix_qrcode TEXT NULL,
    asaas_payment_id VARCHAR(80) NULL,
    asaas_invoice_url VARCHAR(255) NULL,
    infinitepay_order_nsu VARCHAR(80) NULL,
    infinitepay_checkout_url VARCHAR(255) NULL,
    webhook_data JSONB NULL,
    aprovado_em TIMESTAMP NULL
);
CREATE INDEX idx_payments_asaas ON payments(asaas_payment_id);

CREATE TABLE IF NOT EXISTS plan_payments (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    plan_id INT NOT NULL REFERENCES plans(id),
    valor DECIMAL(10,2) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'aprovado',
    pix_qrcode VARCHAR(160) NULL,
    asaas_payment_id VARCHAR(80) NULL,
    asaas_invoice_url VARCHAR(255) NULL,
    webhook_data JSONB NULL,
    periodo_inicio TIMESTAMP NOT NULL,
    periodo_fim TIMESTAMP NOT NULL,
    data TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_plan_payments_user ON plan_payments(user_id, data);

CREATE TABLE IF NOT EXISTS notifications (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    tipo VARCHAR(80) NOT NULL,
    titulo VARCHAR(160) NOT NULL,
    mensagem TEXT NOT NULL,
    lida BOOLEAN NOT NULL DEFAULT false,
    data TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_notifications_user ON notifications(user_id, lida, data);

CREATE TABLE IF NOT EXISTS email_logs (
    id SERIAL PRIMARY KEY,
    user_id INT NULL,
    email VARCHAR(190) NOT NULL,
    assunto VARCHAR(190) NOT NULL,
    tipo VARCHAR(80) NOT NULL,
    status VARCHAR(20) NOT NULL,
    erro TEXT NULL,
    data TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_email_logs_user ON email_logs(user_id, data);
CREATE INDEX idx_email_logs_tipo ON email_logs(tipo, data);

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL,
    ip VARCHAR(45) NULL,
    data TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_password_reset_user ON password_reset_tokens(user_id, expires_at);

CREATE TABLE IF NOT EXISTS system_logs (
    id SERIAL PRIMARY KEY,
    user_id INT NULL,
    tipo VARCHAR(80) NOT NULL,
    entidade VARCHAR(80) NULL,
    entidade_id INT NULL,
    titulo VARCHAR(160) NOT NULL,
    mensagem TEXT NOT NULL,
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    data TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_system_logs_user ON system_logs(user_id, data);
CREATE INDEX idx_system_logs_tipo ON system_logs(tipo, data);

CREATE TABLE IF NOT EXISTS price_history (
    id SERIAL PRIMARY KEY,
    produto_id INT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    preco_antigo DECIMAL(12,2) NOT NULL,
    preco_novo DECIMAL(12,2) NOT NULL,
    data TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    mostrar_publicamente_apenas_como_indicador BOOLEAN NOT NULL DEFAULT true
);

CREATE TABLE IF NOT EXISTS reviews (
    id SERIAL PRIMARY KEY,
    order_id INT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    avaliador_id INT NOT NULL,
    avaliado_id INT NOT NULL,
    nota SMALLINT NOT NULL,
    criterios TEXT NOT NULL,
    data TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS disputes (
    id SERIAL PRIMARY KEY,
    order_id INT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    aberto_por INT NOT NULL,
    motivo TEXT NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'aberta',
    resolucao TEXT NULL,
    admin_responsavel INT NULL,
    data TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS admin_settings (
    id SMALLINT PRIMARY KEY,
    taxa_free DECIMAL(6,3) NOT NULL DEFAULT 2,
    taxa_pro DECIMAL(6,3) NOT NULL DEFAULT 1.5,
    taxa_elite DECIMAL(6,3) NOT NULL DEFAULT 1,
    taxa_gateway DECIMAL(6,3) NOT NULL DEFAULT 0,
    tempo_reserva_oferta INT NOT NULL DEFAULT 30,
    tempo_pagamento_compra_agora INT NOT NULL DEFAULT 30,
    limites TEXT NULL,
    regras_score TEXT NULL
);

CREATE TABLE IF NOT EXISTS migrations (
    id VARCHAR(120) PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
