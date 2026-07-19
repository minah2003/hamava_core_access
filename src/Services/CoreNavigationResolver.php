<?php

namespace Hamava\CoreAccess\Services;

use Hamava\CoreAccess\Models\CoreAccessNode;
use Hamava\CoreAccess\Models\CoreModule;
use Hamava\CoreAccess\Models\CorePermission;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

class CoreNavigationResolver
{
    public function __construct(
        private readonly CoreAccessResolver $access,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function forUser(?Authenticatable $user, string $moduleCode): Collection
    {
        if (! $user) {
            return collect();
        }

        $module = CoreModule::query()
            ->where('code', $moduleCode)
            ->first();

        if (! $module || ! $module->is_enabled) {
            return collect();
        }

        $nodes = CoreAccessNode::query()
            ->where('module_id', $module->id)
            ->where('is_visible_in_navigation', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $accessibleIds = $nodes
            ->filter(fn (CoreAccessNode $node): bool => $this->nodeAccessible($user, $moduleCode, $node))
            ->pluck('id')
            ->all();

        $withParents = $this->includeParents($nodes, $accessibleIds);

        return $this->buildTree($nodes->whereNull('parent_id'), $nodes, $withParents);
    }

    private function nodeAccessible(Authenticatable $user, string $moduleCode, CoreAccessNode $node): bool
    {
        $permissions = CorePermission::query()
            ->where('access_node_id', $node->id)
            ->where('is_active', true)
            ->pluck('name');

        if ($permissions->isEmpty()) {
            $permissions = CorePermission::query()
                ->where('module_id', $node->module_id)
                ->where('name', 'like', "{$node->code}.%")
                ->where('is_active', true)
                ->pluck('name');
        }

        if ($permissions->isEmpty() && $node->node_type === 'module') {
            $permissions = collect([$moduleCode.config('core-access.module_permission_suffix', '.module.view')]);
        }

        if ($permissions->isEmpty()) {
            return false;
        }

        return $permissions->contains(
            fn (string $permission): bool => $this->access->canEnterAccessNode($user, $permission, $moduleCode)->allowed,
        );
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
                'route_name' => $node->route_name,
                'url_path' => $node->url_path,
                'icon' => $node->icon,
                'children' => $this->buildTree($all->where('parent_id', $node->id), $all, $accessibleIds)->values()->all(),
            ])
            ->values();
    }
}
