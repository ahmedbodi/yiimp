<?php

ini_set('date.timezone', 'UTC');

define('YAAMP_LOGS', '/var/log/yaamp');
define('YAAMP_HTDOCS', '/var/web');

define('YAAMP_DBHOST', 'localhost');
define('YAAMP_DBNAME', 'yaamp');
define('YAAMP_DBUSER', 'root');
define('YAAMP_DBPASSWORD', 'password');

define('YAAMP_PRODUCTION', true);
define('YAAMP_RENTAL', true);
define('YAAMP_LIMIT_ESTIMATE', false);

define('YAAMP_FEES_MINING', 0.5);
define('YAAMP_FEES_EXCHANGE', 2);
define('YAAMP_FEES_RENTING', 2);
define('YAAMP_PAYMENTS_FREQ', 3*60*60);

define('YAAMP_BTCADDRESS', '1Auhps1mHZQpoX4mCcVL8odU81VakZQ6dR');
define('YAAMP_SITE_URL', 'yiimp.ccminer.org');
define('YAAMP_ADMIN_EMAIL', 'yiimp@spam.la');

define('YAAMP_USE_NGINX', false);

// Exchange public keys (private keys are in a separate config file)
define('YIIMP_CRYPT_PUB', '');
define('YIIMP_POLO_KEY', '');
define('YIIMP_BITTREX_KEY', '');

// Automatic withdraw to Yaamp btc wallet if btc balance > 0.3
define('YAMMP_BITTREX_AUTO_WITHDRAW', 0.3);

$cold_wallet_table = array(
	'1C23KmLeCaQSLLyKVykHEUse1R7jRDv9j9' => 0.10,
);
