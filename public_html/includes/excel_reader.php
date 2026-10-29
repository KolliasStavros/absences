<?php
// includes/excel_reader.php - High-Performance Excel (.xls / .xlsx / .csv) Reader & MySchool Importer

require_once __DIR__ . '/greek_helper.php';

class ExcelReader {
    private const MAX_BYTES = 10485760;
    private const MAX_ROWS = 10000;
    private const MAX_COLUMNS = 100;
    private const MAX_CELLS = 200000;
    private static float $started = 0;
    private static int $memoryStarted = 0;
    private static function budget(): void {
        if (!self::$started) { self::$started=microtime(true); self::$memoryStarted=memory_get_usage(true); }
        if (microtime(true)-self::$started>15 || memory_get_usage(true)-self::$memoryStarted>67108864) throw new InvalidArgumentException('Υπέρβαση ορίων χρόνου ή μνήμης εισαγωγής.');
    }
    private static function parseXml(string $source) {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i',$source)) throw new InvalidArgumentException('Μη επιτρεπόμενο XML.');
        $old=libxml_use_internal_errors(true);
        try {
            // Bound the XML tree before constructing SimpleXML (native libxml
            // allocations are not fully reflected by memory_get_usage()).
            $reader=new XMLReader();
            if(!$reader->XML($source,null,LIBXML_NONET)) throw new InvalidArgumentException('Μη έγκυρο XML.');
            $reader->setParserProperty(XMLReader::SUBST_ENTITIES,false);
            $nodes=0;
            while($reader->read()) {
                self::budget();
                if(++$nodes>100000 || $reader->depth>32 || strlen($reader->value)>32768) {
                    $reader->close();
                    throw new InvalidArgumentException('Υπέρβαση ορίων πολυπλοκότητας XML.');
                }
            }
            $reader->close();
            if(libxml_get_errors()) throw new InvalidArgumentException('Μη έγκυρο XML.');
            $xml=simplexml_load_string($source, 'SimpleXMLElement', LIBXML_NONET);
            if($xml===false) throw new InvalidArgumentException('Μη έγκυρο XML φύλλου εργασίας.');
            return $xml;
        } finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
    }
    private static function validateFile($path): void {
        self::$started=microtime(true); self::$memoryStarted=memory_get_usage(true);
        if (!is_file($path) || filesize($path)>self::MAX_BYTES) throw new InvalidArgumentException('Το αρχείο υπερβαίνει το όριο 10MB ή δεν είναι έγκυρο.');
    }
    public static function validateSheet($sheet): void {
        self::budget();
        if (!is_array($sheet) || count($sheet)>self::MAX_ROWS) throw new InvalidArgumentException('Όριο εισαγωγής: 10.000 γραμμές.');
        $count=0; $bytes=0;
        foreach($sheet as $r=>$row) {
            self::budget();
            if(!is_array($row) || $r<1 || $r>self::MAX_ROWS || count($row)>self::MAX_COLUMNS) throw new InvalidArgumentException('Μη έγκυρες διαστάσεις φύλλου.');
            foreach($row as $c=>$v) {
                if (!is_scalar($v) && $v !== null) throw new InvalidArgumentException('Μη έγκυρο περιεχόμενο κελιού.');
                $count++; $bytes+=strlen((string)$v);
                if($c<1 || $c>self::MAX_COLUMNS || strlen((string)$v)>32768 || $count>self::MAX_CELLS || $bytes>self::MAX_BYTES) throw new InvalidArgumentException('Υπέρβαση ορίων κελιών εισαγωγής.');
            }
        }
    }
    public static function readText($content) {
        self::$started=microtime(true); self::$memoryStarted=memory_get_usage(true);
        if(strlen($content)>self::MAX_BYTES || substr_count($content,"\n")>=self::MAX_ROWS) throw new InvalidArgumentException('Υπέρβαση ορίων εισαγωγής κειμένου.');
        return self::parseCsvContent($content);
    }


    /**
     * Bounded XLSX, HTML-as-XLS and CSV reader. Binary OLE/BIFF is rejected.
     */
    public static function readFile($filePath) {
        self::validateFile($filePath);
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("Το αρχείο δεν βρέθηκε: " . basename($filePath));
        }

        $f = fopen($filePath, 'rb');
        $magic = fread($f, 8);
        fclose($f);

        // 1. Check for OLE2 Compound Document (Excel 97-2004 .xls)
        if (substr($magic, 0, 8) === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            return self::readBiff8($filePath);
        }

        // 2. Check for PK Zip (Excel 2007+ .xlsx)
        if (substr($magic, 0, 4) === "PK\x03\x04") {
            return self::readXlsx($filePath);
        }

        // 3. Check for HTML Table saved as .xls
        $preview = file_get_contents($filePath, false, null, 0, 1024);
        if (stripos($preview, '<table') !== false || stripos($preview, '<html') !== false) {
            return self::readHtmlTable($filePath);
        }

