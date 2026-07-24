<?php

namespace Hamava\CoreAccess\Data;

use Hamava\CoreAccess\Contracts\DescribesCoreResource;
use Illuminate\Contracts\Support\Arrayable;

class ResourceDescriptor implements Arrayable
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public readonly string $module_code,
        public readonly string $resource_type,
        public readonly int|string|null $resource_id = null,
        public readonly ?string $resource_code = null,
        public readonly array $attributes = [],
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function make(
        string $module_code,
        string $resource_type,
        int|string|null $resource_id = null,
        ?string $resource_code = null,
        array $attributes = [],
    ): self {
        return new self($module_code, $resource_type, $resource_id, $resource_code, $attributes);
    }

    /**
     * @param  array<string, mixed>|DescribesCoreResource|self|null  $value
     */
    public static function from(
        array|self|DescribesCoreResource|null $value,
        ?string $moduleCode = null,
    ): ?self {
        if ($value instanceof self) {
            return $value;
        }

        if ($value instanceof DescribesCoreResource) {
            return $value->toCoreResourceDescriptor();
        }

        if ($value === null) {
            return null;
        }

        $resource = $value['resource'] ?? $value;

        return new self(
            module_code: (string) (
                $resource['module_code']
                ?? $resource['module']
                ?? $moduleCode
                ?? ''
            ),
            resource_type: (string) (
                $resource['resource_type']
                ?? $resource['type']
                ?? 'resource'
            ),
            resource_id: $resource['resource_id']
                ?? $resource['id']
                ?? null,
            resource_code: $resource['resource_code']
                ?? $resource['code']
                ?? null,
            attributes: (array) (
                $resource['attributes']
                ?? []
            ),
        );
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return data_get($this->attributes, $key, $default);
    }

    /**
     * @return list<int|string>
     */
    public function idsFor(string $scopeType, bool $includeAncestors = true): array
    {
        return $this->valuesFor($scopeType, 'id', $includeAncestors);
    }

    /**
     * @return list<string>
     */
    public function codesFor(string $scopeType, bool $includeAncestors = true): array
    {
        return array_map('strval', $this->valuesFor($scopeType, 'code', $includeAncestors));
    }

    /**
     * @return list<int|string>
     */
    private function valuesFor(string $scopeType, string $kind, bool $includeAncestors): array
    {
        $keys = [
            "{$scopeType}_{$kind}",
            "{$scopeType}_{$kind}s",
        ];

        if ($includeAncestors) {
            $keys[] = "{$scopeType}_ancestor_{$kind}s";
            $keys[] = "{$scopeType}_ancestors_{$kind}s";
        }

        if ($scopeType === $this->resource_type) {
            $keys[] = $kind === 'id' ? 'resource_id' : 'resource_code';
        }

        if ($scopeType === 'asset' && $this->resource_type === 'asset') {
            $keys[] = $kind === 'id' ? 'id' : 'code';
        }

        $values = [];

        foreach ($keys as $key) {
            $value = match ($key) {
                'resource_id', 'id' => $this->resource_id,
                'resource_code', 'code' => $this->resource_code,
                default => $this->attribute($key),
            };

            if ($value === null || $value === '') {
                continue;
            }

            foreach ((array) $value as $item) {
                if ($item !== null && $item !== '') {
                    $values[] = $item;
                }
            }
        }

        return array_values(array_unique($values, SORT_REGULAR));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'module_code' => $this->module_code,
            'resource_type' => $this->resource_type,
            'resource_id' => $this->resource_id,
            'resource_code' => $this->resource_code,
            'attributes' => $this->attributes,
        ];
    }
}
