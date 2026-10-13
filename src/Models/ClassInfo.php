<?php

namespace App\Models;

class ClassInfo {
    public function __construct(
        public int $id,
        public string $label
    ) {}
}
