<?php

namespace App\Models;

class Lesson {
    public function __construct(
        public ?string $subjectCode,
        public ?string $subjectTitle,
        public ?string $teacher,
        public ?string $room,
        public ?string $groupLabel,
        public bool $isCancelled,
        public ?string $teacherFullName,
        public ?string $sourceClassLabel = null,
        public ?string $sourceClassId = null
    ) {}
}
