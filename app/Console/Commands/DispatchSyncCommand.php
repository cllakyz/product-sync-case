<?php

namespace App\Console\Commands;

use App\Enums\ProviderType;
use App\Enums\TriggerType;
use App\Sync\Providers\ProviderRegistry;
use App\Sync\Services\SyncDispatcher;
use Illuminate\Console\Command;

/**
 * Queues a sync for every enabled provider (or a single one). Bound to the
 * scheduler in routes/console.php; also runnable on demand.
 */
class DispatchSyncCommand extends Command
{
    protected $signature = 'sync:dispatch {--provider= : Limit to a single provider (dummyjson|fakestore)}';

    protected $description = 'Dispatch a sync job for each enabled provider';

    public function handle(ProviderRegistry $registry, SyncDispatcher $dispatcher): int
    {
        $providers = $this->resolveProviders($registry);

        if ($providers === []) {
            $this->warn('No enabled providers to sync.');

            return self::SUCCESS;
        }

        foreach ($providers as $provider) {
            $log = $dispatcher->dispatch($provider, TriggerType::Scheduled);

            if ($log === null) {
                $this->warn("Sync already active for [{$provider->value}] — skipped.");

                continue;
            }

            $this->info("Queued sync for [{$provider->value}] (sync_log #{$log->id}).");
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, ProviderType>
     */
    private function resolveProviders(ProviderRegistry $registry): array
    {
        $option = $this->option('provider');

        if ($option === null) {
            return $registry->enabled();
        }

        $type = ProviderType::tryFrom($option);

        if ($type === null || ! $registry->isEnabled($type)) {
            $this->error("Unknown or disabled provider: [{$option}].");

            return [];
        }

        return [$type];
    }
}
