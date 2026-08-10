<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

it('sends a content security policy that forbids inline script', function (): void {
    $policy = $this->get('/')->headers->get('Content-Security-Policy');

    expect($policy)->toContain("script-src 'self'")
        // The whole value of the policy. If an inline handler ever comes back
        // and someone "fixes" the resulting breakage by allowing inline script,
        // this fails rather than silently gutting the policy.
        ->and($policy)->not->toContain("script-src 'self' 'unsafe-inline'")
        ->and($policy)->toContain("object-src 'none'")
        ->and($policy)->toContain("connect-src 'self'");
});

it('keeps the public surface free of unsafe-eval', function (): void {
    foreach (['/', '/docs', '/api/v1/health'] as $path) {
        $policy = $this->get($path)->headers->get('Content-Security-Policy');

        expect($policy)->not->toContain('unsafe-eval', "{$path} should not permit eval");
    }
});

it('permits eval only where Alpine needs it', function (): void {
    // Alpine compiles x- expressions with new Function(). Without this the
    // reporter does not start at all — which is how it was discovered, when
    // every one of its end-to-end tests failed at once.
    expect($this->get('/report')->headers->get('Content-Security-Policy'))
        ->toContain("script-src 'self' 'unsafe-eval'");
});

it('sends the policy on API responses too', function (): void {
    expect($this->getJson('/api/v1/health')->headers->get('Content-Security-Policy'))->not->toBeNull();
});

it('disclaims capabilities the platform does not use', function (): void {
    expect($this->get('/')->headers->get('Permissions-Policy'))
        ->toContain('geolocation=()')
        ->toContain('camera=()')
        ->toContain('microphone=()');
});

it('sets the standard hardening headers', function (): void {
    $response = $this->get('/');

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
});

it('serves no inline event handlers the policy would block', function (): void {
    $html = $this->get('/')->getContent();

    // A regression here breaks the page under the policy above — the kind of
    // failure that only shows up in a browser, and only once deployed.
    expect($html)->not->toMatch('/<[^>]+\son(click|change|load|submit|input)\s*=/i');
});

it('does not leak the framework or its version', function (): void {
    $headers = $this->get('/')->headers;

    // Absent is the right answer for both. A version string tells an attacker
    // which advisories to try first.
    expect($headers->get('X-Powered-By'))->toBeNull()
        ->and($headers->get('Server') ?? '')->not->toContain('PHP');
});
