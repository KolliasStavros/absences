<?php
/**
 * includes/crypto.php
 * Robust AES-256-GCM Encryption Helper for School Absences Application
 * 
 * Complies with strict security standards:
 * - Cipher: AES-256-GCM via PHP OpenSSL
 * - Key: 256 bits (32 bytes raw), strictly Base64 validated
 * - Nonce/IV: 96 bits (12 bytes) CSPRNG per encryption
 * - Auth Tag: 128 bits (16 bytes) AEAD verification
 * - Envelope: ENC:v1:<base64(IV . Tag . Ciphertext)>
 * - Safe error handling: zero secret leakage in logs or exceptions
 */

class CryptoException extends Exception {}

class CryptoHelper {
    public const CIPHER = 'aes-256-gcm';
    public const KEY_BYTES = 32;
    public const IV_LENGTH = 12;
    public const TAG_LENGTH = 16;
    public const PREFIX_V1 = 'ENC:v1:';
    public const MAX_CIPHERTEXT_LENGTH = 255;
    public const EXTERNAL_KEY_PATH = 'C:/wamp64/keys/smtp_master.key';

    /**
     * Cached binary master key in memory
     * @var string|null
     */
    private static ?string $cachedKey = null;

    /**
     * Optional custom key file path (useful for testing and containerized setups)
     * @var string|null
     */
    private static ?string $customKeyPath = null;

    /**
     * Clear cached in-memory key (useful for tests)
     */
    public static function clearCachedKey(): void {
        self::$cachedKey = null;
    }

    /**
     * Set a custom path for the external key file (supports testing / deployment customization)
     */
    public static function setCustomKeyPath(?string $path): void {
        self::$customKeyPath = $path;
        self::clearCachedKey();
    }

    /**
     * Generate a new 256-bit CSPRNG key encoded in Base64
     */
    public static function generateKey(): string {
        return base64_encode(random_bytes(self::KEY_BYTES));
    }

    /**
     * Validate and decode a Base64 key string into exactly 32 raw bytes
     * 
     * @param string $keyStr
     * @return string Raw 32 bytes
     * @throws CryptoException
     */
    public static function validateRawKey(string $keyStr): string {
        $trimmed = trim($keyStr);
        if ($trimmed === '') {
            throw new CryptoException("Master key string is empty.");
        }

        $decoded = base64_decode($trimmed, true);
        if ($decoded === false || strlen($decoded) !== self::KEY_BYTES) {
            throw new CryptoException("Invalid master key: must be Base64-encoded 32 raw bytes (256-bit).");
        }

        return $decoded;
    }

    /**
     * Load Master Key according to defined precedence:
     * 1. Environment variable APP_MASTER_KEY (System / Apache SetEnv / $_SERVER)
     * 2. Configured external key path via APP_MASTER_KEY_FILE env or setCustomKeyPath()
     * 3. OS-aware candidate paths outside web root:
     *    - C:/wamp64/keys/smtp_master.key (Windows WAMP)
     *    - /etc/absences/smtp_master.key (Linux hosting)
     *    - [workspace_parent]/keys/smtp_master.key
     * 
     * @return string Raw 32 bytes binary key
     * @throws CryptoException
     */
    public static function loadMasterKey(): string {
        if (self::$cachedKey !== null) {
            return self::$cachedKey;
        }

        // Priority 1: Direct key in environment variable
        $envKey = getenv('APP_MASTER_KEY');
        if ($envKey === false || trim($envKey) === '') {
            $envKey = $_ENV['APP_MASTER_KEY'] ?? ($_SERVER['APP_MASTER_KEY'] ?? '');
        }

        if (is_string($envKey) && trim($envKey) !== '') {
            self::$cachedKey = self::validateRawKey($envKey);
            return self::$cachedKey;
        }

        // Priority 2: External key file path candidates
        $candidatePaths = [];

        // 2a. Programmatic custom path
        if (self::$customKeyPath !== null) {
            $candidatePaths[] = self::$customKeyPath;
        }

        // 2b. Configured key file path via constant or environment variable
        if (defined('APP_MASTER_KEY_FILE') && is_string(constant('APP_MASTER_KEY_FILE')) && trim(constant('APP_MASTER_KEY_FILE')) !== '') {
            $candidatePaths[] = trim(constant('APP_MASTER_KEY_FILE'));
        }
        $envFilePath = getenv('APP_MASTER_KEY_FILE');
        if ($envFilePath === false || trim($envFilePath) === '') {
            $envFilePath = $_ENV['APP_MASTER_KEY_FILE'] ?? ($_SERVER['APP_MASTER_KEY_FILE'] ?? '');
        }
        if (is_string($envFilePath) && trim($envFilePath) !== '') {
            $candidatePaths[] = trim($envFilePath);
        }

        // 2c. Standard candidate paths (PSD shared hosting private dir, system, parent)
        $candidatePaths[] = dirname(__DIR__, 2) . '/private/keys/smtp_master.key';
        $candidatePaths[] = dirname(__DIR__, 2) . '/private/smtp_master.key';
        $candidatePaths[] = dirname(__DIR__, 3) . '/private/keys/smtp_master.key';
        $candidatePaths[] = dirname(__DIR__, 3) . '/private/smtp_master.key';
        $candidatePaths[] = dirname(__DIR__, 2) . '/keys/smtp_master.key';
        $candidatePaths[] = self::EXTERNAL_KEY_PATH; // C:/wamp64/keys/smtp_master.key (Windows dev)
        $candidatePaths[] = '/etc/absences/smtp_master.key'; // Linux system hosting

        foreach ($candidatePaths as $path) {
            if (is_string($path) && file_exists($path) && is_readable($path)) {
                $fileContent = @file_get_contents($path);
                if ($fileContent !== false && trim($fileContent) !== '') {
                    self::$cachedKey = self::validateRawKey($fileContent);
                    return self::$cachedKey;
                }
            }
        }

        // Neither source provided a valid key - NEVER auto-generate!
        throw new CryptoException("Master encryption key is not configured or cannot be loaded.");
    }

