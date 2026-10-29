<?php
/**
 * migrations/migrate_smtp_passwords.php
 * Idempotent migration script to encrypt legacy plaintext SMTP passwords to AES-256-GCM
 * 
 * Usage: php migrations/migrate_smtp_passwords.php [--table=custom_table_name]
 */

require_once __DIR__ . '/../config/config.php';

function runSmtpPasswordMigration(?PDO $pdo = null, string $tableName = 'smtp_accounts', ?array $onlyIds = null): array {
    $db = $pdo ?? getDB();
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $tableName)) throw new InvalidArgumentException('Invalid migration table');
    
    // Step 1: Pre-flight health check on Master Key
    try {
        $testPlaintext = "preflight_health_check_" . bin2hex(random_bytes(8));
        $encrypted = CryptoHelper::encrypt($testPlaintext);
        $decrypted = CryptoHelper::decrypt($encrypted);
        if ($decrypted !== $testPlaintext) {
            throw new Exception("In-memory encryption roundtrip verification failed.");
        }
    } catch (Exception $e) {
        throw new Exception("Pre-flight check failed: Master encryption key is invalid or inaccessible.");
    }

    // Step 2: Fetch all records from the target table
    $where = $onlyIds === null ? '' : ' WHERE id IN ('.implode(',', array_map('intval', $onlyIds ?: [0])).')';
    $stmt = $db->query("SELECT id, title, password FROM `{$tableName}`".$where);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total = count($rows);
    $migrated = 0;
    $skipped = 0;
    $errors = 0;

    $updateStmt = $db->prepare("UPDATE `{$tableName}` SET password = ? WHERE id = ?");

    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $rawPass = $row['password'];

        // If null or empty, skip
        if ($rawPass === null || $rawPass === '') {
            $skipped++;
            continue;
        }

        // If already encrypted with ENC:v1:, skip
        if (CryptoHelper::isEncrypted($rawPass)) {
            $skipped++;
            continue;
        }

        // Migrate plaintext to AES-256-GCM
        try {
            $ciphertext = CryptoHelper::encrypt($rawPass);
            $updateStmt->execute([$ciphertext, $id]);
            $migrated++;
        } catch (Exception $e) {
            $errors++;
        }
    }

    return [
        'total' => $total,
        'migrated' => $migrated,
        'skipped' => $skipped,
        'errors' => $errors
    ];
}

// If executed directly from CLI
if (php_sapi_name() === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    echo "=== SMTP PASSWORDS AES-256-GCM MIGRATION ===\n";
    try {
        $res = runSmtpPasswordMigration();
        echo sprintf(
            "Ολοκληρώθηκε: Σύνολο: %d | Κρυπτογραφήθηκαν: %d | Παραλείφθηκαν: %d | Σφάλματα: %d\n",
            $res['total'], $res['migrated'], $res['skipped'], $res['errors']
        );
        exit($res['errors'] > 0 ? 1 : 0);
    } catch (Exception $e) {
        echo "Αποτυχία Migration: " . $e->getMessage() . "\n";
        exit(1);
    }
}
