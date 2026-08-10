<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response headers that constrain what a page is allowed to do.
 *
 * The Content-Security-Policy is the substantive one, and it can afford to be
 * strict precisely because the platform loads nothing from anywhere else
 * (constraint C1). `'self'` costs nothing here while ruling out an entire class
 * of injection.
 *
 * Two deliberate choices:
 *
 * - **No `'unsafe-inline'` for scripts.** The dashboard's only inline handler
 *   was removed to make this possible — keeping it would have meant allowing
 *   inline script everywhere, which is most of what a CSP is for.
 *   `<script type="application/json">` blocks are data, not code, and are
 *   unaffected by `script-src`.
 * - **`'unsafe-inline'` is permitted for styles.** The SVG map sets a per-point
 *   `--intensity` custom property inline. Hashing those would mean recomputing
 *   the policy on every request for no real gain: a style attribute cannot
 *   exfiltrate anything while `connect-src` is `'self'`.
 */
final class SecurityHeaders
{
    /**
     * Routes whose scripts need `eval`.
     *
     * Alpine compiles its `x-` expressions with `new Function()`, which a
     * strict `script-src` forbids outright. The reporter is built on Alpine, so
     * a policy without `'unsafe-eval'` does not merely degrade it — the app
     * does not start at all.
     *
     * The relaxation is scoped to those routes rather than applied globally.
     * The public dashboard, the API, the docs and the CSV export — everything a
     * data consumer or a passer-by touches — keep the strict policy. Alpine
     * ships a CSP-safe build that would remove the exception entirely, at the
     * cost of rewriting every inline expression as a component method; that is
     * recorded as follow-up work rather than done hastily here.
     *
     * @var list<string>
     */
    private const EVAL_ROUTES = ['report', 'offline', 'admin', 'admin/*', 'horizon', 'horizon/*'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $scriptSrc = $request->is(...self::EVAL_ROUTES)
            ? "script-src 'self' 'unsafe-eval'"
            : "script-src 'self'";

        $policy = implode('; ', [
            "default-src 'self'",
            $scriptSrc,
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'",
            // No third party is ever contacted, so none is allowed to be.
            "connect-src 'self'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]);

        $headers = [
            'Content-Security-Policy' => $policy,
            // The platform needs none of these capabilities; saying so
            // explicitly shrinks what a compromised script could reach for.
            'Permissions-Policy' => 'geolocation=(), camera=(), microphone=(), payment=(), usb=()',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ];

        foreach ($headers as $name => $value) {
            // Never overwrite a header the server in front has already set: an
            // operator's own policy should win over this default.
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
