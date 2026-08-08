<?php

namespace App\Http\Middleware;

use App\Support\RequestLocaleResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveRequestLocale
{
    public function __construct(
        private readonly RequestLocaleResolver $resolver,
    ) {}

    /**
     * Resolve and apply the request locale for citizen-facing API routes.
     *
     * Sets Laravel's locale for the request duration, stamps Content-Language,
     * merges Vary: Accept-Language, then restores the configured default locale
     * so long-lived workers / Octane cannot leak locale between requests.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $defaultLocale = $this->resolver->defaultLocale();
        $locale = $this->resolver->resolve($request);

        app()->setLocale($locale);

        try {
            /** @var Response $response */
            $response = $next($request);
        } finally {
            app()->setLocale($defaultLocale);
        }

        $response->headers->set('Content-Language', $locale);
        $this->mergeVaryAcceptLanguage($response);

        return $response;
    }

    private function mergeVaryAcceptLanguage(Response $response): void
    {
        $parts = [];

        foreach ($response->headers->all('Vary') as $line) {
            foreach (explode(',', (string) $line) as $part) {
                $trimmed = trim($part);
                if ($trimmed !== '') {
                    $parts[] = $trimmed;
                }
            }
        }

        if (! in_array('Accept-Language', $parts, true)) {
            $parts[] = 'Accept-Language';
        }

        $response->headers->set('Vary', implode(', ', array_values(array_unique($parts))));
    }
}
