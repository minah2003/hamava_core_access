<?php

namespace Hamava\CoreAccess\Services;

use Hamava\CoreAccess\Contracts\ScopeEntityHttpOptionProvider;
use Hamava\CoreAccess\Models\CoreScopeEntityProvider;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ScopeEntityOptionProvider
{
    public function __construct(
        private readonly ScopeCatalogService $catalog,
        private readonly Container $container,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array{id: mixed, code: string, label: string}>
     */
    public function options(string $moduleCode, string $scopeType, ?string $search = null, array $filters = [], int $limit = 50): array
    {
        $provider = $this->catalog->entityProvider($moduleCode, $scopeType);

        if (! $provider) {
            return [];
        }

        return match ($provider->provider_type) {
            'table' => $this->tableOptions($provider, $search, $filters, $limit),
            'http' => $this->httpOptions($provider, $search, $filters, $limit),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array{id: mixed, code: string, label: string}>
     */
    public function tableOptions(CoreScopeEntityProvider $provider, ?string $search = null, array $filters = [], int $limit = 50): array
    {
        $table = (string) $provider->table_name;
        $idColumn = (string) ($provider->id_column ?: 'id');
        $codeColumn = (string) ($provider->code_column ?: $idColumn);
        $labelColumns = $this->columnList($provider->label_columns);

        if (! $this->isSafeTableName($table) || ! Schema::hasTable($table)) {
            return [];
        }

        if (! $this->columnsExist($table, [$idColumn, $codeColumn, ...$labelColumns])) {
            return [];
        }

        $query = DB::table($table)->select($this->selectColumns([$idColumn, $codeColumn, ...$labelColumns]));

        if ($this->metadataFlag($provider, 'distinct')) {
            $query->distinct();
        }

        if (! $this->applyStatusColumn($query, $table, $provider)) {
            return [];
        }

        $this->applyFilters($query, $table, $provider, $filters);
        $this->applySearch($query, $table, $provider, $search, $codeColumn, $labelColumns);
        $this->applyOrder($query, $table, $provider, $codeColumn, $labelColumns, $idColumn);

        return $query
            ->limit($this->normalizeLimit($limit))
            ->get()
            ->map(fn (object $row): array => $this->optionFromRow($row, $idColumn, $codeColumn, $labelColumns))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array{id: mixed, code: string, label: string}>
     */
    public function httpOptions(CoreScopeEntityProvider $provider, ?string $search = null, array $filters = [], int $limit = 50): array
    {
        if (! $this->container->bound(ScopeEntityHttpOptionProvider::class)) {
            return [];
        }

        return $this->container
            ->make(ScopeEntityHttpOptionProvider::class)
            ->options($provider, $search, $filters, $this->normalizeLimit($limit));
    }

    /**
     * @param  list<string>  $columns
     */
    private function columnsExist(string $table, array $columns): bool
    {
        foreach (array_filter(array_unique($columns)) as $column) {
            if (! $this->isSafeColumnName($column) || ! Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function selectColumns(array $columns): array
    {
        return array_values(array_unique(array_filter($columns)));
    }

    private function applyStatusColumn(Builder $query, string $table, CoreScopeEntityProvider $provider): bool
    {
        if (! $provider->status_column) {
            return true;
        }

        $statusColumn = (string) $provider->status_column;

        if (! $this->isSafeColumnName($statusColumn) || ! Schema::hasColumn($table, $statusColumn)) {
            return false;
        }

        $query->where($statusColumn, true);

        return true;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, string $table, CoreScopeEntityProvider $provider, array $filters): void
    {
        $allowed = $this->allowedFilterColumns($provider);

        foreach ($filters as $key => $value) {
            $column = $allowed[$key] ?? null;

            if (! $column || ! $this->isSafeColumnName($column) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            if ($value === '' || $value === []) {
                continue;
            }

            if ($value === null) {
                $query->whereNull($column);

                continue;
            }

            if (is_array($value)) {
                $values = array_values(array_filter($value, fn ($item): bool => $item !== null && $item !== ''));

                if ($values !== []) {
                    $query->whereIn($column, $values);
                }

                continue;
            }

            $query->where($column, $value);
        }
    }

    /**
     * @param  list<string>  $labelColumns
     */
    private function applySearch(
        Builder $query,
        string $table,
        CoreScopeEntityProvider $provider,
        ?string $search,
        string $codeColumn,
        array $labelColumns,
    ): void {
        $term = trim((string) $search);

        if ($term === '') {
            return;
        }

        $columns = collect($this->columnList($provider->search_columns))
            ->whenEmpty(fn (Collection $columns) => $columns->merge([$codeColumn, ...$labelColumns]))
            ->filter(fn (string $column): bool => $this->isSafeColumnName($column) && Schema::hasColumn($table, $column))
            ->unique()
            ->values();

        if ($columns->isEmpty()) {
            return;
        }

        $query->where(function (Builder $query) use ($columns, $term): void {
            $columns->each(fn (string $column) => $query->orWhere($column, 'like', "%{$term}%"));
        });
    }

    /**
     * @param  list<string>  $labelColumns
     */
    private function applyOrder(Builder $query, string $table, CoreScopeEntityProvider $provider, string $codeColumn, array $labelColumns, string $idColumn): void
    {
        $metadata = $this->metadata($provider);
        $orderColumn = (string) data_get($metadata, 'order_column', data_get($metadata, 'sort_column', $labelColumns[0] ?? $codeColumn ?: $idColumn));

        if ($this->isSafeColumnName($orderColumn) && Schema::hasColumn($table, $orderColumn)) {
            $query->orderBy($orderColumn);
        }
    }

    /**
     * @param  list<string>  $labelColumns
     * @return array{id: mixed, code: string, label: string}
     */
    private function optionFromRow(object $row, string $idColumn, string $codeColumn, array $labelColumns): array
    {
        $id = data_get($row, $idColumn);
        $code = (string) (data_get($row, $codeColumn) ?? $id ?? '');
        $labelParts = collect($labelColumns)
            ->map(fn (string $column): mixed => data_get($row, $column))
            ->filter(fn (mixed $value): bool => $value !== null && $value !== '')
            ->map(fn (mixed $value): string => (string) $value)
            ->values();

        if ($code !== '' && ! $labelParts->contains($code)) {
            $labelParts->prepend($code);
        }

        return [
            'id' => $id,
            'code' => $code,
            'label' => $labelParts->implode(' - ') ?: $code ?: (string) $id,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function allowedFilterColumns(CoreScopeEntityProvider $provider): array
    {
        $configured = data_get($this->metadata($provider), 'filter_columns', data_get($this->metadata($provider), 'filters', []));
        $allowed = [];

        foreach ((array) $configured as $key => $value) {
            if (is_int($key) && is_string($value)) {
                $allowed[$value] = $value;

                continue;
            }

            if (is_string($key) && is_string($value)) {
                $allowed[$key] = $value;

                continue;
            }

            if (is_string($key) && is_array($value)) {
                $column = $value['column'] ?? null;

                if (is_string($column)) {
                    $allowed[$key] = $column;
                }
            }
        }

        return array_filter($allowed, fn (string $column): bool => $this->isSafeColumnName($column));
    }

    /**
     * @return list<string>
     */
    private function columnList(mixed $columns): array
    {
        if (is_string($columns)) {
            $columns = array_map('trim', explode(',', $columns));
        }

        if (! is_array($columns)) {
            return [];
        }

        return collect($columns)
            ->filter(fn (mixed $column): bool => is_string($column) && $column !== '')
            ->values()
            ->all();
    }

    private function metadataFlag(CoreScopeEntityProvider $provider, string $key): bool
    {
        return filter_var(data_get($this->metadata($provider), $key, false), FILTER_VALIDATE_BOOL);
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(CoreScopeEntityProvider $provider): array
    {
        return is_array($provider->metadata) ? $provider->metadata : [];
    }

    private function normalizeLimit(int $limit): int
    {
        return max(1, min($limit, 500));
    }

    private function isSafeTableName(string $table): bool
    {
        return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $table);
    }

    private function isSafeColumnName(string $column): bool
    {
        return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column);
    }
}
