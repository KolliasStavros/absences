-- Migration: Protect attendance historical records from ON DELETE CASCADE
-- Date: 2026-10-23
-- Task: DATA-01
-- Description:
-- Modifies the foreign keys on the attendance table to prevent accidental deletion
-- of historical absence data when a student, section, teacher/user, or academic year is removed.
-- Instead of ON DELETE CASCADE, ON DELETE RESTRICT (or NO ACTION) is enforced at the database level.
-- NOTE: Do NOT run on production without taking a database backup first.

-- Step 1: Identify existing constraint names on attendance table (InnoDB defaults: attendance_ibfk_1 .. 4)
-- We drop the existing cascading foreign keys:

ALTER TABLE `attendance` 
    DROP FOREIGN KEY `attendance_ibfk_1`,
    DROP FOREIGN KEY `attendance_ibfk_2`,
    DROP FOREIGN KEY `attendance_ibfk_3`,
    DROP FOREIGN KEY `attendance_ibfk_4`;

-- Step 2: Re-add foreign keys with ON DELETE RESTRICT
ALTER TABLE `attendance`
    ADD CONSTRAINT `fk_attendance_academic_year`
        FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years`(`id`) ON DELETE RESTRICT,
    ADD CONSTRAINT `fk_attendance_section`
        FOREIGN KEY (`section_id`) REFERENCES `sections`(`id`) ON DELETE RESTRICT,
    ADD CONSTRAINT `fk_attendance_student`
        FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE RESTRICT,
    ADD CONSTRAINT `fk_attendance_teacher`
        FOREIGN KEY (`teacher_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT;
