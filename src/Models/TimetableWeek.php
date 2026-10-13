<?php

namespace App\Models;

class TimetableWeek {
    public function __construct(
        public int $classId,
        public string $weekStart,
        public string $weekEnd,
        public array $days
    ) {}
}
