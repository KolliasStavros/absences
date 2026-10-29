from __future__ import annotations

from dataclasses import dataclass
from datetime import date
from enum import Enum


class AttendanceStatus(str, Enum):
    PRESENT = "present"
    ABSENT = "absent"
    EXCUSED_ABSENT = "excused_absent"


@dataclass(frozen=True)
class Student:
    student_id: str
    full_name: str


@dataclass(frozen=True)
class AbsenceEntry:
    student_id: str
    day: date
    excused: bool = False
    reason: str | None = None


class AttendanceBook:
    def __init__(self) -> None:
        self._students: dict[str, Student] = {}
        self._attendance: dict[date, dict[str, AttendanceStatus]] = {}
        self._absence_details: dict[tuple[str, date], AbsenceEntry] = {}

    def add_student(self, student_id: str, full_name: str) -> Student:
        if not student_id or not full_name:
            raise ValueError("student_id and full_name are required")
        student = Student(student_id=student_id, full_name=full_name)
        self._students[student_id] = student
        return student

    def mark_present(self, student_id: str, day: date) -> None:
        self._mark_status(student_id, day, AttendanceStatus.PRESENT)
        self._absence_details.pop((student_id, day), None)

    def mark_absent(
        self, student_id: str, day: date, *, excused: bool = False, reason: str | None = None
    ) -> None:
        status = AttendanceStatus.EXCUSED_ABSENT if excused else AttendanceStatus.ABSENT
        self._mark_status(student_id, day, status)
        self._absence_details[(student_id, day)] = AbsenceEntry(
            student_id=student_id,
            day=day,
            excused=excused,
            reason=reason,
        )

    def get_student_absences(self, student_id: str, *, include_excused: bool = True) -> list[AbsenceEntry]:
        self._validate_student(student_id)
        entries = [
            entry
            for key, entry in self._absence_details.items()
            if key[0] == student_id and (include_excused or not entry.excused)
        ]
        return sorted(entries, key=lambda entry: entry.day)

    def total_absences(self, student_id: str, *, include_excused: bool = True) -> int:
        return len(self.get_student_absences(student_id, include_excused=include_excused))

    def daily_summary(self, day: date) -> dict[AttendanceStatus, int]:
        statuses = self._attendance.get(day, {})
        summary = {status: 0 for status in AttendanceStatus}
        for status in statuses.values():
            summary[status] += 1
        return summary

    def students_with_many_absences(self, threshold: int, *, include_excused: bool = True) -> list[Student]:
        if threshold < 0:
            raise ValueError("threshold must be >= 0")
        result = [
            student
            for student_id, student in self._students.items()
            if self.total_absences(student_id, include_excused=include_excused) >= threshold
        ]
        return sorted(result, key=lambda student: student.student_id)

    def _mark_status(self, student_id: str, day: date, status: AttendanceStatus) -> None:
        self._validate_student(student_id)
        self._attendance.setdefault(day, {})[student_id] = status

    def _validate_student(self, student_id: str) -> None:
        if student_id not in self._students:
            raise KeyError(f"Unknown student_id: {student_id}")
