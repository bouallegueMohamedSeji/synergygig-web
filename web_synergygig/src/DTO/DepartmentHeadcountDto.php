<?php

namespace App\DTO;

class DepartmentHeadcountDto
{
    public function __construct(
        public ?string $departmentName,
        public int $employeeCount
    ) {
    }
}
