<?php

namespace Hamava\CoreAccess\Data;

use Illuminate\Contracts\Support\Arrayable;

class AccessDecision implements Arrayable
{
    /**
     * @param  array<string, list<int|string>>  $matched
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly string $reason,
        public readonly array $matched = [
            'team_ids' => [],
            'membership_ids' => [],
            'role_ids' => [],
            'team_role_ids' => [],
            'member_role_assignment_ids' => [],
            'scope_ids' => [],
            'resource_grant_ids' => [],
        ],
    ) {}

    /**
     * @param  array<string, list<int|string>>  $matched
     */
    public static function allow(string $reason, array $matched = []): self
    {
        return new self(true, $reason, self::normalizeMatched($matched));
    }

    /**
     * @param  array<string, list<int|string>>  $matched
     */
    public static function deny(string $reason, array $matched = []): self
    {
        return new self(false, $reason, self::normalizeMatched($matched));
    }

    /**
     * @param  array<string, list<int|string>>  $matched
     * @return array<string, list<int|string>>
     */
    private static function normalizeMatched(array $matched): array
    {
        $defaults = [
            'team_ids' => [],
            'membership_ids' => [],
            'role_ids' => [],
            'team_role_ids' => [],
            'member_role_assignment_ids' => [],
            'scope_ids' => [],
            'resource_grant_ids' => [],
        ];

        foreach ($matched as $key => $values) {
            $defaults[$key] = array_values(array_unique(array_filter((array) $values, fn ($value) => $value !== null)));
        }

        return $defaults;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'reason' => $this->reason,
            'matched' => $this->matched,
        ];
    }
}
