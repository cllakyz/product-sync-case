# PLAN — Veri Senkronizasyon Sistemi (SPEC.md uygulaması)

## Context

`SPEC.md` bu sistemin tam teknik tasarımını içeriyor; bu plan onu mevcut Laravel 13 + Inertia/React
starter kit üzerinde **çalışan koda** dönüştürmenin sıralı yol haritasıdır. Keşif (Explore) sonucu:
projede API iskelesi yok (`routes/api.php` yok, `bootstrap/app.php`'de `api:` kayıtlı değil, Sanctum
kurulu değil), auth Fortify session tabanlı, queue/cache default `database`, DB SQLite. Bu plan bu
boşlukları kapatır ve SPEC'i uygular.

### Kilitlenen kararlar (kullanıcı onayı)
- **Altyapı: Sadece Docker** → runtime docker-compose ile: `app` (php-fpm+phpredis), `nginx`,
  `mysql:8`, `redis`, `queue-worker`, `scheduler`, `vite/node`. DB=MySQL 8, queue/cache/lock=Redis.
- **API auth: Sanctum (cookie + token)** → dashboard SPA cookie auth ile, Postman/dış istemci bearer
  token ile. `php artisan install:api` + `HasApiTokens`.
- **Kapsam: Önce core** → engine + 7 endpoint + minimal dashboard + testler + README + Docker.
  **Ertelenen (faz 2):** webhook notification (Slack/Discord) ve Postman collection. Structured-log
  alerting core'da kalır.

### Kod yerleşimi / isimlendirme notu
- Senkronizasyon domain kodu `app/Providers` (Laravel service provider dizini) ile karışmasın diye
  **`app/Sync/`** altında toplanır: `app/Sync/Contracts`, `app/Sync/Providers`, `app/Sync/Services`.
