<?php
// Additive, repeatable migration; run using the deployment account, never over HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$deploymentConfig=getenv('ABSENCES_DEPLOY_CONFIG') ?: __DIR__.'/../config/database.php';
require_once $deploymentConfig;
$db=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$columns=$db->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('auth_version',$columns,true)) $db->exec('ALTER TABLE users ADD COLUMN auth_version INT UNSIGNED NOT NULL DEFAULT 1');
$db->exec("CREATE TABLE IF NOT EXISTS reset_requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ip_hash CHAR(64) NOT NULL,
 identifier_hash CHAR(64) NOT NULL,
 requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_reset_ip (ip_hash, requested_at),
 INDEX idx_reset_identifier (identifier_hash, requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "Step 9 additive migration complete.\n";
