<?php
// includes/greek_helper.php - Smart Greek Inflection & Gender Helper

function greek_to_genitive($name) {
    $name = trim($name);
    if (empty($name)) return '';

    $words = preg_split('/\s+/u', $name);
    $result = [];
    foreach ($words as $w) {
        $result[] = inflect_word_genitive($w);
    }
    return implode(' ', $result);
}

function inflect_word_genitive($word) {
    $w = trim($word);
    $len = mb_strlen($w, 'UTF-8');
    if ($len <= 2) return $w;

    $isUpper = (mb_strtoupper($w, 'UTF-8') === $w);
    $lower = mb_strtolower($w, 'UTF-8');

    // Rule 1: Ends with -ος -> -ου
    if (mb_substr($lower, -2, 2, 'UTF-8') === 'ος') {
        $stem = mb_substr($w, 0, $len - 2, 'UTF-8');
        return $stem . ($isUpper ? 'ΟΥ' : 'ου');
    }
    // Rule 2: Ends with -ός -> -ού
    if (mb_substr($lower, -2, 2, 'UTF-8') === 'ός') {
        $stem = mb_substr($w, 0, $len - 2, 'UTF-8');
        return $stem . ($isUpper ? 'ΟΥ' : 'ού');
    }
    // Rule 3: Ends with -ης or -ής -> -η / -ή
    if (mb_substr($lower, -2, 2, 'UTF-8') === 'ης' || mb_substr($lower, -2, 2, 'UTF-8') === 'ής') {
        return mb_substr($w, 0, $len - 1, 'UTF-8');
    }
    // Rule 4: Ends with -ας or -άς -> -α / -ά
    if (mb_substr($lower, -2, 2, 'UTF-8') === 'ας' || mb_substr($lower, -2, 2, 'UTF-8') === 'άς') {
        return mb_substr($w, 0, $len - 1, 'UTF-8');
    }
    // Rule 5: Female ending in -α -> -ας
    if (mb_substr($lower, -1, 1, 'UTF-8') === 'α' || mb_substr($lower, -1, 1, 'UTF-8') === 'ά') {
        return $w . ($isUpper ? 'Σ' : 'ς');
    }
    // Rule 6: Female ending in -η -> -ης
    if (mb_substr($lower, -1, 1, 'UTF-8') === 'η' || mb_substr($lower, -1, 1, 'UTF-8') === 'ή') {
        return $w . ($isUpper ? 'Σ' : 'ς');
    }
    // Rule 7: Surnames ending in -ου remain -ου
    if (mb_substr($lower, -2, 2, 'UTF-8') === 'ου') {
        return $w;
    }

    return $w;
}

function inflect_word_vocative($word) {
    $w = trim($word);
    $len = mb_strlen($w, 'UTF-8');
    if ($len <= 2) return $w;

    $isUpper = (mb_strtoupper($w, 'UTF-8') === $w);
    $lower = mb_strtolower($w, 'UTF-8');

    // Ends with -ος -> -ε
    if (mb_substr($lower, -2, 2, 'UTF-8') === 'ος') {
        $stem = mb_substr($w, 0, $len - 2, 'UTF-8');
        return $stem . ($isUpper ? 'Ε' : 'ε');
    }
    // Ends with -ης, -ας -> remove sigma
    if (mb_substr($lower, -2, 2, 'UTF-8') === 'ης' || mb_substr($lower, -2, 2, 'UTF-8') === 'ας') {
        return mb_substr($w, 0, $len - 1, 'UTF-8');
    }

    return $w;
}

/**
 * Detect gender from Greek first name
 */
function detect_gender_from_name($firstName) {
    $lower = mb_strtolower(trim($firstName), 'UTF-8');
    // Common female endings in Greek: -α, -ά, -η, -ή, -ω
    $lastChar = mb_substr($lower, -1, 1, 'UTF-8');
    if (in_array($lastChar, ['α', 'ά', 'η', 'ή', 'ω'])) {
        return 'female';
    }
    return 'male';
}

function get_student_title($gender) {
    return ($gender === 'female') ? 'η μαθήτρια' : 'ο μαθητής';
}

function get_student_title_gen($gender) {
    return ($gender === 'female') ? 'της μαθήτριας' : 'του μαθητή';
}

function get_student_noun($gender) {
    return ($gender === 'female') ? 'μαθήτρια' : 'μαθητής';
}

function get_student_article($gender) {
    return ($gender === 'female') ? 'η' : 'ο';
}

function get_student_article_gen($gender) {
    return ($gender === 'female') ? 'της' : 'του';
}

/**
 * Mask a single word keeping the first 2 characters and replacing the rest with asterisks.
 * e.g. "Παπαδόπουλος" -> "Πα*********"
 */
function mask_word_gdpr($word) {
    $word = trim($word);
    $len = mb_strlen($word, 'UTF-8');
    if ($len <= 2) {
        return $word;
    }
    $prefix = mb_substr($word, 0, 2, 'UTF-8');
    $stars = str_repeat('*', $len - 2);
    return $prefix . $stars;
}

/**
 * Mask student full name proportionally according to length
 * e.g. "Παπαδόπουλος Νικόλαος" -> "Πα********* Νι*****"
 * e.g. "ΑΓΓΕΛΗ ΑΝΝΑ - ΧΡΙΣΤΙΝΑ" -> "ΑΓ**** ΑΝ** - ΧΡ******"
 */
function mask_student_name($fullName) {
    if (empty($fullName)) return '';
    $parts = preg_split('/(\s+|-)/u', $fullName, -1, PREG_SPLIT_DELIM_CAPTURE);
    $result = '';
    foreach ($parts as $part) {
        if (trim($part) === '' || $part === '-') {
            $result .= $part;
        } else {
            $result .= mask_word_gdpr($part);
        }
    }
    return $result;
}