- Mevcut konvansiyonlara uyulur: FormRequest'ler `app/Http/Requests/`, paylaşılan validasyon
  kuralları `app/Concerns/` (starter kit'teki `ProfileValidationRules` deseni), enum-attribute'lü
  model stili (`User.php`'deki `#[Fillable]` deseni), PHPUnit class-based test + opt-in
  `RefreshDatabase`.

---

## Faz 0 — Altyapı ve iskele

1. **API scaffolding:** `php artisan install:api` → `routes/api.php`, `bootstrap/app.php`'ye `api:`,
   Sanctum + `personal_access_tokens` migration. `app/Models/User.php`'ye `HasApiTokens` trait +
   `#[Fillable]` deseni korunur.
2. **Sanctum SPA:** `config/sanctum.php` stateful domains, `SANCTUM_STATEFUL_DOMAINS`; `config/cors.php`
   `supports_credentials=true`; api grubuna `EnsureFrontendRequestsAreStateful`.
3. **Docker:** `Dockerfile` (php 8.5-fpm + `pdo_mysql`, `redis` (phpredis) extension, composer),
   `docker-compose.yml` (app, nginx, mysql, redis, queue-worker `queue:work redis`, scheduler
   `schedule:work`, node/vite), `docker/nginx/default.conf`, `.dockerignore`, entrypoint (migrate +
   serve). `composer.json`'a `laravel/sanctum` (install:api ekler); Redis için phpredis extension
   image'de kurulur (predis gereksiz).
4. **Config & env:** `config/sync.php` (providers, interval, `max_rps`, tries/backoff, breaker limit,
   `delete_guard_ratio`, `missing_stock_policy`, alert eşikleri, throttle) — SPEC §15. `.env.example`
   güncellenir: `DB_CONNECTION=mysql`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`,
   `REDIS_CLIENT=phpredis`, tüm `SYNC_*`/`ALERT_*`/`PROVIDER_*` anahtarları.

## Faz 1 — Domain & veri katmanı (SPEC §4)

- **Migrations** (`database/migrations/`): `products`, `sync_logs`, `dead_letter_jobs`, `sync_alerts`.
  Tip/kısıt/index SPEC §4'e birebir: `UNIQUE(provider_type, external_id)`, CHECK (`price>=0`,
  `stock>=0`), gerekli index'ler, soft delete (`deleted_at`), JSON kolonlar.
- **Enums** (`app/Enums/`): `ProviderType`, `SyncStatus`, `TriggerType`, `DeadLetterStatus`, `AlertType`.
- **Models** (`app/Models/`): `Product` (SoftDeletes, `#[Fillable]` whitelist, casts), `SyncLog`,
  `DeadLetterJob`, `SyncAlert` + **factories** (SPEC test gereği).
- **DTO** (`app/Sync/Data/ProductData.php`): `readonly` class, adapter invariant'leri (SPEC §3.2).

## Faz 2 — Provider soyutlaması (SPEC §3)

- **Contract** `app/Sync/Contracts/ProductProvider.php` (`type()`, `fetchAll(): iterable`, `fetchOne()`).
- **Adapters** `app/Sync/Providers/DummyJsonProvider.php`, `FakeStoreProvider.php`: Laravel `Http`
  client (timeout, TLS verify), zarf/dizi farkını + alan mapping'i + `stock` policy'yi normalize eder,
  `ProductData` üretir. FakeStore `stock` yok → `missing_stock_policy`.
- **Registry** `app/Sync/Providers/ProviderRegistry.php`: config'ten aktif provider'ları çözer (DIP).

## Faz 3 — Senkronizasyon motoru (SPEC §5–8)

- `app/Sync/Services/ProductHasher.php` — SHA-256 + deterministik normalizasyon (§5, kod örneği).
- `app/Sync/Services/RateLimiter.php` — 5 rps + istekler arası delay (Redis).
- `app/Sync/Services/CircuitBreaker.php` — Redis'te provider bazlı ardışık-hata sayacı (§7.3).
- `app/Sync/Services/DeltaSyncService.php` — `upsert` (unique key), hash compare, soft-delete-missing,
  **delete-guard ratio** (E18), sayaçlar, kayıt-bazlı validasyon+skip (§10.2).
- `app/Sync/Services/SyncOrchestrator.php` — lock (`Cache::lock("sync-run:{provider}")`) + fetch +
  delta + `SyncLog` yaşam döngüsü; partial'da silme atlama (§6.3).
- `app/Sync/Services/DeadLetterService.php` — DLQ kaydı (sanitize edilmiş payload/exception).
- `app/Sync/Services/AlertManager.php` — structured JSON log + `sync_alerts` audit + Redis debounce
  (webhook **ertelendi**, arayüz hazır bırakılır).
- `app/Jobs/SyncProviderJob.php` — `ShouldQueue, ShouldBeUnique`; `uniqueId="sync:{provider}"`,
  `uniqueFor`, `tries=3`, `backoff()=[1,2,4]`, `retryUntil()`, `failed()`→DLQ+alert.
- `app/Console/Commands/DispatchSyncCommand.php` (`sync:dispatch`) + `routes/console.php`'de
  `Schedule::command('sync:dispatch')->everyFiveMinutes()`.

## Faz 4 — API katmanı (SPEC §9–11)

- `routes/api.php`: `/sync/{trigger,status,history,failed-jobs,retry/{jobId}}`, `/products`,
  `/health`. `auth:sanctum` (health public). Throttle: default + trigger/retry katı (SPEC §11.6).
- **Controllers** `app/Http/Controllers/Api/`: `SyncController`, `ProductController`, `HealthController`.
- **FormRequests** `app/Http/Requests/Api/`: `TriggerSyncRequest`, `SyncHistoryRequest`,
  `FailedJobsRequest`, `ProductIndexRequest` — SPEC §9/§10 (allow-list `in:`, `per_page<=100`,
  sort whitelist).
- **Resources** `app/Http/Resources/`: `ProductResource`, `SyncLogResource`, `DeadLetterJobResource`
  (`data_hash`/`raw_payload`/tam trace sızmaz — §11.5).
- **Envelope** `app/Support/ApiResponse.php` (standart success/error/meta) + `bootstrap/app.php`
  `withExceptions` → validation/known exception'ları `{success:false,error:{code,message}}` zarfına
  map (kod↔HTTP tutarlı; §9.1). Not: `shouldRenderJsonWhen(api/*)` zaten açık.

## Faz 5 — Frontend dashboard (SPEC §14)

- `resources/js/pages/sync/dashboard.tsx` + `routes/web.php`'de auth'lu route (Wayfinder). Bölümler:
  status + manuel trigger (disabled-while-pending), history (son 10), failed jobs + retry, auto-refresh
  polling. `/api` endpoint'lerine `fetch` (Inertia v3 axios'suz) + Sanctum cookie. Minimal Tailwind,
  responsive. Mümkünse `resources/js/components/ui` primitifleri yeniden kullanılır.

## Faz 6 — Testler (≥ %70, SPEC §17)

- **Unit** (`tests/Unit/`): `ProductHasherTest`, `DeltaSyncServiceTest` (added/updated/unchanged/
  deleted/restore), `CircuitBreakerTest`, `RateLimiterTest`, delete-guard.
- **Feature** (`tests/Feature/`): `SyncProviderJobTest` (idempotency 2x, retry/backoff, uniqueness,
  DLQ), provider adapters `Http::fake`, `Api/*Test` (auth 401/403, validation 422, envelope, meta,
  error kodları), `HealthTest`. `RefreshDatabase` opt-in.
- **Test ortamı:** `phpunit.xml` zaten sqlite `:memory:` + `CACHE_STORE=array` + `QUEUE=sync`.
  `Cache::lock` array store'da in-process çalışır; `upsert`/JSON/CHECK sqlite'ta desteklenir →
  **testler Redis/MySQL gerektirmez**. Gerçek provider'a çıkılmaz (`Http::fake`).

## Faz 7 — Dokümantasyon

- `README.md`: 5 dk Docker kurulumu (`docker compose up -d` → migrate → `http://localhost:8000`),
  çalıştırma (worker/scheduler zaten container), test komutları, ve SPEC §18'deki tüm teknik kararlar
  (şema/index, hash, job uniqueness, idempotency, rate limiting, alerting) gerekçeleriyle.

---

## Doğrulama (end-to-end)

1. `docker compose up -d --build` → tüm servisler ayakta; `GET /api/health` `200 ok` (db/redis/queue).
2. Dashboard'dan veya `POST /api/sync/trigger {provider:"dummyjson"}` → `202`; `sync_logs` kaydı
   `completed`, `products` tablosu ~100 kayıt, sayaçlar doğru.
3. Tekrar trigger → çoğu ürün `unchanged` (delta + idempotency kanıtı, duplicate yok).
4. `GET /api/sync/history`, `/api/products`, `/api/sync/status` → standart zarf + `meta` pagination.
5. `Http::fake` ile 429/5xx/timeout senaryoları testlerde: retry/backoff, circuit breaker `partial`,
   DLQ kaydı, `POST /api/sync/retry/{id}` re-dispatch.
6. Job uniqueness: aktif sync varken ikinci trigger → `409 SYNC_IN_PROGRESS`.
7. `php artisan test --compact` → tüm suite yeşil, ≥ %70 coverage.
8. İkinci provider (`fakestore`) trigger → `stock` policy uygulanır, mapping doğru.

## Riskler / dikkat
- **Sanctum SPA + Inertia**: stateful domain + CORS credentials doğru ayarlanmazsa dashboard 401 alır.
- **MySQL CHECK/JSON vs test SQLite**: migration'lar iki motorda da çalışacak şekilde yazılır
  (Laravel schema builder ile taşınabilir); gerekirse CHECK'ler DB-agnostik tutulur.
- **Namespace çakışması**: domain kodu `app/Sync/` (asla `app/Providers/`).
- **Scheduler container**: `schedule:work` ayrı container; `everyFiveMinutes` cron.
