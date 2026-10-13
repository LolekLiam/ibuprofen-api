<?php

namespace App\Database;

use PDO;

class Database {
    private PDO $pdo;

    public function __construct(string $dbPath = 'database.sqlite') {
        $this->pdo = new PDO("sqlite:$dbPath");
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->init();
    }

    private function init() {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS schools (
                id INTEGER PRIMARY KEY,
                key TEXT UNIQUE,
                name TEXT
            );

            CREATE TABLE IF NOT EXISTS classes (
                id INTEGER PRIMARY KEY,
                school_id INTEGER,
                label TEXT,
                FOREIGN KEY (school_id) REFERENCES schools(id)
            );

            CREATE TABLE IF NOT EXISTS teachers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                full_name TEXT UNIQUE,
                short_name TEXT
            );

            CREATE TABLE IF NOT EXISTS lessons (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                class_id INTEGER,
                date TEXT,
                period_number INTEGER,
                start_time TEXT,
                end_time TEXT,
                subject_code TEXT,
                subject_title TEXT,
                teacher_name TEXT,
                teacher_full_name TEXT,
                room TEXT,
                group_label TEXT,
                is_cancelled INTEGER,
                FOREIGN KEY (class_id) REFERENCES classes(id)
            );

            CREATE INDEX IF NOT EXISTS idx_lessons_date ON lessons(date);
            CREATE INDEX IF NOT EXISTS idx_lessons_class_id ON lessons(class_id);
            CREATE INDEX IF NOT EXISTS idx_lessons_teacher_full_name ON lessons(teacher_full_name);

            CREATE TABLE IF NOT EXISTS sync_status (
                school_id INTEGER,
                class_id INTEGER,
                week_id INTEGER,
                last_sync TEXT,
                week_start TEXT,
                week_end TEXT,
                PRIMARY KEY (school_id, class_id, week_id)
            );
        ");
    }

    public function getClassesBySchool(int $schoolId): array {
        $stmt = $this->pdo->prepare("SELECT * FROM classes WHERE school_id = ?");
        $stmt->execute([$schoolId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSyncStatus(int $schoolId, int $classId, int $weekId): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM sync_status WHERE school_id = ? AND class_id = ? AND week_id = ?");
        $stmt->execute([$schoolId, $classId, $weekId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function updateSyncStatus(int $schoolId, int $classId, int $weekId, ?string $weekStart = null, ?string $weekEnd = null) {
        $stmt = $this->pdo->prepare("INSERT OR REPLACE INTO sync_status (school_id, class_id, week_id, last_sync, week_start, week_end) VALUES (?, ?, ?, datetime('now'), ?, ?)");
        $stmt->execute([$schoolId, $classId, $weekId, $weekStart, $weekEnd]);
    }

    public function getLessonsForClassAndWeek(int $classId, string $weekStart, string $weekEnd): array {
        $stmt = $this->pdo->prepare("SELECT * FROM lessons WHERE class_id = ? AND date >= ? AND date <= ? ORDER BY date, start_time, period_number");
        $stmt->execute([$classId, $weekStart, $weekEnd]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveSchool(int $id, string $key, string $name) {
        $stmt = $this->pdo->prepare("INSERT OR REPLACE INTO schools (id, key, name) VALUES (?, ?, ?)");
        $stmt->execute([$id, $key, $name]);
    }

    public function saveClass(int $id, int $schoolId, string $label) {
        $stmt = $this->pdo->prepare("INSERT OR REPLACE INTO classes (id, school_id, label) VALUES (?, ?, ?)");
        $stmt->execute([$id, $schoolId, $label]);
    }

    public function getOrAddTeacher(string $fullName, ?string $shortName = null): int {
        $stmt = $this->pdo->prepare("SELECT id FROM teachers WHERE full_name = ?");
        $stmt->execute([$fullName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return (int)$row['id'];

        $stmt = $this->pdo->prepare("INSERT INTO teachers (full_name, short_name) VALUES (?, ?)");
        $stmt->execute([$fullName, $shortName]);
        return (int)$this->pdo->lastInsertId();
    }

    public function saveLesson(int $classId, string $date, int $periodNumber, array $timeRange, $lesson) {
        $teacherId = null;
        if ($lesson->teacherFullName) {
            $this->getOrAddTeacher($lesson->teacherFullName, $lesson->teacher);
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO lessons (
                class_id, date, period_number, start_time, end_time, 
                subject_code, subject_title, teacher_name, teacher_full_name, 
                room, group_label, is_cancelled
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $classId, $date, $periodNumber, $timeRange['start'], $timeRange['end'],
            $lesson->subjectCode, $lesson->subjectTitle, $lesson->teacher, $lesson->teacherFullName,
            $lesson->room, $lesson->groupLabel, $lesson->isCancelled ? 1 : 0
        ]);
    }

    public function clearLessonsForClassAndWeek(int $classId, string $weekStart, string $weekEnd) {
        $stmt = $this->pdo->prepare("DELETE FROM lessons WHERE class_id = ? AND date >= ? AND date <= ?");
        $stmt->execute([$classId, $weekStart, $weekEnd]);
    }

    public function getTeacherTimetable(string $teacherFullName, string $weekStart, string $weekEnd): array {
        $stmt = $this->pdo->prepare("
            SELECT l.*, c.label as class_label, c.id as class_id
            FROM lessons l
            JOIN classes c ON l.class_id = c.id
            WHERE l.teacher_full_name = ? AND l.date >= ? AND l.date <= ?
            ORDER BY l.date, l.start_time, l.period_number
        ");
        $stmt->execute([$teacherFullName, $weekStart, $weekEnd]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTeacherById(int $id) {
        $stmt = $this->pdo->prepare("SELECT * FROM teachers WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getSchoolById(int $id) {
        $stmt = $this->pdo->prepare("SELECT * FROM schools WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getAllSchools(): array {
        $stmt = $this->pdo->prepare("SELECT * FROM schools");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function beginTransaction() {
        $this->pdo->beginTransaction();
    }

    public function commit() {
        $this->pdo->commit();
    }

    public function rollBack() {
        $this->pdo->rollBack();
    }
}
