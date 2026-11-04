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
