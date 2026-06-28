<?php
return [
    'db_host' => 'localhost',
    'db_port' => '3306',
    'db_name' => 'lojistco_bank',
    'db_user' => 'lojistco_admin',
    'db_pass' => '31081997Diego.',
    'db_charset' => 'utf8mb4',
    'app_env' => 'production',
    'app_url' => 'https://lojist.com.br',

    // Enquanto estiver true, visitantes veem a pagina "Em breve".
    // O pre-cadastro continua aberto em index.php?p=register.
    'marketing_lock_enabled' => false,

    // Troque essa chave antes de subir para a hospedagem.
    // Acesso interno: https://lojist.com.br/index.php?p=login&admin_key=SUA_CHAVE_AQUI
    'marketing_admin_key' => 'TROQUE-ESSA-CHAVE-ANTES-DE-SUBIR',

    // Asaas
    // Use "sandbox" para homologacao e "production" para cobrancas reais.
    'asaas_environment' => 'production',
    'asaas_api_key' => 'COLE_AQUI_SUA_API_KEY_DO_ASAAS',
    'asaas_webhook_token' => 'COLE_AQUI_UM_TOKEN_FORTE_DO_WEBHOOK',
    'payment_provider' => 'asaas',
    'infinitepay_handle' => 'COLE_AQUI_SEU_HANDLE',
    'infinitepay_webhook_secret' => 'COLE_AQUI_UM_TOKEN_FORTE_INFINITYPAY',
    'mail_from' => 'naoresponda@lojist.com.br',
    'mail_from_name' => 'LOJIST',
    'mail_reply_to' => 'naoresponda@lojist.com.br',
];