    /**
     * Check if a given string is in the ENC:v1: format
     */
    public static function isEncrypted(?string $data): bool {
        if ($data === null || $data === '') {
            return false;
        }
        return str_starts_with($data, self::PREFIX_V1);
    }

    /**
     * Encrypt a plaintext string using AES-256-GCM
     * 
     * @param string $plaintext
     * @param string|null $overrideRawKey Optional raw 32-byte key for unit testing
     * @return string Versioned envelope string (e.g. ENC:v1:...)
     * @throws CryptoException
     */
    public static function encrypt(string $plaintext, ?string $overrideRawKey = null): string {
        if ($plaintext === '') {
            return '';
        }

        $rawKey = $overrideRawKey !== null ? $overrideRawKey : self::loadMasterKey();
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $rawKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '', // AAD
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new CryptoException("Encryption operation failed.");
        }

        // Construct binary payload: IV (12B) + Tag (16B) + Ciphertext
        $payload = $iv . $tag . $ciphertext;
        $envelope = self::PREFIX_V1 . base64_encode($payload);

        // Strict database column length boundary enforcement
        if (strlen($envelope) > self::MAX_CIPHERTEXT_LENGTH) {
            throw new CryptoException(
                "Encrypted ciphertext length (" . strlen($envelope) . " chars) exceeds maximum database limit of " . self::MAX_CIPHERTEXT_LENGTH . " characters."
            );
        }

        return $envelope;
    }

    /**
     * Decrypt a versioned envelope string
     * Supports legacy plaintext during transition (strings not starting with ENC:)
     * 
     * @param string|null $data
     * @param string|null $overrideRawKey Optional raw 32-byte key for unit testing
     * @return string Decrypted plaintext
     * @throws CryptoException
     */
    public static function decrypt(?string $data, ?string $overrideRawKey = null): string {
        if ($data === null || $data === '') {
            return '';
        }

        // Transitional support: if string does not begin with ENC:, treat as legacy plaintext
        if (!str_starts_with($data, 'ENC:')) {
            return $data;
        }

        // Check envelope version
        if (!str_starts_with($data, self::PREFIX_V1)) {
            throw new CryptoException("Unsupported or unknown encryption envelope version.");
        }

        $b64 = substr($data, strlen(self::PREFIX_V1));
        $payload = base64_decode($b64, true);

        if ($payload === false) {
            throw new CryptoException("Corrupted Base64 payload in encrypted envelope.");
        }

        $minPayloadLength = self::IV_LENGTH + self::TAG_LENGTH;
        if (strlen($payload) < $minPayloadLength) {
            throw new CryptoException("Encrypted envelope payload is too short or malformed.");
        }

        $iv = substr($payload, 0, self::IV_LENGTH);
        $tag = substr($payload, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($payload, $minPayloadLength);

        $rawKey = $overrideRawKey !== null ? $overrideRawKey : self::loadMasterKey();

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $rawKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new CryptoException("Decryption failed: integrity authentication tag mismatch or corrupted data.");
        }

        return $plaintext;
    }
}
