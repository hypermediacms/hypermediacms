<?php

namespace Origen\Enums;

enum Role: string
{
    case SuperAdmin = 'super_admin';
    case TenantAdmin = 'tenant_admin';
    case Editor = 'editor';
    case Author = 'author';
    case Viewer = 'viewer';

    public function level(): int
    {
        return match ($this) {
            self::SuperAdmin => 5,
            self::TenantAdmin => 4,
            self::Editor => 3,
            self::Author => 2,
            self::Viewer => 1,
        };
    }
}
