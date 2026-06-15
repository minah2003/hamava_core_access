<?php

namespace Hamava\CoreAccess\Middleware;

use Closure;
use Hamava\CoreAccess\Services\CoreAccessResolver;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CoreCan
{
    public function __construct(private readonly CoreAccessResolver $access) {}

    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $capabilities = str($capability)
            ->replace(',', '|')
            ->explode('|')
            ->map(fn (string $item): string => trim($item))
            ->filter()
            ->values();

        abort_unless($capabilities->contains(fn (string $item): bool => $this->access->can($request->user(), $item)), 403);

        return $next($request);
    }
}
