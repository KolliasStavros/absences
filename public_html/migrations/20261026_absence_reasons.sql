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
