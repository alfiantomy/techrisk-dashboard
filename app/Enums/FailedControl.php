<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Which control layer failed (PDR 3×3 matrix: Prevent / Detect / Respond).
 * Set at closure — AI suggestion is a follow-up; human confirms.
 */
enum FailedControl: string
{
    use HasOptions;

    case Prevention = 'Prevention';
    case Detection = 'Detection';
    case Response = 'Response';

    public function color(): string
    {
        return match ($this) {
            self::Prevention => 'danger',
            self::Detection => 'warning',
            self::Response => 'gray',
        };
    }
}
