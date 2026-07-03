<?php

namespace App\Sync\Contracts;

use App\Enums\ProviderType;
use App\Sync\Data\ProductData;

interface ProductProvider
{
    public function type(): ProviderType;

    /**
     * Stream all products as normalized DTOs. Implementations page lazily so
     * the full catalogue is never held in memory at once (SPEC §6.4).
     *
     * @return iterable<ProductData>
     */
    public function fetchAll(): iterable;

    public function fetchOne(string $externalId): ?ProductData;

    /**
     * Number of HTTP requests issued so far — surfaced as a sync metric.
     */
    public function requestCount(): int;

    /**
     * Raw records skipped during mapping because they failed provider-level
     * validity checks (missing id/name, non-numeric price). Rolled into the
     * run's products_failed counter.
     */
    public function skippedCount(): int;
}
