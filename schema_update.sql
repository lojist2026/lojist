-- 1. market_research_queries
CREATE TABLE IF NOT EXISTS `market_research_queries` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `marca` varchar(120) NOT NULL,
  `modelo` varchar(120) NOT NULL,
  `armazenamento` varchar(50) NOT NULL,
  `estado_aparelho` varchar(50) NOT NULL,
  `valor_pago` decimal(10,2) NOT NULL,
  `data_consulta` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. price_alerts
CREATE TABLE IF NOT EXISTS `price_alerts` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `modelo` varchar(120) NOT NULL,
  `valor_desejado` decimal(10,2) NOT NULL,
  `status` enum('ativo','inativo','disparado') NOT NULL DEFAULT 'ativo',
  `data_criacao` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. inventory & sales
CREATE TABLE IF NOT EXISTS `inventory` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `marca` varchar(100) NOT NULL,
  `modelo` varchar(120) NOT NULL,
  `imei` varchar(50) DEFAULT NULL,
  `cor` varchar(50) DEFAULT NULL,
  `armazenamento` varchar(50) DEFAULT NULL,
  `valor_custo` decimal(10,2) NOT NULL,
  `valor_venda` decimal(10,2) NOT NULL,
  `quantidade` int(11) NOT NULL DEFAULT 0,
  `data_cadastro` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `inventory_movements` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `inventory_id` int(10) UNSIGNED NOT NULL,
  `tipo` enum('entrada','saida','ajuste') NOT NULL,
  `quantidade` int(11) NOT NULL,
  `data_movimento` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `inventory_id` (`inventory_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sales` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `inventory_id` int(10) UNSIGNED NOT NULL,
  `cliente_nome` varchar(120) NOT NULL,
  `valor_venda` decimal(10,2) NOT NULL,
  `quantidade` int(11) NOT NULL DEFAULT 1,
  `data_venda` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `inventory_id` (`inventory_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. subscriptions
CREATE TABLE IF NOT EXISTS `subscriptions` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `plano` varchar(30) NOT NULL,
  `metodo` enum('pix','cartao') NOT NULL,
  `data_renovacao` datetime NOT NULL,
  `proxima_cobranca` datetime NOT NULL,
  `status` enum('ativo','atrasado','cancelado') NOT NULL DEFAULT 'ativo',
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `subscription_payments` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `subscription_id` int(10) UNSIGNED NOT NULL,
  `valor` decimal(10,2) NOT NULL,
  `status` enum('pago','pendente','falho') NOT NULL,
  `data_pagamento` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `subscription_id` (`subscription_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. referrals
CREATE TABLE IF NOT EXISTS `referrals` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `referrer_id` int(10) UNSIGNED NOT NULL,
  `referred_id` int(10) UNSIGNED NOT NULL,
  `data_indicacao` datetime NOT NULL DEFAULT current_timestamp(),
  `status` enum('pendente','valida') NOT NULL DEFAULT 'pendente',
  PRIMARY KEY (`id`),
  KEY `referrer_id` (`referrer_id`),
  KEY `referred_id` (`referred_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `referral_rewards` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `recompensa` varchar(100) NOT NULL,
  `mes_referencia` varchar(7) NOT NULL,
  `data_concessao` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Check and add referral_code to users if it doesn't exist.
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `referral_code` varchar(20) DEFAULT NULL AFTER `plano`;
ALTER TABLE `users` ADD UNIQUE KEY IF NOT EXISTS `idx_referral_code` (`referral_code`);
