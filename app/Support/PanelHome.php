<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Single source for the panel landing URL: users who can view dashboard
 * widgets (`view dashboard widgets` or `manage incidents` — see
 * User::canViewDashboardWidgets()) land on the Dashboard, everyone else on
 * the incidents list (sending an account there is a guaranteed 403).
 * Login and the profile page must agree.
 */
class PanelHome
{
    public static function urlFor(?Authenticatable $user): string
    {
        return $user instanceof User && $user->canViewDashboardWidgets()
            ? route('filament.admin.pages.dashboard')
            : route('filament.admin.resources.incidents.index');
    }
}
