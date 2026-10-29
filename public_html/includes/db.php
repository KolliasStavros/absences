<?php
// includes/db.php - PDO Database connection helper

/**
 * Custom PDO wrapper that transparently prepends DB_PREFIX to system tables
 * allowing seamless co-existence with other web applications (e.g. WordPress).
 */
class AppPDO extends PDO {
    private static ?string $prefix = null;
    private static ?string $pattern = null;
    private static array $tables = [
        'academic_years', 'absence_day_reasons', 'attendance_notes', 'attendance', 'grades',
        'login_attempts', 'notification_logs', 'password_resets',
        'reset_requests', 'student_sections', 'teacher_sections',
        'sections', 'settings', 'smtp_accounts', 'students', 'users'
    ];

    public static function setPrefix(?string $prefix): void {
        self::$prefix = $prefix !== null ? (string)$prefix : '';
        if (!preg_match('/^[a-zA-Z0-9_]{0,40}$/D', self::$prefix)) throw new InvalidArgumentException('Invalid table prefix');
        if (self::$prefix !== '') {
            usort(self::$tables, fn($a, $b) => strlen($b) - strlen($a));
            self::$pattern = '/(?<![a-zA-Z0-9_])(' . implode('|', array_map('preg_quote', self::$tables)) . ')(?![a-zA-Z0-9_])/';
        } else {
            self::$pattern = null;
        }
    }

    public static function prefixQuery(string $sql): string {
        if (self::$prefix === null) {
            self::setPrefix(defined('DB_PREFIX') ? (string)DB_PREFIX : '');
        }
        if (empty(self::$prefix) || self::$pattern === null) {
            return $sql;
        }
        return self::prefixIdentifiers($sql, self::$prefix);
    }

    public static function prefixIdentifiers(string $sql, string $prefix): string {
        if (!preg_match('/^[a-zA-Z0-9_]{0,40}$/D', $prefix)) throw new InvalidArgumentException('Invalid table prefix');
        if ($prefix==='') return $sql;
        // Skip SQL string literals and comments; leave bound values untouched.
        $protected = <<<'REGEX'
'(?:''|\\.|[^'\\])*'|"(?:""|\\.|[^"\\])*"|/\*.*?\*/|--(?=\s)[^\r\n]*|\#[^\r\n]*
REGEX;
        $tables=implode('|',array_map('preg_quote',self::$tables));
        $pattern='~(?:'.$protected.')(*SKIP)(*F)|(?<![a-zA-Z0-9_:])('.$tables.')(?![a-zA-Z0-9_])~s';
        $result=preg_replace_callback($pattern,fn($m)=>$prefix.$m[1],$sql);
        if ($result===null) throw new RuntimeException('SQL identifier rewrite failed');
        return $result;
    }

    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = []) {
        return parent::prepare(self::prefixQuery($query), $options);
    }

    #[\ReturnTypeWillChange]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs) {
        $query = self::prefixQuery($query);
        if ($fetchMode !== null) {
            return parent::query($query, $fetchMode, ...$fetchModeArgs);
        }
        return parent::query($query);
    }

    #[\ReturnTypeWillChange]
    public function exec(string $statement): int|false {
        return parent::exec(self::prefixQuery($statement));
    }
}

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $portPart = (defined('DB_PORT') && DB_PORT) ? ";port=" . DB_PORT : "";
        $dsn = "mysql:host=" . DB_HOST . $portPart . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new AppPDO($dsn, DB_USER, DB_PASS, $options);
            $pdo->exec("SET time_zone = '" . date('P') . "'");
        } catch (PDOException $e) {
            // If database doesn't exist yet, try connecting without DB name to allow install
            if ($e->getCode() == 1049) {
                header("Location: " . BASE_URL . "/install.php");
                exit;
            }
            http_response_code(503);
            die(htmlspecialchars(safeError($e), ENT_QUOTES, "UTF-8"));
        }
    }
    return $pdo;
}


// =========================================================================
// ACADEMIC YEAR (ΣΧΟΛΙΚΟ / ΔΙΔΑΚΤΙΚΟ ΕΤΟΣ) MANAGEMENT
// =========================================================================

/**
 * Get active academic year row (caches in memory per request, supports force refresh)
 */
function getActiveAcademicYear($forceRefresh = false) {
    static $cachedActiveYear = null;
    if ($cachedActiveYear !== null && !$forceRefresh) {
        return $cachedActiveYear;
    }
    try {
        $db = getDB();
        $stmt = $db->query("SELECT id, name, is_active FROM academic_years WHERE is_active = 1 LIMIT 1");
        $year = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$year) throw new RuntimeException('No active academic year configured');
        $cachedActiveYear = [
            'id' => (int)$year['id'],
            'name' => $year['name'],
            'is_active' => (int)$year['is_active']
        ];
        return $cachedActiveYear;
    } catch (Exception $e) {
        throw new RuntimeException("No active academic year available", 0, $e);
    }
}

