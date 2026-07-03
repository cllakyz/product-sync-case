<?php

namespace App\Sync\Services;

use App\Enums\AlertType;
use App\Enums\DeadLetterStatus;
use App\Enums\ProviderType;
use App\Models\DeadLetterJob;
use App\Models\SyncAlert;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;

/**
 * Central alert evaluation (SPEC §13). Emits structured (JSON-friendly) log
 * entries and an auditable sync_alerts row, debounced so the same
 * (type, provider) does not notify more than once per configured window.
 * The webhook notification channel is deferred; the hook is left in place.
 */
class AlertManager
{
    public function __construct(private readonly Cache $cache) {}

    /**
     * Record a failed sync and alert when the consecutive-failure streak for a
     * provider reaches the threshold.
     */
    public function syncFailed(ProviderType $provider): void
    {
        $streak = (int) $this->cache->get($this->streakKey($provider), 0) + 1;
        $this->cache->put($this->streakKey($provider), $streak, now()->addDay());

        if ($streak >= (int) config('sync.alerts.sync_failure_streak', 3)) {
            $this->trigger(AlertType::SyncFailure, $provider, ['consecutive_failures' => $streak]);
        }
    }

    /**
     * Reset the failure streak after a clean run.
     */
    public function syncSucceeded(ProviderType $provider): void
    {
        $this->cache->forget($this->streakKey($provider));
    }

    /**
     * Alert that a provider's API is failing consistently (breaker tripped).
     */
    public function apiFailure(ProviderType $provider, int $consecutiveFailures): void
    {
        $this->trigger(AlertType::ApiFailure, $provider, ['consecutive_failures' => $consecutiveFailures]);
    }

    /**
     * Alert when the pending dead-letter queue exceeds its threshold.
     */
    public function evaluateDlqDepth(): void
    {
        $count = DeadLetterJob::query()->where('status', DeadLetterStatus::Pending)->count();

        if ($count > (int) config('sync.alerts.dlq_threshold', 10)) {
            $this->trigger(AlertType::DlqThreshold, null, ['pending' => $count]);
        }
    }

    /**
     * Alert when queued jobs pile up beyond the backlog threshold.
     */
    public function evaluateQueueBacklog(int $backlog): void
    {
        if ($backlog > (int) config('sync.alerts.queue_backlog_threshold', 100)) {
            $this->trigger(AlertType::QueueBacklog, null, ['backlog' => $backlog]);
        }
    }

    /**
     * Emit an alert (structured log + audit row) unless debounced.
     *
     * @param  array<string, mixed>  $details
     */
    private function trigger(AlertType $type, ?ProviderType $provider, array $details): void
    {
        $debounceKey = $this->debounceKey($type, $provider);

        if ($this->cache->has($debounceKey)) {
            return;
        }

        $this->cache->put($debounceKey, true, now()->addMinutes((int) config('sync.alerts.debounce_minutes', 5)));

        $severity = $type->severity();

        Log::log($severity === 'critical' ? 'critical' : 'warning', 'sync.alert', [
            'level' => 'ALERT',
            'type' => $type->value,
            'severity' => $severity,
            'provider' => $provider?->value,
            'timestamp' => now()->toIso8601String(),
            'details' => $details,
        ]);

        SyncAlert::create([
            'alert_type' => $type,
            'severity' => $severity,
            'provider_type' => $provider,
            'details' => $details,
            'notified_at' => now(),
        ]);

        $this->notifyWebhook($type, $provider, $severity, $details);
    }

    /**
     * Deferred notification channel (Slack/Discord/webhook). Left as a no-op
     * unless a webhook URL is configured; safe to extend without touching callers.
     *
     * @param  array<string, mixed>  $details
     */
    private function notifyWebhook(AlertType $type, ?ProviderType $provider, string $severity, array $details): void
    {
        // Intentionally deferred (SPEC "önce core"): the structured logs above are
        // the shipped alert channel. A real Slack/Discord POST would go here, and
        // must swallow its own failures so alerting never breaks the sync path.
    }

    private function streakKey(ProviderType $provider): string
    {
        return "sync:alert:streak:{$provider->value}";
    }

    private function debounceKey(AlertType $type, ?ProviderType $provider): string
    {
        return "sync:alert:debounce:{$type->value}:".($provider !== null ? $provider->value : 'global');
    }
}
