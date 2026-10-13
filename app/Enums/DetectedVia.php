<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How the incident was first detected (VP TechOps gap-analysis channel mix).
 * `incident_source` (Internal/External) says where it came from, not who
 * caught it — Unknown-channel FL share is a governance KPI (PDR: red >5%).
 */
enum DetectedVia: string
{
    use HasOptions;

    case Monitoring = 'Monitoring';
    case TsDashboard = 'TS Dashboard';
    case Reconciliation = 'Reconciliation';
    case Partner = 'Partner';
    case Customer = 'Customer';
    case Internal = 'Internal';
    case Unknown = 'Unknown';

    public function color(): string
    {
        return match ($this) {
            self::Monitoring, self::TsDashboard => 'success',
            self::Reconciliation => 'warning',
            self::Partner, self::Customer => 'danger',
            self::Internal => 'gray',
            self::Unknown => 'gray',
        };
    }
}
