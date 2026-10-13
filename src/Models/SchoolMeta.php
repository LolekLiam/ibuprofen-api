<?php

namespace App\Models;

class SchoolMeta {
    public function __construct(
        public string $schoolKey,
        public int $schoolId,
        public array $classes,
        public array $teachers = [],
        public ?string $schoolName = null,
        public ?int $currentWeekId = null,
        public ?string $currentWeekLabel = null,
        public array $allWeekIds = []
    ) {}
}
