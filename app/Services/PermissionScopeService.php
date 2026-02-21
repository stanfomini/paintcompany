<?php

namespace App\Services;

use App\Models\PermissionScope;
use App\Models\SalesTerritory;
use App\Models\User;
use Illuminate\Support\Collection;

class PermissionScopeService
{
    /**
     * Check if user has a permission (role + scope overrides).
     */
    public function hasPermission(User $user, string $permission): bool
    {
        // Check for explicit revoke first
        $revoked = PermissionScope::where('user_id', $user->id)
            ->where('permission', $permission)
            ->where('scope_type', 'revoke')
            ->exists();

        if ($revoked) {
            return false;
        }

        // Check role permission
        if ($user->hasPermissionTo($permission)) {
            return true;
        }

        // Check for explicit grant
        return PermissionScope::where('user_id', $user->id)
            ->where('permission', $permission)
            ->where('scope_type', 'grant')
            ->exists();
    }

    /**
     * Get the scope value for visibility filtering.
     */
    public function getScope(User $user, string $permission): ?array
    {
        $scope = PermissionScope::where('user_id', $user->id)
            ->where('permission', $permission)
            ->whereNotNull('scope_value')
            ->first();

        return $scope?->scope_value;
    }

    /**
     * Grant a permission to a user (with optional visibility scope).
     */
    public function grantPermission(User $user, string $permission, ?array $scopeValue, User $grantedBy): PermissionScope
    {
        return PermissionScope::updateOrCreate(
            ['user_id' => $user->id, 'permission' => $permission, 'scope_type' => 'grant'],
            ['scope_value' => $scopeValue, 'granted_by' => $grantedBy->id]
        );
    }

    /**
     * Revoke a permission from a user.
     */
    public function revokePermission(User $user, string $permission, User $grantedBy): PermissionScope
    {
        return PermissionScope::updateOrCreate(
            ['user_id' => $user->id, 'permission' => $permission, 'scope_type' => 'revoke'],
            ['granted_by' => $grantedBy->id]
        );
    }

    /**
     * Remove a scope override entirely (returns to role default).
     */
    public function removeScope(User $user, string $permission): void
    {
        PermissionScope::where('user_id', $user->id)
            ->where('permission', $permission)
            ->delete();
    }

    /**
     * Get all effective permissions for display (role + scopes).
     */
    public function getEffectivePermissions(User $user): Collection
    {
        $rolePermissions = $user->getAllPermissions()->map(fn ($p) => [
            'permission' => $p->name,
            'source' => 'role',
            'scope_value' => null,
        ]);

        $scopeGrants = PermissionScope::where('user_id', $user->id)
            ->where('scope_type', 'grant')
            ->get()
            ->map(fn ($s) => [
                'permission' => $s->permission,
                'source' => 'scope_grant',
                'scope_value' => $s->scope_value,
            ]);

        $revokedPermissions = PermissionScope::where('user_id', $user->id)
            ->where('scope_type', 'revoke')
            ->pluck('permission')
            ->toArray();

        return $rolePermissions
            ->reject(fn ($p) => in_array($p['permission'], $revokedPermissions))
            ->merge($scopeGrants)
            ->unique('permission')
            ->values();
    }

    /**
     * Get territory IDs this user can see (for map filtering).
     *
     * Logic:
     * - If user has 'territories.view_all' -> return all territory IDs
     * - If user has scope_value with territory_ids -> return those
     * - Otherwise -> return territories assigned to user + their descendants
     */
    public function getVisibleTerritoryIds(User $user): array
    {
        // Owner/admin sees everything
        if ($this->hasPermission($user, 'territories.view_all')) {
            return SalesTerritory::active()->pluck('id')->toArray();
        }

        // Check for explicit territory_ids scope
        $scope = $this->getScope($user, 'territories.view_own');
        if ($scope && isset($scope['territory_ids'])) {
            return $scope['territory_ids'];
        }

        // Default: assigned territories + their descendants
        $assigned = SalesTerritory::active()
            ->where(function ($q) use ($user) {
                $q->where('assigned_user_id', $user->id)
                    ->orWhere('manager_id', $user->id);
            })
            ->with('children.children')
            ->get();

        $ids = [];
        foreach ($assigned as $territory) {
            $ids[] = $territory->id;
            $ids = array_merge($ids, $territory->getDescendantIds());
        }

        // Also include territories where user is a team member
        $teamTerritoryIds = $user->territoryTeams()->pluck('sales_territories.id')->toArray();
        $ids = array_merge($ids, $teamTerritoryIds);

        return array_unique($ids);
    }
}
