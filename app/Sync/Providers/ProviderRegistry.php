<?php

namespace App\Sync\Providers;

use App\Enums\ProviderType;
use App\Sync\Contracts\ProductProvider;
use App\Sync\Http\SyncHttpClient;
use InvalidArgumentException;

/**
 * Resolves ProductProvider instances from config/sync.php. Adding a provider is
 * a matter of implementing ProductProvider and registering it in config — no
 * change here (Open/Closed).
 */
class ProviderRegistry
{
    public function __construct(private readonly SyncHttpClient $client) {}

    /**
     * Build the adapter for a provider type.
     */
    public function make(ProviderType $type): ProductProvider
    {
        $config = config("sync.providers.{$type->value}");

        if (! is_array($config) || ! isset($config['class'])) {
            throw new InvalidArgumentException("No provider configured for [{$type->value}].");
        }

        /** @var class-string<AbstractHttpProvider> $class */
        $class = $config['class'];

        return new $class($this->client, $config);
    }

    /**
     * Whether a provider is present and enabled in configuration.
     */
    public function isEnabled(ProviderType $type): bool
    {
        return (bool) config("sync.providers.{$type->value}.enabled", false);
    }

    /**
     * All enabled provider types.
     *
     * @return array<int, ProviderType>
     */
    public function enabled(): array
    {
        return array_values(array_filter(
            ProviderType::cases(),
            fn (ProviderType $type): bool => $this->isEnabled($type),
        ));
    }
}
