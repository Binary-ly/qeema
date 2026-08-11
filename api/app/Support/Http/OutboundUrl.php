<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support\Http;

use InvalidArgumentException;

/**
 * A URL the platform is willing to fetch on an operator's instruction.
 *
 * Two places take a URL from configuration rather than from code: the exchange
 * rate provider and the open-data scraper. Both are operator-supplied, and
 * `SECURITY.md` names server-side request forgery through exactly those two as
 * in scope — a deployment reachable from the internet, holding configuration
 * somebody else may be able to edit, will happily fetch `http://169.254.169.254`
 * and hand back a cloud instance's credentials if nothing stops it.
 *
 * So the rule is: public internet only. Resolve the host first, refuse every
 * address that belongs to the machine, the network it sits on, or the metadata
 * services that live at link-local addresses.
 *
 * **What this does not solve.** A host that resolves to a public address here
 * and a private one at connection time — DNS rebinding — would defeat it, since
 * the check and the connection are separate resolutions. Closing that properly
 * means pinning the resolved address into the connection, which the HTTP client
 * does not expose. Given the input is operator configuration rather than
 * anonymous user input, the residual risk is stated rather than pretended away.
 */
final class OutboundUrl
{
    /** @var list<string> */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * Validate a URL, or explain why it is refused.
     *
     * @throws InvalidArgumentException
     */
    public static function guard(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'], $parts['scheme'])) {
            throw new InvalidArgumentException("Not a usable URL: {$url}");
        }

        $scheme = strtolower((string) $parts['scheme']);

        if (! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw new InvalidArgumentException(
                "Refusing scheme '{$scheme}': only http and https are fetched."
            );
        }

        // Credentials in a URL end up in logs, in error messages, and in the
        // country configuration file itself. If a source needs authentication
        // it needs a considered design, not a userinfo component.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Refusing a URL that carries credentials.');
        }

        foreach (self::resolve((string) $parts['host']) as $address) {
            if (! self::isPublic($address)) {
                throw new InvalidArgumentException(sprintf(
                    'Refusing %s: it resolves to %s, which is not a public address.',
                    $parts['host'],
                    $address,
                ));
            }
        }

        return $url;
    }

    public static function isAllowed(string $url): bool
    {
        try {
            self::guard($url);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Every address a host resolves to.
     *
     * All of them, not the first: a host that answers with one public address
     * and one loopback address is precisely the case worth refusing.
     *
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = gethostbynamel($host);

        // A host that does not resolve is not a refusal. This check exists to
        // stop a URL pointing *inside* the network, and a name with no address
        // points nowhere: the connection will fail on its own, with a message
        // about the host rather than a security refusal that sends whoever
        // typed it looking for the wrong problem.
        //
        // It also keeps the guard honest about what it is: a policy on
        // addresses, not a liveness check on DNS. Coupling the two would mean a
        // transient resolver failure looked identical to an attack.
        if ($addresses === false) {
            return [];
        }

        /** @var list<string> $addresses */
        return $addresses;
    }

    /**
     * Public routable address?
     *
     * `NO_PRIV_RANGE` covers RFC 1918 and unique-local v6; `NO_RES_RANGE`
     * covers loopback, link-local — including the 169.254.169.254 metadata
     * address every cloud provider serves — and the other reserved blocks.
     */
    private static function isPublic(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
