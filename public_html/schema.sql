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
('email_template_body', 'Αγαπητέ/ή κηδεμόνα,\n\nΣας ενημερώνουμε για τις παρακάτω απουσίες {student_title_gen} {student_genitive}:\n\nΗμερομηνία: {date}\nΏρες απουσίας: {hours}\n\nΓια οποιαδήποτε διευκρίνιση, μπορείτε να επικοινωνήσετε με το σχολείο.\n\nΜε εκτίμηση,\nΗ Διεύθυνση\n{school_name}\nΤηλ.: {school_phone}'),
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

-- Grading module. Additive migration with no teacher tax identifiers.
CREATE TABLE IF NOT EXISTS subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source_name VARCHAR(255) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY unique_subject_source (source_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS course_offerings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    academic_year_id INT NOT NULL,
    section_id INT NOT NULL,
    subject_id INT NOT NULL,
    weekly_hours DECIMAL(6,2) DEFAULT NULL,
    has_written_exam TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY unique_course_year (academic_year_id,section_id,subject_id),
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT,
    FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE RESTRICT,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS course_teachers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_offering_id INT NOT NULL,
    teacher_id INT NOT NULL,
    assignment_type VARCHAR(100) NOT NULL DEFAULT '',
    can_edit_marks TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY unique_course_teacher (course_offering_id,teacher_id),
    FOREIGN KEY (course_offering_id) REFERENCES course_offerings(id) ON DELETE RESTRICT,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessment_periods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    academic_year_id INT NOT NULL,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(100) NOT NULL,
    min_mark DECIMAL(6,2) NOT NULL DEFAULT 0,
    max_mark DECIMAL(6,2) NOT NULL DEFAULT 20,
    mark_step DECIMAL(6,2) NOT NULL DEFAULT 1,
    is_open TINYINT(1) NOT NULL DEFAULT 1,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY unique_period_year (academic_year_id,code),
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS course_students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_offering_id INT NOT NULL,
    student_id INT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY unique_course_student (course_offering_id,student_id),
    FOREIGN KEY (course_offering_id) REFERENCES course_offerings(id) ON DELETE RESTRICT,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_marks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_student_id INT NOT NULL,
    assessment_period_id INT NOT NULL,
    mark_value DECIMAL(6,2) DEFAULT NULL,
    mark_status VARCHAR(20) NOT NULL DEFAULT 'ungraded',
    updated_by INT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY unique_student_period (course_student_id,assessment_period_id),
    FOREIGN KEY (course_student_id) REFERENCES course_students(id) ON DELETE RESTRICT,
    FOREIGN KEY (assessment_period_id) REFERENCES assessment_periods(id) ON DELETE RESTRICT,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mark_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_offering_id INT NOT NULL,
    assessment_period_id INT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    submitted_by INT DEFAULT NULL,
    submitted_at DATETIME DEFAULT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY unique_course_submission (course_offering_id,assessment_period_id),
    FOREIGN KEY (course_offering_id) REFERENCES course_offerings(id) ON DELETE RESTRICT,
    FOREIGN KEY (assessment_period_id) REFERENCES assessment_periods(id) ON DELETE RESTRICT,
    FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mark_audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mark_id INT DEFAULT NULL,
    submission_id INT DEFAULT NULL,
    course_offering_id INT NOT NULL,
    action VARCHAR(30) NOT NULL,
    old_value TEXT DEFAULT NULL,
    new_value TEXT DEFAULT NULL,
    changed_by INT NOT NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reason VARCHAR(255) NOT NULL DEFAULT '',
    INDEX idx_mark_audit_course (course_offering_id,changed_at),
    FOREIGN KEY (mark_id) REFERENCES student_marks(id) ON DELETE RESTRICT,
    FOREIGN KEY (submission_id) REFERENCES mark_submissions(id) ON DELETE RESTRICT,
    FOREIGN KEY (course_offering_id) REFERENCES course_offerings(id) ON DELETE RESTRICT,
    FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mark_teacher_aliases (
    source_key VARCHAR(255) PRIMARY KEY,
    teacher_id INT NOT NULL,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS calendar_managers (
    user_id INT PRIMARY KEY,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    granted_by INT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS school_calendar_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    academic_year_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    internal_notes TEXT NOT NULL,
    category ENUM('event','trip','task','note') NOT NULL DEFAULT 'event',
    status ENUM('planned','pending','completed','cancelled') NOT NULL DEFAULT 'planned',
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    start_time TIME DEFAULT NULL,
    end_time TIME DEFAULT NULL,
    visibility ENUM('private','all','selected') NOT NULL DEFAULT 'private',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NOT NULL,
    updated_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX calendar_year_dates (academic_year_id,is_deleted,start_date,end_date),
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendar_event_readers (
    event_id INT NOT NULL,
    user_id INT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (event_id,user_id),
    INDEX calendar_reader (user_id,is_active,event_id),
    FOREIGN KEY (event_id) REFERENCES school_calendar_events(id) ON DELETE RESTRICT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendar_audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT DEFAULT NULL,
    changed_by INT NOT NULL,
    action VARCHAR(40) NOT NULL,
    details TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX calendar_audit_event (event_id,id),
    FOREIGN KEY (event_id) REFERENCES school_calendar_events(id) ON DELETE RESTRICT,
    FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_edit_batches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    academic_year_id INT NOT NULL,
    actor_id INT NOT NULL,
    tab_key VARCHAR(64) NOT NULL,
    run_key VARCHAR(64) NOT NULL,
    target_date DATE NOT NULL,
    started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    last_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    undone_by INT DEFAULT NULL,
    undone_at DATETIME(6) DEFAULT NULL,
    INDEX undo_batch_actor (academic_year_id,actor_id,tab_key,id),
    INDEX undo_batch_date (academic_year_id,target_date,id),
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT,
    FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (undone_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_edit_changes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_id BIGINT UNSIGNED DEFAULT NULL,
    academic_year_id INT NOT NULL,
    actor_id INT NOT NULL,
    student_id INT NOT NULL,
    target_date DATE NOT NULL,
    hour TINYINT NOT NULL,
    before_state MEDIUMTEXT NOT NULL,
    after_state MEDIUMTEXT NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    INDEX undo_change_batch (batch_id,id),
    INDEX undo_change_slot (academic_year_id,student_id,target_date,hour,id),
    FOREIGN KEY (batch_id) REFERENCES attendance_edit_batches(id) ON DELETE RESTRICT,
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT,
    FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
