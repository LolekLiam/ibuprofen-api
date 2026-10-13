<?php

namespace App\Models;

class TimetableDay {
    public function __construct(
        public string $date,
        public array $lessonsByPeriod
    ) {}
}
