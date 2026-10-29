CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `email` VARCHAR(150) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) DEFAULT NULL,
    `auth_version` INT UNSIGNED NOT NULL DEFAULT 1,
    `role` ENUM('admin', 'teacher', 'teacher_admin') NOT NULL DEFAULT 'teacher',
    `status` ENUM('active', 'inactive', 'pending_activation') NOT NULL DEFAULT 'active',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `last_active_at` DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_resets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `token` VARCHAR(100) NOT NULL UNIQUE,
    `type` ENUM('reset', 'invitation') NOT NULL DEFAULT 'reset',
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `academic_years` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL UNIQUE,
    `is_active` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `academic_years` (`id`, `name`, `is_active`) VALUES (1, '2026-2027', 1)
ON DUPLICATE KEY UPDATE `is_active` = `is_active`;

CREATE TABLE IF NOT EXISTS `grades` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sections` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `grade_id` INT NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    UNIQUE KEY `unique_grade_section` (`grade_id`, `name`),
    FOREIGN KEY (`grade_id`) REFERENCES `grades`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `students` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `academic_year_id` INT NOT NULL DEFAULT 1,
    `am` VARCHAR(50) NOT NULL,
    `last_name` VARCHAR(100) NOT NULL,
    `first_name` VARCHAR(100) NOT NULL,
    `father_name` VARCHAR(100) DEFAULT NULL,
    `mother_name` VARCHAR(100) DEFAULT NULL,
    `gender` ENUM('male', 'female') NOT NULL DEFAULT 'male',
    `last_name_gen` VARCHAR(100) DEFAULT NULL,
    `first_name_gen` VARCHAR(100) DEFAULT NULL,
    `student_email` VARCHAR(150) DEFAULT NULL,
    `guardian_name` VARCHAR(150) DEFAULT NULL,
    `guardian_name_voc` VARCHAR(150) DEFAULT NULL,
    `guardian_email` VARCHAR(150) DEFAULT NULL,
    `guardian_mobile` VARCHAR(50) DEFAULT NULL,
    `guardian_name_2` VARCHAR(150) DEFAULT NULL,
    `guardian_email_2` VARCHAR(150) DEFAULT NULL,
    `guardian_mobile_2` VARCHAR(50) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_year_am` (`academic_year_id`, `am`),
    FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `student_sections` (
    `student_id` INT NOT NULL,
    `section_id` INT NOT NULL,
    PRIMARY KEY (`student_id`, `section_id`),
    FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`section_id`) REFERENCES `sections`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `teacher_sections` (
    `academic_year_id` INT NOT NULL DEFAULT 1,
    `teacher_id` INT NOT NULL,
    `section_id` INT NOT NULL,
    PRIMARY KEY (`academic_year_id`, `teacher_id`, `section_id`),
    FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`teacher_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`section_id`) REFERENCES `sections`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `attendance` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `academic_year_id` INT NOT NULL DEFAULT 1,
    `date` DATE NOT NULL,
    `hour` TINYINT NOT NULL,
    `section_id` INT NOT NULL,
    `student_id` INT NOT NULL,
    `teacher_id` INT NOT NULL,
    `is_absent` TINYINT(1) NOT NULL DEFAULT 1,
    `absence_type` VARCHAR(20) NOT NULL DEFAULT 'absent',
    `note` VARCHAR(255) DEFAULT NULL,
    `email_notified` TINYINT(1) NOT NULL DEFAULT 0,
    `sms_notified` TINYINT(1) NOT NULL DEFAULT 0,
    `viber_notified` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_date_hour_student` (`date`, `hour`, `student_id`),
    INDEX `idx_academic_year` (`academic_year_id`),
    INDEX `idx_date_section` (`date`, `section_id`),
    INDEX `idx_student_year` (`student_id`, `academic_year_id`),
    FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years`(`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`section_id`) REFERENCES `sections`(`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`teacher_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notification_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `channel` ENUM('email', 'sms', 'viber') NOT NULL DEFAULT 'email',
    `student_id` INT NOT NULL,
    `recipient` VARCHAR(150) NOT NULL,
    `subject` VARCHAR(255) DEFAULT NULL,
    `message` TEXT NOT NULL,
    `hours_summary` VARCHAR(100) DEFAULT NULL,
    `status` ENUM('sent', 'failed') NOT NULL DEFAULT 'sent',
    `error_message` TEXT DEFAULT NULL,
    `sent_by` INT NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`sent_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `smtp_accounts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(100) NOT NULL,
    `from_email` VARCHAR(150) NOT NULL,
    `from_name` VARCHAR(150) NOT NULL,
    `host` VARCHAR(150) NOT NULL,
    `port` INT NOT NULL DEFAULT 465,
    `encryption` ENUM('ssl', 'tls', 'none') NOT NULL DEFAULT 'ssl',
    `username` VARCHAR(150) DEFAULT NULL,
    `password` VARCHAR(255) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
    `setting_key` VARCHAR(100) PRIMARY KEY,
    `setting_value` TEXT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('school_name', '1ο Γενικό Λύκειο'),
('school_email', 'mail@myschool.mysch.gr'),
('school_phone', '2100000000'),
('smtp_host', 'mail.sch.gr'),
('smtp_port', '465'),
('smtp_secure', 'ssl'),
('smtp_user', ''),
('smtp_pass', ''),
('email_template_subject', 'Ενημέρωση Απουσιών Μαθητή - {school_name}'),
('email_template_body', 'Αγαπητέ κηδεμόνα {guardian_name_voc},\n\nΣας ενημερώνουμε ότι {student_title} {student_full_name} σημείωσε σήμερα {date} απουσία κατά τις εξής διδακτικές ώρες: {hours}.\n\nΜε εκτίμηση,\nΗ Διεύθυνση του {school_name}\nΤηλ: {school_phone}'),
('mask_student_name', '0')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;


CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `ip_address` VARCHAR(45) NOT NULL,
    `email` VARCHAR(150) NOT NULL,
    `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ip_time` (`ip_address`, `attempted_at`),
    INDEX `idx_email_time` (`email`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reset_requests` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ip_hash` CHAR(64) NOT NULL,
    `identifier_hash` CHAR(64) NOT NULL,
    `requested_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_reset_ip` (`ip_hash`, `requested_at`),
    INDEX `idx_reset_identifier` (`identifier_hash`, `requested_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Table: attendance_notes (independent hour notes)
CREATE TABLE IF NOT EXISTS `attendance_notes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `academic_year_id` INT NOT NULL DEFAULT 1,
    `student_id` INT NOT NULL,
    `date` DATE NOT NULL,
    `hour` TINYINT NOT NULL,
    `note` VARCHAR(255) NOT NULL,
    `teacher_id` INT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_student_date_hour_note` (`student_id`, `date`, `hour`),
    INDEX `idx_date_year` (`date`, `academic_year_id`),
    FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS absence_day_reasons (
    academic_year_id INT NOT NULL,
    student_id INT NOT NULL,
    date DATE NOT NULL,
    unjustified_reason VARCHAR(255) DEFAULT NULL,
    justification_reason VARCHAR(512) DEFAULT NULL,
    protocol VARCHAR(255) DEFAULT NULL,
    justified_by INT DEFAULT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (academic_year_id, student_id, date),
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (justified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
