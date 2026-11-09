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
