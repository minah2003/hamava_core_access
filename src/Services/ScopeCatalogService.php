<?php

namespace Hamava\CoreAccess\Services;

use Hamava\CoreAccess\Models\CoreAccessNode;
use Hamava\CoreAccess\Models\CoreAccessNodeScopeRule;
use Hamava\CoreAccess\Models\CoreScopeEntityProvider;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class ScopeCatalogService
{
    /**
     * @return EloquentCollection<int, CoreAccessNodeScopeRule>
     */
    public function scopeRulesForAccessNode(CoreAccessNode|int|string|null $accessNode): EloquentCollection
    {
        if (! $this->modelTableExists(CoreAccessNodeScopeRule::class)) {
            return $this->emptyScopeRules();
        }

        $accessNodeId = $this->accessNodeId($accessNode);

        if ($accessNodeId === null) {
            return $this->emptyScopeRules();
        }

        return CoreAccessNodeScopeRule::query()
            ->where('access_node_id', $accessNodeId)
            ->active()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return EloquentCollection<int, CoreAccessNodeScopeRule>
     */
    public function rulesForAccessNode(CoreAccessNode|int|string|null $accessNode): EloquentCollection
    {
        return $this->scopeRulesForAccessNode($accessNode);
    }

    public function entityProvider(string $moduleCode, string $scopeType): ?CoreScopeEntityProvider
    {
        if (! $this->modelTableExists(CoreScopeEntityProvider::class)) {
            return null;
        }

        return CoreScopeEntityProvider::query()
            ->active()
            ->forModule($moduleCode)
            ->forScopeType($scopeType)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function definitionForAccessNode(string $moduleCode, CoreAccessNode|int|string|null $accessNode = null): array
    {
        $resolvedNode = $this->resolveAccessNode($accessNode);
        $accessNodeId = $resolvedNode?->getKey() ?? $this->rawAccessNodeId($accessNode);
        $accessNodeCode = $resolvedNode?->code ?? (is_string($accessNode) && ! is_numeric($accessNode) ? $accessNode : null);
        $rules = $this->scopeRulesForAccessNode($resolvedNode ?? $accessNodeId);

        return [
            'module_code' => $moduleCode,
            'access_node_id' => $accessNodeId,
            'access_node_code' => $accessNodeCode,
            'scope_types' => $rules->pluck('scope_type')->unique()->values()->all(),
            'entity_required' => $rules->contains(fn (CoreAccessNodeScopeRule $rule): bool => (bool) $rule->requires_entity),
            'allow_include_children' => $rules->contains(fn (CoreAccessNodeScopeRule $rule): bool => (bool) $rule->allow_include_children),
            'asset_category_mode' => $this->assetCategoryMode($rules),
            'forced_asset_category' => $this->forcedAssetCategory($rules),
            'asset_type_mode' => $this->assetTypeMode($rules),
            'rules' => $rules->map(fn (CoreAccessNodeScopeRule $rule): array => $this->normalizeRule($moduleCode, $rule))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(string $moduleCode, CoreAccessNode|int|string|null $accessNode = null): array
    {
        return $this->definitionForAccessNode($moduleCode, $accessNode);
    }

    /**
     * @param  Collection<int, CoreAccessNodeScopeRule>  $rules
     */
    private function assetCategoryMode(Collection $rules): string
    {
        if ($rules->contains(fn (CoreAccessNodeScopeRule $rule): bool => $this->normalizeAssetCategoryMode($rule) === 'forced')) {
            return 'forced';
        }

        if ($rules->contains(fn (CoreAccessNodeScopeRule $rule): bool => $this->normalizeAssetCategoryMode($rule) === 'optional')) {
            return 'optional';
        }

        return 'hidden';
    }

    /**
     * @param  Collection<int, CoreAccessNodeScopeRule>  $rules
     */
    private function assetTypeMode(Collection $rules): string
    {
        return $rules->contains(fn (CoreAccessNodeScopeRule $rule): bool => $this->normalizeAssetTypeMode($rule) === 'optional')
            ? 'optional'
            : 'hidden';
    }

    /**
     * @param  Collection<int, CoreAccessNodeScopeRule>  $rules
     */
    private function forcedAssetCategory(Collection $rules): ?string
    {
        return $rules
            ->first(fn (CoreAccessNodeScopeRule $rule): bool => $this->normalizeAssetCategoryMode($rule) === 'forced')
            ?->forced_asset_category;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeRule(string $moduleCode, CoreAccessNodeScopeRule $rule): array
    {
        return [
            'id' => $rule->id,
            'module_code' => $moduleCode,
            'access_node_id' => $rule->access_node_id,
            'scope_type' => $rule->scope_type,
            'requires_entity' => (bool) $rule->requires_entity,
            'allow_include_children' => (bool) $rule->allow_include_children,
            'asset_category_mode' => $this->normalizeAssetCategoryMode($rule),
            'forced_asset_category' => $rule->forced_asset_category,
            'asset_type_mode' => $this->normalizeAssetTypeMode($rule),
            'metadata' => $rule->metadata ?? [],
        ];
    }

    private function normalizeAssetCategoryMode(CoreAccessNodeScopeRule $rule): string
    {
        if ($rule->forced_asset_category) {
            return 'forced';
        }

        return in_array($rule->asset_category_mode, ['hidden', 'optional', 'forced'], true)
            ? $rule->asset_category_mode
            : 'hidden';
    }

    private function normalizeAssetTypeMode(CoreAccessNodeScopeRule $rule): string
    {
        return $rule->asset_type_mode === 'optional' ? 'optional' : 'hidden';
    }

    private function accessNodeId(CoreAccessNode|int|string|null $accessNode): int|string|null
    {
        return $this->resolveAccessNode($accessNode)?->getKey() ?? $this->rawAccessNodeId($accessNode);
    }

    private function rawAccessNodeId(CoreAccessNode|int|string|null $accessNode): int|string|null
    {
        if ($accessNode instanceof CoreAccessNode) {
            return $accessNode->getKey();
        }

        if (is_int($accessNode) || (is_string($accessNode) && is_numeric($accessNode))) {
            return (int) $accessNode;
        }

        return null;
    }

    private function resolveAccessNode(CoreAccessNode|int|string|null $accessNode): ?CoreAccessNode
    {
        if ($accessNode instanceof CoreAccessNode) {
            return $accessNode;
        }

        if ($accessNode === null || ! $this->modelTableExists(CoreAccessNode::class)) {
            return null;
        }

        return is_numeric($accessNode)
            ? CoreAccessNode::query()->find($accessNode)
            : CoreAccessNode::query()->where('code', $accessNode)->first();
    }

    /**
     * @return EloquentCollection<int, CoreAccessNodeScopeRule>
     */
    private function emptyScopeRules(): EloquentCollection
    {
        return (new CoreAccessNodeScopeRule)->newCollection();
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function modelTableExists(string $model): bool
    {
        $instance = new $model;

        return Schema::hasTable($instance->getTable());
    }
}
