<?php

namespace Tests\Feature\Sync;

use App\Enums\ProviderType;
use App\Enums\SyncStatus;
use App\Enums\TriggerType;
use App\Models\Product;
use App\Models\SyncLog;
use App\Sync\Exceptions\ProviderRequestException;
use App\Sync\Services\SyncOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithSync;
use Tests\TestCase;

class SyncOrchestratorTest extends TestCase
{
    use InteractsWithSync;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeSleeper();
    }

    private function runSync(ProviderType $provider = ProviderType::DummyJson): SyncLog
    {
        $log = SyncLog::create([
            'provider_type' => $provider,
            'trigger_type' => TriggerType::Manual,
            'status' => SyncStatus::Pending,
        ]);

        return app(SyncOrchestrator::class)->run($log);
    }

    public function test_a_successful_run_adds_products_and_records_stats()
    {
        $this->fakeDummyJson([
            $this->dummyJsonProduct(1, 'Alpha'),
            $this->dummyJsonProduct(2, 'Beta'),
        ]);

        $log = $this->runSync();

        $this->assertSame(SyncStatus::Completed, $log->status);
        $this->assertSame(2, $log->products_added);
        $this->assertSame(2, Product::count());
        $this->assertGreaterThan(0, $log->api_requests);
    }

    public function test_running_twice_is_idempotent_with_no_duplicates()
    {
        $this->fakeDummyJson([
            $this->dummyJsonProduct(1, 'Alpha'),
            $this->dummyJsonProduct(2, 'Beta'),
        ]);

        $this->runSync();
        $log = $this->runSync();

        $this->assertSame(SyncStatus::Completed, $log->status);
        $this->assertSame(0, $log->products_added);
        $this->assertSame(2, $log->products_unchanged);
        $this->assertSame(2, Product::count());
    }

    public function test_products_absent_from_the_provider_are_soft_deleted()
    {
        // First run returns 3 products, second returns a subset (1/3 removed —
        // within the delete guard). A sequence gives each run distinct data.
        Http::fakeSequence('dummyjson.com/*')
            ->push($this->dummyJsonEnvelope([
                $this->dummyJsonProduct(1, 'Alpha'),
                $this->dummyJsonProduct(2, 'Beta'),
                $this->dummyJsonProduct(3, 'Gamma'),
            ]))
            ->push($this->dummyJsonEnvelope([
                $this->dummyJsonProduct(1, 'Alpha'),
                $this->dummyJsonProduct(2, 'Beta'),
            ]));

        $this->runSync();
        $log = $this->runSync();

        $this->assertSame(1, $log->products_deleted);
        $this->assertSoftDeleted('products', ['external_id' => '3']);
    }

    public function test_circuit_breaker_ends_the_run_as_partial()
    {
        config(['sync.circuit_breaker.failure_threshold' => 2]);
        Http::fake(['dummyjson.com/*' => Http::response('', 500)]);

        $log = $this->runSync();

        $this->assertSame(SyncStatus::Partial, $log->status);
        $this->assertNotNull($log->error_message);
    }

    public function test_persistent_failure_marks_the_run_failed_and_rethrows()
    {
        config(['sync.circuit_breaker.failure_threshold' => 99]); // don't trip the breaker
        Http::fake(['dummyjson.com/*' => Http::response('', 500)]);

        try {
            $this->runSync();
            $this->fail('Expected ProviderRequestException.');
        } catch (ProviderRequestException) {
            // expected
        }

        $log = SyncLog::latest('id')->first();
        $this->assertSame(SyncStatus::Failed, $log->status);
    }
}
