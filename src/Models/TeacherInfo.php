<?php

namespace App\Models;

class TeacherInfo {
    public function __construct(
        public int $id,
        public string $name
    ) {}
}
