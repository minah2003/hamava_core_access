<?php

namespace Hamava\CoreAccess\Services;

use Hamava\CoreAccess\Models\CoreAccessNode;
use Hamava\CoreAccess\Models\CorePermission;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

class CoreNavigationResolver
{
    public function __construct(
        private readonly CoreAccessResolver $access,
        private readonly CoreAccessContext $context,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function forUser(
        ?Authenticatable $user,
        string $moduleCode,
    ): Collection {
        if (! $user) {
            return collect();
        }

        $module = $this->context->module($moduleCode);

        if (! $module || ! $module->is_enabled) {
            return collect();
        }

        $nodes = CoreAccessNode::query()
            ->where('module_id', $module->id)
            ->where('is_visible_in_navigation', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($nodes->isEmpty()) {
            return collect();
        }

        $modulePermission = $moduleCode.config(
            'core-access.module_permission_suffix',
            '.module.view',
        );

        /*
         * Load all active permissions that may participate in navigation:
         *
         * 1. Permissions belonging to the module.
         * 2. Legacy/global permissions attached directly to a visible node.
         * 3. The configured module-entry permission, even when module_id is null.
         *
         * accessNode is eager-loaded because canEnterAccessNode() reads its
         * requires_scope flag.
         */
        $permissions = CorePermission::query()
            ->with('accessNode')
            ->where('is_active', true)
            ->where(function ($query) use (
                $module,
                $nodes,
                $modulePermission,
            ): void {
                $query
                    ->where('module_id', $module->id)
                    ->orWhereIn(
                        'access_node_id',
                        $nodes->pluck('id'),
                    )
                    ->orWhere('name', $modulePermission);
            })
            ->get();

        /*
         * A direct CorePermission query does not automatically populate
         * CoreAccessContext. Prime it so canEnterAccessNode() does not query
         * every capability again.
         */
        $this->context->primePermissions($permissions);

        $permissionsByNode = $permissions
            ->whereNotNull('access_node_id')
            ->groupBy(
                fn (CorePermission $permission): int => (int) $permission
                    ->access_node_id
            );

        $capabilitiesByNode = $nodes->mapWithKeys(
            fn (CoreAccessNode $node): array => [
                $node->getKey() => $this->capabilitiesForNode(
                    $node,
                    $moduleCode,
                    $permissions,
                    $permissionsByNode,
                ),
            ]
        );

        $enterableCapabilities = $this->access
            ->enterableCapabilities(
                $user,
                $capabilitiesByNode->flatten(1),
                $moduleCode,
            )
            ->flip();

        $accessibleIds = $capabilitiesByNode
            ->filter(
                fn (Collection $capabilities): bool => $capabilities->contains(
                    fn (string $capability): bool => $enterableCapabilities
                        ->has($capability)
                )
            )
            ->keys()
            ->all();

        $withParents = $this->includeParents(
            $nodes,
            $accessibleIds,
        );

        return $this->buildTree(
            $nodes->whereNull('parent_id'),
            $nodes,
            $withParents,
        );
    }

    /**
     * @param  Collection<int, CorePermission>  $permissions
     * @param  Collection<int|string, Collection<int, CorePermission>>  $permissionsByNode
     * @return Collection<int, string>
     */
    private function capabilitiesForNode(
        CoreAccessNode $node,
        string $moduleCode,
        Collection $permissions,
        Collection $permissionsByNode,
    ): Collection {
        /*
         * Directly attached permissions take precedence over the legacy
         * name-prefix fallback. This preserves the current behavior.
         */
        $nodePermissions = $permissionsByNode->get(
            (int) $node->getKey(),
            collect(),
        );

        if ($nodePermissions->isEmpty()) {
            $prefix = "{$node->code}.";

            $nodePermissions = $permissions
                ->filter(
                    fn (CorePermission $permission): bool => (string) $permission
                        ->module_id === (string) $node->module_id
                        && str_starts_with(
                            (string) $permission->name,
                            $prefix,
                        )
                )
                ->values();
        }

        if (
            $nodePermissions->isEmpty()
            && $node->node_type === 'module'
        ) {
            $modulePermission = $moduleCode.config(
                'core-access.module_permission_suffix',
                '.module.view',
            );

            $nodePermissions = $permissions
                ->filter(
                    fn (CorePermission $permission): bool => $permission->name
                        === $modulePermission
                )
                ->values();
        }

        return $nodePermissions
            ->pluck('name')
            ->unique()
            ->values();
    }

    /**
     * @param  Collection<int, CoreAccessNode>  $nodes
     * @param  list<int|string>  $ids
     * @return list<int|string>
     */
    private function includeParents(Collection $nodes, array $ids): array
    {
        $all = collect($ids);
        $byId = $nodes->keyBy('id');

        foreach ($ids as $id) {
            $node = $byId->get($id);

            while ($node && $node->parent_id) {
                $all->push($node->parent_id);
                $node = $byId->get($node->parent_id);
            }
        }

        return $all->unique()->values()->all();
    }

    /**
     * @param  Collection<int, CoreAccessNode>  $roots
     * @param  Collection<int, CoreAccessNode>  $all
     * @param  list<int|string>  $accessibleIds
     * @return Collection<int, array<string, mixed>>
     */
    private function buildTree(Collection $roots, Collection $all, array $accessibleIds): Collection
    {
        return $roots
            ->filter(fn (CoreAccessNode $node): bool => in_array($node->id, $accessibleIds, true))
            ->map(fn (CoreAccessNode $node): array => [
                'id' => $node->id,
                'code' => $node->code,
                'label' => $node->label,
                'label_fa' => $node->label_fa,
                'label_translation_key' => $node->label_translation_key,
                'route_name' => $node->route_name,
                'url_path' => $node->url_path,
                'icon' => $node->icon,
                'children' => $this->buildTree($all->where('parent_id', $node->id), $all, $accessibleIds)->values()->all(),
            ])
            ->values();
    }
}
