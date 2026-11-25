<?php

namespace App\Support;

/**
 * Immutable user DTO mirroring Python auth.py::User.
 */
class CmtUser
{
    public function __construct(
        public readonly string $username = '',
        public readonly string $displayName = '',
        public readonly string $role = '',
        public readonly bool $authenticated = false,
        /** Partial-match filter for the customer role (e.g. "Trisco"). */
        public readonly string $customerCompany = '',
    ) {
    }

    public static function anonymous(): self
    {
        return new self;
    }

    /** Level for role comparisons; unknown roles rank lowest. */
    public function level(): int
    {
        return CmtAuth::ROLE_LEVEL[$this->role] ?? 0;
    }
}