/**
 * Get all registered academic years with stats
 */
function getAllAcademicYearsWithStats() {
    try {
        $db = getDB();
        $stmt = $db->query("
            SELECT 
                y.id, 
                y.name, 
                y.is_active, 
                y.created_at,
                (SELECT COUNT(*) FROM students s WHERE s.academic_year_id = y.id) as students_count,
                (SELECT COUNT(*) FROM attendance a WHERE a.academic_year_id = y.id AND a.is_absent = 1) as absences_count,
                (SELECT COUNT(*) FROM attendance a WHERE a.academic_year_id = y.id AND a.is_absent = 1) as attendance_count
            FROM academic_years y
            ORDER BY y.id DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Switch active academic year
 */
function setActiveAcademicYear($yearId) {
    $yearId = (int)$yearId;
    if ($yearId <= 0) return false;
    $db = getDB();
    $db->beginTransaction();
    try {
        $db->exec("UPDATE academic_years SET is_active = 0");
        $stmt = $db->prepare("UPDATE academic_years SET is_active = 1 WHERE id = ?");
        $stmt->execute([$yearId]);
        $db->commit();
        getActiveAcademicYear(true); // Reset cache
        return true;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        return false;
    }
}

/**
 * Create a new academic year
 */
function createAcademicYear($name, $setAsActive = true) {
    $name = trim($name);
    if (empty($name)) {
        return false;
    }
    $db = getDB();
    
    // Check if name already exists
    $stmt = $db->prepare("SELECT id FROM academic_years WHERE name = ?");
    $stmt->execute([$name]);
    if ($stmt->fetch()) {
        return false;
    }

    $db->beginTransaction();
    try {
        if ($setAsActive) {
            $db->exec("UPDATE academic_years SET is_active = 0");
        }
        $stmt = $db->prepare("INSERT INTO academic_years (name, is_active) VALUES (?, ?)");
        $stmt->execute([$name, $setAsActive ? 1 : 0]);
        $newId = (int)$db->lastInsertId();
        $db->commit();
        getActiveAcademicYear(true); // Reset cache
        return $newId;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        return false;
    }
}

/**
 * Delete an academic year (and all its associated student, attendance & assignment records)
 */
function deleteAcademicYear($yearId) {
    $yearId = (int)$yearId;
    $db = getDB();
    
    try {
        $stmt = $db->prepare("SELECT name, is_active FROM academic_years WHERE id = ?");
        $stmt->execute([$yearId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['success' => false, 'error' => 'Το διδακτικό έτος δεν βρέθηκε.'];
        }

        $totalYears = (int)$db->query("SELECT COUNT(*) FROM academic_years")->fetchColumn();
        if ($totalYears <= 1) {
            return ['success' => false, 'error' => 'Δεν μπορείτε να διαγράψετε το μοναδικό διδακτικό έτος του συστήματος.'];
        }
        if ((int)$row['is_active'] === 1) {
            return ['success' => false, 'error' => 'Δεν μπορείτε να διαγράψετε το τρέχον ενεργό διδακτικό έτος. Επιλέξτε πρώτα ένα άλλο έτος ως ενεργό.'];
        }

        // DATA-01: Protect historical records from CASCADE deletion
        $stmtAtt = $db->prepare("SELECT COUNT(*) FROM attendance WHERE academic_year_id = ?");
        $stmtAtt->execute([$yearId]);
        $attCount = (int)$stmtAtt->fetchColumn();
        if ($attCount > 0) {
            return ['success' => false, 'error' => "Δεν είναι δυνατή η διαγραφή του σχολικού έτους «{$row['name']}» επειδή περιέχει {$attCount} καταχωρημένες απουσίες. Το ιστορικό απουσιών προστατεύεται από οριστική διαγραφή."];
        }

        $stmtStu = $db->prepare("SELECT COUNT(*) FROM students WHERE academic_year_id = ?");
        $stmtStu->execute([$yearId]);
        $stuCount = (int)$stmtStu->fetchColumn();
        if ($stuCount > 0) {
            return ['success' => false, 'error' => "Δεν είναι δυνατή η διαγραφή του σχολικού έτους «{$row['name']}» επειδή περιέχει {$stuCount} εγγεγραμμένους μαθητές."];
        }

        $stmt = $db->prepare("DELETE FROM academic_years WHERE id = ?");
        $stmt->execute([$yearId]);
        getActiveAcademicYear(true);
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => safeError($e)];
    }
}
