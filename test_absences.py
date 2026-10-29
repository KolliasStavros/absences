import unittest
from datetime import date

from absences import AttendanceBook, AttendanceStatus


class AttendanceBookTests(unittest.TestCase):
    def test_absence_tracking_and_summary(self) -> None:
        book = AttendanceBook()
        book.add_student("S1", "Alice")
        book.add_student("S2", "Bob")

        day = date(2026, 10, 1)
        book.mark_present("S1", day)
        book.mark_absent("S2", day, reason="Sick")

        summary = book.daily_summary(day)
        self.assertEqual(summary[AttendanceStatus.PRESENT], 1)
        self.assertEqual(summary[AttendanceStatus.ABSENT], 1)
        self.assertEqual(summary[AttendanceStatus.EXCUSED_ABSENT], 0)

        absences = book.get_student_absences("S2")
        self.assertEqual(len(absences), 1)
        self.assertEqual(absences[0].reason, "Sick")
        self.assertFalse(absences[0].excused)

    def test_excused_absences_and_thresholds(self) -> None:
        book = AttendanceBook()
        book.add_student("S1", "Alice")
        book.add_student("S2", "Bob")

        book.mark_absent("S1", date(2026, 10, 1), excused=True, reason="Doctor note")
        book.mark_absent("S2", date(2026, 10, 1), excused=False)
        book.mark_absent("S2", date(2026, 10, 2), excused=False)

        self.assertEqual(book.total_absences("S1"), 1)
        self.assertEqual(book.total_absences("S1", include_excused=False), 0)
        self.assertEqual(book.total_absences("S2", include_excused=False), 2)

        at_risk = book.students_with_many_absences(2, include_excused=False)
        self.assertEqual([student.student_id for student in at_risk], ["S2"])


if __name__ == "__main__":
    unittest.main()
