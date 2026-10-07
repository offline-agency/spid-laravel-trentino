<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Support;

/**
 * Outcome of a transaction log verification: on failure, the id of the first
 * row that did not verify and why.
 */
final readonly class VerificationResult
{
    public function __construct(
        public bool $ok,
        public int $checked,
        public int $legacy,
        public ?int $brokenAt = null,
        public ?string $reason = null,
    ) {}
}