        // 4. Fallback to CSV / TXT
        return self::readCsv($filePath);
    }

    /**
     * Pure PHP BIFF8 (.xls) Reader
     */
    public static function readBiff8($filePath) {
        // The former partial OLE/BIFF reader did not safely support mini streams,
        // continuation records or malformed chains. Reject instead of invoking Office.
        throw new InvalidArgumentException('Το παλαιό δυαδικό XLS δεν υποστηρίζεται για λόγους ασφαλείας. Αποθηκεύστε το από το MySchool ως XLSX ή CSV και δοκιμάστε ξανά.');
    }

    public static function readXlsx($filePath) {
        self::validateFile($filePath);
        if (!class_exists('ZipArchive')) {
            throw new InvalidArgumentException("Η επέκταση ZipArchive δεν είναι εγκατεστημένη.");
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new InvalidArgumentException("Αδυναμία ανάγνωσης αρχείου .xlsx (Zip).");
        }

        // Inspect central-directory metadata before decompressing any entry.
        if($zip->numFiles>2000) { $zip->close(); throw new InvalidArgumentException('Υπερβολικά πολλά ZIP entries.'); }
        $total=0;
        for($i=0;$i<$zip->numFiles;$i++) {
            self::budget(); $stat=$zip->statIndex($i); $size=(int)$stat['size']; $compressed=(int)$stat['comp_size'];
            $total+=$size;
            if($size>4194304 || $total>16777216 || $size/max(1,$compressed)>100 || preg_match('#(vbaProject|externalLinks|/embeddings/)#i',$stat['name'])) {
                $zip->close(); throw new InvalidArgumentException('Το XLSX υπερβαίνει τα όρια ασφαλείας ή περιέχει macros/εξωτερικές συνδέσεις.');
            }
        }
        // Shared strings
        $sst = [];
        $sstXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sstXml) {
            if (preg_match('/<!DOCTYPE|<!ENTITY/i',$sstXml)) throw new InvalidArgumentException('Μη επιτρεπόμενο XML.');
            $xml = self::parseXml($sstXml);
            if ($xml) {
                foreach ($xml->si as $si) {
                    if (isset($si->t)) {
                        $sst[] = (string)$si->t;
                    } elseif (isset($si->r)) {
                        $text = '';
                        foreach ($si->r as $r) {
                            $text .= (string)$r->t;
                        }
                        $sst[] = $text;
                    } else {
                        $sst[] = '';
                    }
                }
            }
        }

        // Discover all worksheet XML files in the archive
        $sheetFiles = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat['name'];
            if (preg_match('#^xl/worksheets/sheet(\d+)\.xml$#i', $name, $m)) {
                $sheetFiles[(int)$m[1]] = $name;
            } elseif (preg_match('#^xl/worksheets/[^/]+\.xml$#i', $name)) {
                $sheetFiles[] = $name;
            }
        }
        ksort($sheetFiles);

        if (empty($sheetFiles)) {
            $zip->close();
            throw new InvalidArgumentException("Δεν βρέθηκε φύλλο εργασίας XML.");
        }

        $parsedCells = 0;
        $parseSheetXml = function($xmlContent) use ($sst, &$parsedCells) {
            if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xmlContent)) throw new InvalidArgumentException('Μη επιτρεπόμενο XML.');
            $xml = self::parseXml($xmlContent);
            if (!$xml) return [];
            $rows = [];
            foreach ($xml->sheetData->row as $row) {
                self::budget();
                $rNum = (int)$row['r'];
                if ($rNum < 1 || $rNum > self::MAX_ROWS) continue;
                $rowVals = [];
                foreach ($row->c as $c) {
                    if (++$parsedCells > self::MAX_CELLS) throw new InvalidArgumentException('Όριο κελιών XLSX.');
                    $ref = (string)$c['r'];
                    preg_match('/([A-Z]+)(\d+)/', $ref, $matches);
                    $colLetters = $matches[1] ?? 'A';
                    $cNum = self::columnLettersToNumber($colLetters);
                    if ($cNum > self::MAX_COLUMNS || count($rowVals) >= self::MAX_COLUMNS) continue;
                    $type = (string)$c['t'];
                    $val = (string)$c->v;
                    if ($type === 's') {
                        $rowVals[$cNum] = $sst[(int)$val] ?? '';
                    } elseif ($type === 'inlineStr' && isset($c->is->t)) {
                        $rowVals[$cNum] = (string)$c->is->t;
                    } else {
                        $rowVals[$cNum] = $val;
                    }
                }
                if (!empty($rowVals)) {
                    $rows[$rNum] = $rowVals;
                }
            }
            ksort($rows);
            return $rows;
        };

        // Check for MySchool multi-sheet paired split export (where even sheets have students and odd sheets have contacts)
        $sheetValues = array_values($sheetFiles);
        $sheetCount = count($sheetValues);
        $isMySchoolSplit = false;

        if ($sheetCount >= 2 && $sheetCount % 2 === 0) {
            $firstXml = $zip->getFromName($sheetValues[0]);
            $secondXml = $zip->getFromName($sheetValues[1]);
            $firstRows = $firstXml ? $parseSheetXml($firstXml) : [];
            $secondRows = $secondXml ? $parseSheetXml($secondXml) : [];

            $firstHasAM = false;
            foreach ($firstRows as $row) {
                $joined = mb_strtolower(implode(' ', $row), 'UTF-8');
                if (strpos($joined, 'μητρώου') !== false || strpos($joined, 'επώνυμο') !== false) {
                    $firstHasAM = true;
                    break;
                }
            }
            $secondHasContacts = false;
            foreach ($secondRows as $row) {
                $joined = mb_strtolower(implode(' ', $row), 'UTF-8');
                if (strpos($joined, 'email') !== false || strpos($joined, 'κηδεμόνα') !== false) {
                    $secondHasContacts = true;
                    break;
                }
            }

            if ($firstHasAM && $secondHasContacts) {
                $isMySchoolSplit = true;
            }
        }

        $cells = [];
        if ($isMySchoolSplit) {
            $rIdx = 1;
            // Standard Header
            $cells[$rIdx++] = [
                1 => 'Αριθμός μητρώου',
                2 => 'Επώνυμο μαθητή',
                3 => 'Όνομα μαθητή',
                4 => 'Φύλο μαθητή',
                5 => 'Όνομα πατέρα',
                6 => 'Τάξη Εγγραφής',
                7 => 'Τμήματα',
                8 => 'eMail 1ου κηδεμόνα',
                9 => 'Κινητό 1ου Κηδεμόνα',
                10 => 'eMail 2ου κηδεμόνα',
                11 => 'Κινητό 2ου Κηδεμόνα',
                12 => 'eMail μαθητή'
            ];

            for ($p = 0; $p < $sheetCount; $p += 2) {
                $sEven = ($p === 0 && !empty($firstRows)) ? $firstRows : $parseSheetXml($zip->getFromName($sheetValues[$p]));
                $sOdd = ($p === 0 && !empty($secondRows)) ? $secondRows : $parseSheetXml($zip->getFromName($sheetValues[$p + 1]));

                $studentRows = [];
                foreach ($sEven as $cols) {
                    $am = trim($cols[2] ?? '');
                    $surname = trim($cols[3] ?? '');
                    if (is_numeric($am) && !empty($surname) && $surname !== 'Επώνυμο μαθητή') {
                        $studentRows[] = $cols;
                    }
                }

                $contactRows = [];
                foreach ($sOdd as $cols) {
                    $sec = trim($cols[1] ?? '');
                    if (!empty($sec) && $sec !== 'Τμήματα') {
                        $contactRows[] = $cols;
                    }
                }

                for ($k = 0; $k < count($studentRows); $k++) {
                    $s = $studentRows[$k];
                    $c = $contactRows[$k] ?? [];

                    $cells[$rIdx++] = [
                        1 => $s[2] ?? '', // AM
                        2 => $s[3] ?? '', // Last name
                        3 => $s[4] ?? '', // First name
                        4 => $s[6] ?? '', // Gender
                        5 => $s[7] ?? '', // Father name
                        6 => $s[8] ?? '', // Grade
                        7 => $s[9] ?? '', // Sections
                        8 => $c[2] ?? '', // Email 1
                        9 => $c[3] ?? '', // Mobile 1
                        10 => $c[4] ?? '', // Email 2
                        11 => $c[5] ?? '', // Mobile 2
                        12 => $c[6] ?? ''  // Student email
                    ];
                }
            }
        } else {
            // Single sheet or standard multi-sheet concatenation
            $rIdx = 1;
            foreach ($sheetValues as $sheetFile) {
                $content = $zip->getFromName($sheetFile);
                if (!$content) continue;
                $sheetRows = $parseSheetXml($content);
                foreach ($sheetRows as $row) {
                    if ($rIdx > self::MAX_ROWS) throw new InvalidArgumentException('Όριο γραμμών XLSX.');
                    $cells[$rIdx++] = $row;
                }
            }
        }

        $zip->close();
        ksort($cells);
        self::validateSheet($cells);
        return $cells;
    }

    private static function columnLettersToNumber($letters) {
        $num = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $num = $num * 26 + (ord($letters[$i]) - 64);
        }
        return $num;
    }

    /**
     * HTML Table (.xls) Reader
     */
    public static function readHtmlTable($filePath) {
        self::validateFile($filePath);
        $content = file_get_contents($filePath);
        if (substr_count($content,'<')>100000 || preg_match('/<!ENTITY/i',$content)) throw new InvalidArgumentException('Υπέρβαση ορίων ή μη επιτρεπόμενο HTML εισαγωγής.');
        if (!preg_match('//u', $content)) {
            $content = iconv('Windows-1253', 'UTF-8//IGNORE', $content);
        }

        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $content, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $rows = $dom->getElementsByTagName('tr');

        $cells = [];
        $rNum = 1;
        if($rows->length>self::MAX_ROWS) throw new InvalidArgumentException('Όριο γραμμών HTML.');
        foreach ($rows as $tr) {
            self::budget();
            $cols = $tr->getElementsByTagName('td');
            if ($cols->length === 0) {
                $cols = $tr->getElementsByTagName('th');
            }
            if ($cols->length === 0) continue;

            $rowVals = [];
            $cNum = 1;
            foreach ($cols as $td) {
                $rowVals[$cNum++] = trim($td->textContent);
            }
            $cells[$rNum++] = $rowVals;
        }

        self::validateSheet($cells);
        return $cells;
    }

    /**
     * CSV / TXT Reader with Auto-Delimiter and Encoding Detection
     */
    public static function readCsv($filePath) {
        self::validateFile($filePath);
        return self::readText(file_get_contents($filePath));
    }

    private static function parseCsvContent($content) {
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
        }
        if (!preg_match('//u', $content)) {
            $content = iconv('Windows-1253', 'UTF-8//IGNORE', $content);
        }

        $lines = explode("\n", $content);
        $cells = [];
        $rNum = 1;

        // Detect delimiter
        $firstLine = $lines[0] ?? '';
        $delimiter = ';';
        if (strpos($firstLine, "\t") !== false) $delimiter = "\t";
        elseif (substr_count($firstLine, ',') > substr_count($firstLine, ';')) $delimiter = ',';

        foreach ($lines as $line) {
            self::budget();
            $line = trim($line);
            if (empty($line)) continue;
            $data = str_getcsv($line, $delimiter);
            $rowVals = [];
            foreach ($data as $idx => $v) {
                $rowVals[$idx + 1] = trim($v);
            }
            $cells[$rNum++] = $rowVals;
        }

        self::validateSheet($cells);
        return $cells;
    }

    /**
     * Smart MySchool Importer
     * Automatically identifies columns, creates missing grades and sections, and registers students.
     */
    public static function importMySchoolRows($sheet, $db, $academicYearId = null) {
        self::validateSheet($sheet);
        if ($academicYearId === null) {
            $activeYear = getActiveAcademicYear();
            $academicYearId = $activeYear ? (int)$activeYear['id'] : 1;
        }
        // 1. Locate Header Row
        $headerRowIdx = null;
        $colMap = [
            'am' => null,
            'last_name' => null,
            'first_name' => null,
            'gender' => null,
            'father_name' => null,
            'mother_name' => null,
            'grade' => null,
            'sections' => null,
            'guardian_email_1' => null,
            'guardian_mobile_1' => null,
            'guardian_email_2' => null,
            'guardian_mobile_2' => null,
            'student_email' => null,
            'last_name_gen' => null,
            'first_name_gen' => null,
        ];

        foreach ($sheet as $rIdx => $row) {
            self::budget();
            foreach ($row as $cIdx => $val) {
                $v = mb_strtolower(trim($val), 'UTF-8');
                if (strpos($v, 'μητρώου') !== false || $v === 'αμ' || $v === 'α.μ.') {
                    $headerRowIdx = $rIdx;
                    break 2;
                }
            }
        }

        // If no header found, assume standard 12-column layout starting row 1
        if ($headerRowIdx === null) {
            $colMap = [
                'am' => 1, 'last_name' => 2, 'first_name' => 3, 'gender' => 4,
                'father_name' => 5, 'grade' => 6, 'sections' => 7,
                'guardian_email_1' => 8, 'guardian_mobile_1' => 9,
                'guardian_email_2' => 10, 'guardian_mobile_2' => 11, 'student_email' => 12
            ];
            $headerRowIdx = 0;
        } else {
            // Map columns by name
            foreach ($sheet[$headerRowIdx] as $cIdx => $val) {
                $v = mb_strtolower(trim($val), 'UTF-8');
                if (strpos($v, 'μητρώου') !== false || $v === 'αμ' || $v === 'α.μ.') {
                    $colMap['am'] = $cIdx;
                } elseif (strpos($v, 'επώνυμο') !== false) {
                    $colMap['last_name'] = $cIdx;
                } elseif (strpos($v, 'όνομα μαθητή') !== false || ($v === 'όνομα' && !isset($colMap['first_name']))) {
                    $colMap['first_name'] = $cIdx;
                } elseif (strpos($v, 'φύλο') !== false) {
                    $colMap['gender'] = $cIdx;
                } elseif (strpos($v, 'πατέρα') !== false || strpos($v, 'πατρώνυμο') !== false) {
                    $colMap['father_name'] = $cIdx;
                } elseif (strpos($v, 'μητέρας') !== false || strpos($v, 'μητρός') !== false || strpos($v, 'μητρώνυμο') !== false) {
                    $colMap['mother_name'] = $cIdx;
                } elseif (strpos($v, 'γενική επωνύμου') !== false || strpos($v, 'επώνυμο γενική') !== false || $v === 'last_name_gen') {
                    $colMap['last_name_gen'] = $cIdx;
                } elseif (strpos($v, 'γενική ονόματος') !== false || strpos($v, 'όνομα γενική') !== false || $v === 'first_name_gen') {
                    $colMap['first_name_gen'] = $cIdx;
                } elseif (strpos($v, 'τάξη') !== false) {
                    $colMap['grade'] = $cIdx;
                } elseif (strpos($v, 'τμήμα') !== false) {
                    $colMap['sections'] = $cIdx;
                } elseif ((strpos($v, 'email 1') !== false || strpos($v, 'email 1ου') !== false) && !isset($colMap['guardian_email_1'])) {
                    $colMap['guardian_email_1'] = $cIdx;
                } elseif ((strpos($v, 'κινητό 1') !== false || strpos($v, 'κινητό 1ου') !== false) && !isset($colMap['guardian_mobile_1'])) {
                    $colMap['guardian_mobile_1'] = $cIdx;
                } elseif ((strpos($v, 'email 2') !== false || strpos($v, 'email 2ου') !== false) && !isset($colMap['guardian_email_2'])) {
                    $colMap['guardian_email_2'] = $cIdx;
                } elseif ((strpos($v, 'κινητό 2') !== false || strpos($v, 'κινητό 2ου') !== false) && !isset($colMap['guardian_mobile_2'])) {
                    $colMap['guardian_mobile_2'] = $cIdx;
                } elseif (strpos($v, 'email μαθητή') !== false) {
                    $colMap['student_email'] = $cIdx;
                }
            }
        }

        // Preload existing grades and sections into memory
        $stmtG = $db->query("SELECT id, name FROM grades");
        $gradesCache = [];
        while ($gRow = $stmtG->fetch(PDO::FETCH_ASSOC)) {
            $gradesCache[trim($gRow['name'])] = (int)$gRow['id'];
        }

        $stmtS = $db->query("SELECT id, grade_id, name FROM sections");
        $sectionsCache = [];
        while ($sRow = $stmtS->fetch(PDO::FETCH_ASSOC)) {
            $sectionsCache[$sRow['grade_id']][trim($sRow['name'])] = (int)$sRow['id'];
        }

        $newGradesCreated = [];
        $newSectionsCreated = [];
        $importedCount = 0;
        $updatedCount = 0;
        $currentGrade = 'Α';

        // Prepared statements for high performance
        $stmtInsertGrade = $db->prepare("INSERT INTO grades (name) VALUES (?)");
        $stmtInsertSection = $db->prepare("INSERT INTO sections (grade_id, name) VALUES (?, ?)");
        $stmtCheckStudent = $db->prepare("SELECT id FROM students WHERE am = ? AND academic_year_id = ?");
        $stmtInsertStudent = $db->prepare("
            INSERT INTO students (
                academic_year_id,
                am, last_name, first_name, father_name, mother_name, gender,
                last_name_gen, first_name_gen, student_email,
                guardian_name, guardian_name_voc, guardian_email, guardian_mobile,
                guardian_name_2, guardian_email_2, guardian_mobile_2
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                last_name = VALUES(last_name),
                first_name = VALUES(first_name),
                father_name = VALUES(father_name),
                mother_name = VALUES(mother_name),
                gender = VALUES(gender),
                last_name_gen = VALUES(last_name_gen),
                first_name_gen = VALUES(first_name_gen),
                student_email = VALUES(student_email),
                guardian_email = VALUES(guardian_email),
                guardian_mobile = VALUES(guardian_mobile),
                guardian_name_2 = VALUES(guardian_name_2),
                guardian_email_2 = VALUES(guardian_email_2),
                guardian_mobile_2 = VALUES(guardian_mobile_2)
        ");
        $stmtDelSections = $db->prepare("DELETE FROM student_sections WHERE student_id = ?");
        $stmtLinkSection = $db->prepare("INSERT IGNORE INTO student_sections (student_id, section_id) VALUES (?, ?)");

        foreach ($sheet as $rIdx => $row) {
            self::budget();
            if ($rIdx <= $headerRowIdx) continue;

            // Check for section separator like "Τάξη Εγγραφής: Β"
            $c1 = trim($row[1] ?? '');
            if (strpos($c1, 'Τάξη Εγγραφής') !== false) {
                foreach ($row as $cVal) {
                    $cVal = trim($cVal);
                    if (in_array($cVal, ['Α', 'Β', 'Γ', 'Δ', 'Ε', 'ΣΤ'])) {
                        $currentGrade = $cVal;
                        break;
                    }
                }
                continue;
            }

            // Extract student AM
            $am = trim($row[$colMap['am']] ?? '');
            if (empty($am) || !is_numeric($am)) continue;

            $lastName = trim($row[$colMap['last_name']] ?? '');
            $firstName = trim($row[$colMap['first_name']] ?? '');
            if (empty($lastName) || empty($firstName)) continue;

            $fatherName = trim($row[$colMap['father_name']] ?? '');
            $motherName = !empty($colMap['mother_name']) ? trim($row[$colMap['mother_name']] ?? '') : '';
            $rawGender = trim($row[$colMap['gender']] ?? '');

            // Parse Gender
            $gender = 'male';
            $g = mb_strtolower($rawGender, 'UTF-8');
            if (in_array($g, ['θ', 'θήλυ', 'θηλυ', 'f', 'female', 'κορίτσι', 'μαθήτρια'])) {
                $gender = 'female';
            } elseif (in_array($g, ['α', 'άρρεν', 'αρρεν', 'm', 'male', 'αγόρι', 'μαθητής'])) {
                $gender = 'male';
            } else {
                $gender = detect_gender_from_name($firstName);
            }

            // Grade: Ensure it is a valid grade name, not misidentified parent name
            $gradeName = trim($row[$colMap['grade']] ?? '');
            if (!empty($gradeName) && (mb_strlen($gradeName) > 4 || (!in_array($gradeName, ['Α', 'Β', 'Γ', 'Δ', 'Ε', 'ΣΤ', "Α'", "Β'", "Γ'", 'A', 'B', 'C', '1', '2', '3', '4', '5', '6']) && !preg_match('/^[Α-ΩA-Z][0-9]?$/u', $gradeName)))) {
                if (empty($motherName)) {
                    $motherName = $gradeName;
                }
                $gradeName = $currentGrade ?: 'Α';
            }
            if (empty($gradeName)) $gradeName = $currentGrade ?: 'Α';

            if (!isset($gradesCache[$gradeName])) {
                $stmtInsertGrade->execute([$gradeName]);
                $newGId = (int)$db->lastInsertId();
                $gradesCache[$gradeName] = $newGId;
                $newGradesCreated[] = $gradeName;
            }
            $gradeId = $gradesCache[$gradeName];

            // Sections: Parse and ensure each exists under this grade
            $sectionsRaw = trim($row[$colMap['sections']] ?? '');
            $sectionIds = [];
            if (!empty($sectionsRaw)) {
                $parts = preg_split('/[,;\/]+/u', $sectionsRaw);
                foreach ($parts as $p) {
                    $secName = trim($p);
                    if (empty($secName)) continue;

                    if (!isset($sectionsCache[$gradeId][$secName])) {
                        // Section does not exist for this grade -> CREATE IT!
                        $stmtInsertSection->execute([$gradeId, $secName]);
                        $newSId = (int)$db->lastInsertId();
                        $sectionsCache[$gradeId][$secName] = $newSId;
                        $newSectionsCreated[$gradeName][] = $secName;
                    }
                    $sectionIds[] = $sectionsCache[$gradeId][$secName];
                }
            }

            // Student Name Grammatical Inflections (use manual if present, else auto-compute)
            $manualLastNameGen = !empty($colMap['last_name_gen']) ? trim($row[$colMap['last_name_gen']] ?? '') : '';
            $manualFirstNameGen = !empty($colMap['first_name_gen']) ? trim($row[$colMap['first_name_gen']] ?? '') : '';

            $lastNameGen = !empty($manualLastNameGen) ? $manualLastNameGen : greek_to_genitive($lastName);
            $firstNameGen = !empty($manualFirstNameGen) ? $manualFirstNameGen : greek_to_genitive($firstName);

            $guardianName = 'Κηδεμόνας 1 ' . $lastName;
            $guardianNameVoc = 'κ./κα ' . inflect_word_vocative($lastName);
            $guardianName2 = 'Κηδεμόνας 2 ' . $lastName;

            $guardianEmail1 = trim($row[$colMap['guardian_email_1']] ?? '');
            $guardianMobile1 = trim($row[$colMap['guardian_mobile_1']] ?? '');
            $guardianEmail2 = trim($row[$colMap['guardian_email_2']] ?? '');
            $guardianMobile2 = trim($row[$colMap['guardian_mobile_2']] ?? '');
            $studentEmail = trim($row[$colMap['student_email']] ?? '');

            // Check if existing student in active academic year
            $stmtCheckStudent->execute([$am, $academicYearId]);
            $existingId = $stmtCheckStudent->fetchColumn();

            $stmtInsertStudent->execute([
                $academicYearId,
                $am, $lastName, $firstName, $fatherName, $motherName, $gender,
                $lastNameGen, $firstNameGen, $studentEmail,
                $guardianName, $guardianNameVoc, $guardianEmail1, $guardianMobile1,
                $guardianName2, $guardianEmail2, $guardianMobile2
            ]);

            $studentId = $existingId ? (int)$existingId : (int)$db->lastInsertId();
            if ($existingId) {
                $updatedCount++;
            } else {
                $importedCount++;
            }

            // Sync student sections
            if ($studentId && !empty($sectionIds)) {
                $stmtDelSections->execute([$studentId]);
                foreach ($sectionIds as $sId) {
                    $stmtLinkSection->execute([$studentId, $sId]);
                }
            }
        }

        return [
            'imported' => $importedCount,
            'updated' => $updatedCount,
            'new_grades' => array_unique($newGradesCreated),
            'new_sections' => $newSectionsCreated,
        ];
    }

    /**
     * Transliterate Greek name to clean Latin characters for emails
     */
    public static function transliterateGreek($str) {
        $map = [
            'Α'=>'a','Β'=>'v','Γ'=>'g','Δ'=>'d','Ε'=>'e','Ζ'=>'z','Η'=>'i','Θ'=>'th',
            'Ι'=>'i','Κ'=>'k','Λ'=>'l','Μ'=>'m','Ν'=>'n','Ξ'=>'x','Ο'=>'o','Π'=>'p',
            'Ρ'=>'r','Σ'=>'s','Τ'=>'t','Υ'=>'y','Φ'=>'f','Χ'=>'ch','Ψ'=>'ps','Ω'=>'o',
            'Ά'=>'a','Έ'=>'e','Ή'=>'i','Ί'=>'i','Ό'=>'o','Ύ'=>'y','Ώ'=>'o','Ϊ'=>'i','Ϋ'=>'y'
        ];
        $clean = mb_strtoupper(trim((string)$str), 'UTF-8');
        $trans = strtr($clean, $map);
        return preg_replace('/[^a-z0-9]/', '', strtolower($trans));
    }

    /**
     * Import Teachers and their Section Assignments from MySchool Excel (.xls/.xlsx/CSV)
     */
    public static function importMySchoolTeachers($sheet, PDO $db, $academicYearId = null) {
        self::validateSheet($sheet);
        if (!$academicYearId) {
            $activeYear = getActiveAcademicYear();
            $academicYearId = $activeYear ? (int)$activeYear['id'] : 1;
        }

        // 1. Locate Header Row
        $headerRowIdx = null;
        $lastNameCol = 1;
        $firstNameCol = 2;
        $afmCol = 4;
        $sectionCol = 8;

        foreach ($sheet as $rIdx => $row) {
            self::budget();
            foreach ($row as $cIdx => $cellVal) {
                $val = trim((string)$cellVal);
                if (mb_stripos($val, 'Επώνυμο', 0, 'UTF-8') !== false) {
                    $lastNameCol = $cIdx;
                    $headerRowIdx = $rIdx;
                }
                if ($val === 'Όνομα' || (mb_stripos($val, 'Όνομα', 0, 'UTF-8') === 0 && mb_stripos($val, 'πατέρα', 0, 'UTF-8') === false)) {
                    $firstNameCol = $cIdx;
                }
                if (mb_stripos($val, 'Α.Φ.Μ', 0, 'UTF-8') !== false || mb_stripos($val, 'ΑΦΜ', 0, 'UTF-8') !== false) {
                    $afmCol = $cIdx;
                }
                if (mb_stripos($val, 'Τμήμα', 0, 'UTF-8') !== false) {
                    $sectionCol = $cIdx;
                }
            }
            if ($headerRowIdx !== null) break;
        }

        if ($headerRowIdx === null) {
            $headerRowIdx = 14;
        }

        // 2. Preload grades and sections
        $stmtG = $db->query("SELECT id, name FROM grades");
        $gradesCache = [];
        while ($g = $stmtG->fetch(PDO::FETCH_ASSOC)) {
            $gradesCache[trim($g['name'])] = (int)$g['id'];
        }

        $stmtS = $db->query("SELECT id, name, grade_id FROM sections");
        $sectionsCache = [];
        while ($s = $stmtS->fetch(PDO::FETCH_ASSOC)) {
            $sectionsCache[trim($s['name'])] = (int)$s['id'];
        }

        $stmtInsertGrade = $db->prepare("INSERT INTO grades (name) VALUES (?)");
        $stmtInsertSection = $db->prepare("INSERT INTO sections (grade_id, name) VALUES (?, ?)");

        // 3. Parse teachers and sections
        $teachersMap = [];
        $currentTeacherKey = null;

        foreach ($sheet as $rIdx => $row) {
            self::budget();
            if ($rIdx <= $headerRowIdx) continue;

            $cLastName = trim((string)($row[$lastNameCol] ?? ''));
            $cFirstName = trim((string)($row[$firstNameCol] ?? ''));
            $cAfm = trim((string)($row[$afmCol] ?? ''));
            $cSection = trim((string)($row[$sectionCol] ?? ''));

            // Check if this row initiates a new teacher
            if (!empty($cLastName) && mb_stripos($cLastName, 'Επώνυμο', 0, 'UTF-8') === false && mb_stripos($cLastName, 'Σύνολο', 0, 'UTF-8') === false) {
                $currentTeacherKey = $cLastName . '|' . $cFirstName;
                if (!isset($teachersMap[$currentTeacherKey])) {
                    $teachersMap[$currentTeacherKey] = [
                        'last_name' => $cLastName,
                        'first_name' => $cFirstName,
                        'afm' => $cAfm,
                        'sections' => []
                    ];
                }
                if (!empty($cAfm) && empty($teachersMap[$currentTeacherKey]['afm'])) {
                    $teachersMap[$currentTeacherKey]['afm'] = $cAfm;
                }
            }

            // Collect section for current teacher
            if ($currentTeacherKey && !empty($cSection) && mb_stripos($cSection, 'Τμήμα', 0, 'UTF-8') === false) {
                if (!in_array($cSection, $teachersMap[$currentTeacherKey]['sections'])) {
                    $teachersMap[$currentTeacherKey]['sections'][] = $cSection;
                }
            }
        }

        if (empty($teachersMap)) {
            throw new InvalidArgumentException("Δεν βρέθηκαν εκπαιδευτικοί στο αρχείο. Βεβαιωθείτε ότι επιλέξατε το αρχείο «Αναθέσεις εκπαιδευτικών μονάδας» του MySchool.");
        }

        // 4. Upsert Teachers & Section Assignments
        $importedCount = 0;
        $updatedCount = 0;
        $totalAssignments = 0;
        $newSectionsCreated = [];
        $teacherSummaries = [];

        $stmtFindTeacher = $db->prepare("SELECT id, name, email, status FROM users WHERE name = ? AND role = 'teacher'");
        $stmtFindTeacherByEmail = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmtInsertTeacher = $db->prepare("INSERT INTO users (name, email, role, status) VALUES (?, ?, 'teacher', 'pending_activation')");
        $stmtClearAssignments = $db->prepare("DELETE FROM teacher_sections WHERE teacher_id = ? AND academic_year_id = ?");
        $stmtAssignSection = $db->prepare("INSERT IGNORE INTO teacher_sections (academic_year_id, teacher_id, section_id) VALUES (?, ?, ?)");

        foreach ($teachersMap as $tKey => $tData) {
            $fullName = $tData['last_name'] . ' ' . $tData['first_name'];
            $afm = $tData['afm'];
            $sections = $tData['sections'];

            // Match teacher by full name
            $stmtFindTeacher->execute([$fullName]);
            $existing = $stmtFindTeacher->fetch(PDO::FETCH_ASSOC);

            $teacherId = null;
            $isNew = false;

            if ($existing) {
                $teacherId = (int)$existing['id'];
                $updatedCount++;
            } else {
                // Generate clean, unique email
                $latinLast = self::transliterateGreek($tData['last_name']);
                $latinFirst = self::transliterateGreek($tData['first_name']);
                $baseEmail = strtolower($latinLast . '.' . $latinFirst);
                if (empty($baseEmail) || $baseEmail === '.') {
                    $baseEmail = 'teacher.' . ($afm ?: rand(1000, 9999));
                }
                $emailCandidate = $baseEmail . '@myschool.local';

                $counter = 1;
                while (true) {
                    $stmtFindTeacherByEmail->execute([$emailCandidate]);
                    if (!$stmtFindTeacherByEmail->fetch()) {
                        break;
                    }
                    self::budget();
                    if($counter>1000) throw new InvalidArgumentException('Αδυναμία δημιουργίας μοναδικού λογαριασμού.');
                    $emailCandidate = $baseEmail . '.' . $counter++ . '@myschool.local';
                }

                $stmtInsertTeacher->execute([$fullName, $emailCandidate]);
                $teacherId = (int)$db->lastInsertId();
                $importedCount++;
                $isNew = true;
            }

            // Sync Section Assignments
            $stmtClearAssignments->execute([$teacherId, $academicYearId]);

            $assignedSecCount = 0;
            foreach ($sections as $secName) {
                $secName = trim($secName);
                if (empty($secName)) continue;

                $secId = $sectionsCache[$secName] ?? null;
                if (!$secId) {
                    // Create grade if needed
                    $firstChar = mb_substr($secName, 0, 1, 'UTF-8');
                    $gName = in_array($firstChar, ['Α', 'Β', 'Γ', 'Δ', 'Ε', 'ΣΤ']) ? $firstChar : 'Α';

                    if (!isset($gradesCache[$gName])) {
                        $stmtInsertGrade->execute([$gName]);
                        $gradesCache[$gName] = (int)$db->lastInsertId();
                    }
                    $gId = $gradesCache[$gName];

                    // Create section
                    $stmtInsertSection->execute([$gId, $secName]);
                    $secId = (int)$db->lastInsertId();
                    $sectionsCache[$secName] = $secId;
                    $newSectionsCreated[] = $secName;
                }

                $stmtAssignSection->execute([$academicYearId, $teacherId, $secId]);
                $assignedSecCount++;
                $totalAssignments++;
            }

            $teacherSummaries[] = [
                'name' => $fullName,
                'is_new' => $isNew,
                'sections_count' => $assignedSecCount,
                'sections' => $sections
            ];
        }

        return [
            'total' => count($teachersMap),
            'imported' => $importedCount,
            'updated' => $updatedCount,
            'total_assignments' => $totalAssignments,
            'new_sections' => array_unique($newSectionsCreated),
            'teachers' => $teacherSummaries
        ];
    }
}
