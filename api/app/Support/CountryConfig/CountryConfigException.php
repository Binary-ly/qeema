<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support\CountryConfig;

use RuntimeException;

/**
 * A country configuration file is invalid.
 *
 * Carries every problem found rather than only the first. Someone adding a
 * country should get one list they can work through, not a dozen round trips
 * where each run reveals the next mistake.
 *
 * Note the property is `configPath`, not `file`: `Exception` already has an
 * engine-managed `$file` property, and shadowing it with a promoted readonly
 * property segfaults PHP rather than raising a normal error.
 */
final class CountryConfigException extends RuntimeException
{
    /**
     * @param  list<string>  $problems
     */
    public function __construct(
        public readonly string $configPath,
        public readonly array $problems,
    ) {
        parent::__construct(sprintf(
            "Invalid country configuration in %s:\n  - %s",
            $configPath,
            implode("\n  - ", $problems),
        ));
    }
}
