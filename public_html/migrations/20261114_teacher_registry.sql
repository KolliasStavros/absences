-- Existing installations: run once; absent registry numbers remain NULL.
ALTER TABLE users ADD COLUMN teacher_registry_number VARCHAR(30) DEFAULT NULL,
    ADD UNIQUE KEY unique_teacher_registry_number (teacher_registry_number);
