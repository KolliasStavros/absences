-- Rollback: Revert attendance foreign keys back to ON DELETE CASCADE
-- Date: 2026-10-23
-- Task: DATA-01 Rollback

ALTER TABLE `attendance` 
    DROP FOREIGN KEY `fk_attendance_academic_year`,
    DROP FOREIGN KEY `fk_attendance_section`,
    DROP FOREIGN KEY `fk_attendance_student`,
    DROP FOREIGN KEY `fk_attendance_teacher`;

ALTER TABLE `attendance`
    ADD CONSTRAINT `attendance_ibfk_1`
        FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years`(`id`) ON DELETE CASCADE,
    ADD CONSTRAINT `attendance_ibfk_2`
        FOREIGN KEY (`section_id`) REFERENCES `sections`(`id`) ON DELETE CASCADE,
    ADD CONSTRAINT `attendance_ibfk_3`
        FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE,
    ADD CONSTRAINT `attendance_ibfk_4`
        FOREIGN KEY (`teacher_id`) REFERENCES `users`(`id`) ON DELETE CASCADE;
