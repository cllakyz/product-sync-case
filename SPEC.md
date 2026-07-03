# SPEC — Veri Senkronizasyon Sistemi

E-ticaret platformu için 3rd party tedarikçi API'lerinden ürün verilerini periyodik olarak
senkronize eden sistemin teknik spesifikasyonu. Bu doküman; veri modellerini, senkronizasyon
motorunu, validasyon kurallarını, security kontrollerini ve edge case kataloğunu tanımlar.

> Hedef stack: **Laravel 13 + PHP 8.5**, **Redis** (queue + cache + lock), **MySQL 8 / PostgreSQL 15**,
> **Inertia v3 + React 19** (dashboard). Bu repo React starter kit üzerine kuruludur; frontend
> dashboard mevcut Inertia mimarisiyle uyumlu olacaktır.

---

## İçindekiler

1. [Amaç ve Kapsam](#1-amaç-ve-kapsam)
2. [Mimari Genel Bakış](#2-mimari-genel-bakış)
3. [Provider Soyutlaması](#3-provider-soyutlaması)
4. [Veri Modelleri](#4-veri-modelleri)
5. [Hash Hesaplama Stratejisi](#5-hash-hesaplama-stratejisi)
6. [Senkronizasyon Motoru](#6-senkronizasyon-motoru)
7. [Rate Limiting, Throttling ve Circuit Breaker](#7-rate-limiting-throttling-ve-circuit-breaker)
8. [Retry, Backoff ve Dead Letter Queue](#8-retry-backoff-ve-dead-letter-queue)
9. [API Endpoint Spesifikasyonu](#9-api-endpoint-spesifikasyonu)
10. [Validasyon Kuralları](#10-validasyon-kuralları)
11. [Security Kontrolleri](#11-security-kontrolleri)
12. [Edge Case Kataloğu](#12-edge-case-kataloğu)
13. [Alerting](#13-alerting)
14. [Frontend Dashboard](#14-frontend-dashboard)
15. [Configuration (Environment)](#15-configuration-environment)
16. [Logging ve Observability](#16-logging-ve-observability)
17. [Test Stratejisi](#17-test-stratejisi)
18. [Kabul Kriterleri](#18-kabul-kriterleri)

---

## 1. Amaç ve Kapsam

Sistem, birden fazla tedarikçinin (provider) HTTP API'lerinden ürün bilgilerini (isim, fiyat,
stok, açıklama) çeker; değişiklikleri hash tabanlı delta sync ile tespit eder ve local
database'i **idempotent** biçimde günceller. Senkronizasyon periyodiktir (5–10 dk), queue
üzerinde asenkron çalışır ve hataya dayanıklıdır (retry + exponential backoff + DLQ).

**Kapsam içinde:** provider abstraction, delta sync, queue/scheduler, rate limiting, retry/DLQ,
REST API, dashboard, alerting.
**Kapsam dışı:** gerçek satın alma/sipariş akışı, çok kiracılı (multi-tenant) yetkilendirme,
provider'a yazma (write-back).

### Terimler

| Terim | Anlam |
|---|---|
| Provider | Ürün verisi sağlayan 3rd party API (dummyjson, fakestore) |
| External ID | Ürünün provider tarafındaki kimliği |
| Delta Sync | Sadece değişen kayıtları güncelleyen senkronizasyon |
| Data Hash | Ürünün iş alanlarından türetilen SHA-256 parmak izi |
| DLQ | Dead Letter Queue — max retry'ı aşan job'ların park edildiği kuyruk |
| Sync Run | Bir provider için tek bir senkronizasyon çalıştırması |

---

## 2. Mimari Genel Bakış

```
                       ┌──────────────────────────────┐
   Scheduler (5–10dk)  │  DispatchSyncCommand          │
   ── veya ──          │  (her aktif provider için)    │
   POST /sync/trigger  └──────────────┬────────────────┘
                                      │  dispatch (unique per provider)
                                      ▼
                        ┌──────────────────────────┐
                        │  SyncProviderJob          │  ShouldBeUnique
                        │  (queue: sync)            │  tries=3, backoff=[1,2,4]
                        └──────────────┬────────────┘
                                      │
             ┌────────────────────────┼─────────────────────────┐
             ▼                        ▼                          ▼
   ┌──────────────────┐   ┌────────────────────┐    ┌──────────────────────┐
   │ ProviderClient   │   │ RateLimiter        │    │ SyncLogRepository     │
   │ (adapter)        │   │ (5 req/s + backoff)│    │ (run kaydı, sayaçlar) │
   └────────┬─────────┘   └────────────────────┘    └──────────────────────┘
            │ normalize
            ▼
   ┌──────────────────┐   ┌────────────────────┐    ┌──────────────────────┐
   │ ProductDTO[]     │─▶ │ DeltaSyncService   │──▶ │ products (upsert)     │
   └──────────────────┘   │ (hash compare)     │    │ + soft delete         │
                          └─────────┬──────────┘    └──────────────────────┘
                                    │ fail (max retry aşıldı)
                                    ▼
                          ┌────────────────────┐    ┌──────────────────────┐
                          │ DeadLetterService  │──▶ │ dead_letter_jobs      │
                          └────────────────────┘    └──────────────────────┘
                                    │
                                    ▼
                          ┌────────────────────┐
                          │ AlertManager       │  (structured log + webhook)
                          └────────────────────┘
```

### Katmanlar (Clean Architecture / SoC)

- **Domain**: `ProductData` (DTO), enum'lar (`ProviderType`, `SyncStatus`), hash politikası.
- **Application/Services**: `SyncOrchestrator`, `DeltaSyncService`, `RateLimiter`, `CircuitBreaker`,
  `DeadLetterService`, `AlertManager`.
- **Infrastructure**: `ProviderClient` implementasyonları (adapter), Eloquent repository'ler, queue.
- **Interface**: HTTP Controller'lar (API), FormRequest'ler, API Resource'lar, Inertia dashboard.

SOLID uygulaması: her provider `ProductProvider` arayüzünü uygular (OCP); `SyncProviderJob` somut
provider'a değil arayüze bağımlıdır (DIP); servisler tek sorumluluğa sahiptir (SRP).

---

## 3. Provider Soyutlaması

### 3.1 Arayüz

```php
interface ProductProvider
{
    public function type(): ProviderType;

    /** @return iterable<ProductData> Normalize edilmiş, sayfalı akış */
    public function fetchAll(): iterable;

    public function fetchOne(string $externalId): ?ProductData;
}
```

Her provider konfigürasyonu (base URL, page size, rate limit, timeout, aktif/pasif) `config/sync.php`
içinde `providers` dizisinde tanımlanır. Yeni provider eklemek için: arayüzü uygulayan bir sınıf +
config kaydı yeterlidir (kod değişikliği izole).

### 3.2 Kaynak Şemaları ve Alan Eşlemesi (Mapping)

İki provider farklı response şemasına sahiptir; adapter bunları tek bir `ProductData` DTO'suna
normalize eder.

| İç Alan (`ProductData`) | DummyJSON (`/products`) | FakeStore (`/products`) | Not |
|---|---|---|---|
| `external_id` | `id` (int) | `id` (int) | string'e cast edilir |
| `name` | `title` | `title` | trim, max 255 |
| `price` | `price` (number) | `price` (number) | DECIMAL(12,2)'ye normalize |
| `stock` | `stock` (int) | *(yok)* | FakeStore'da yok → `null`/`0` policy |
| `description` | `description` | `description` | TEXT |

- **DummyJSON** response'u `{ products: [...], total, skip, limit }` zarfıyla döner → sayfalama
  `limit`/`skip` ile yapılır (100 kayıt).
- **FakeStore** düz bir dizi `[ {...}, ... ]` döner, sayfalama parametresi yoktur → tek çağrı +
  client-side chunking; `stock` alanı **yoktur**.
- **Stock policy (config'ten):** eksik `stock` için `missing_stock_policy = null | zero | keep`.
  Varsayılan `keep` (mevcut değeri koru, ilk eklemede `0`). Bu edge case #E12'de detaylandırılır.

DTO minimum invariant'leri (adapter garanti eder): `external_id` boş değil; `name` boş değil;
`price >= 0`; `stock >= 0` veya `null`. İhlal eden kayıtlar **skip + warn log** edilir, tüm sync'i
düşürmez (bkz. #E06).

---

## 4. Veri Modelleri

Tüm timestamp'ler **UTC** saklanır. Motor MySQL 8 ve PostgreSQL 15 ile uyumludur; tip notları
her ikisini kapsar.

### 4.1 `products`

| Kolon | Tip | Kısıt / Not |
|---|---|---|
| `id` | BIGINT UNSIGNED | PK, auto-increment |
| `provider_type` | VARCHAR(32) | NOT NULL, enum-backed (`dummyjson`,`fakestore`) |
| `external_id` | VARCHAR(64) | NOT NULL — sağlayıcı kimliği (int olsa da string saklanır) |
| `name` | VARCHAR(255) | NOT NULL |
| `price` | DECIMAL(12,2) | NOT NULL, `>= 0` (CHECK) |
| `stock` | INT UNSIGNED | NULLABLE, `>= 0` |
| `description` | TEXT | NULLABLE |
| `data_hash` | CHAR(64) | NOT NULL — SHA-256 hex |
| `raw_payload` | JSON | NULLABLE — denetim/debug için normalize öncesi ham kayıt |
| `last_synced_at` | TIMESTAMP | NOT NULL |
| `deleted_at` | TIMESTAMP | NULLABLE — **soft delete** (provider'dan kalkan ürün) |
| `created_at` | TIMESTAMP | NOT NULL |
| `updated_at` | TIMESTAMP | NOT NULL |

**Kısıtlar ve indeksler**
- `UNIQUE (provider_type, external_id)` — **idempotency ve upsert'in temeli.** Bir ürün (provider,
  external_id) çifti ile tekildir; duplicate insert imkânsız.
- `INDEX (provider_type, deleted_at)` — aktif ürünleri provider bazında listelemek/silinenleri
  tespit için.
- `INDEX (data_hash)` — hash karşılaştırma sorgularını hızlandırır.
- `INDEX (last_synced_at)` — bir sync run'da "bu koşuda görülmeyen" (dolayısıyla silinmiş)
  kayıtları bulmak için.
- `INDEX (name)` veya full-text (arama endpoint'i için, opsiyonel).
- CHECK: `price >= 0`, `stock >= 0` (PostgreSQL'de native; MySQL 8'de CHECK desteklenir).

> **Neden `external_id` VARCHAR?** Provider'lar bugün integer ID kullanıyor; ancak string/UUID
> kullanan bir provider eklendiğinde şema değişmesin diye VARCHAR seçildi. Karşılaştırma daima
> `(provider_type, external_id)` bileşik anahtarı üzerinden yapılır.

### 4.2 `sync_logs`

Her sync run için bir kayıt.

| Kolon | Tip | Kısıt / Not |
|---|---|---|
| `id` | BIGINT UNSIGNED | PK |
| `provider_type` | VARCHAR(32) | NOT NULL, indexed |
| `trigger_type` | VARCHAR(16) | NOT NULL — `scheduled` \| `manual` |
| `status` | VARCHAR(16) | NOT NULL — `pending`\|`running`\|`completed`\|`failed`\|`partial` |
| `started_at` | TIMESTAMP | NULLABLE |
| `completed_at` | TIMESTAMP | NULLABLE |
| `duration_ms` | INT UNSIGNED | NULLABLE — türetilmiş, sorguyu kolaylaştırır |
| `products_added` | INT UNSIGNED | DEFAULT 0 |
| `products_updated` | INT UNSIGNED | DEFAULT 0 |
| `products_deleted` | INT UNSIGNED | DEFAULT 0 |
| `products_unchanged` | INT UNSIGNED | DEFAULT 0 |
| `products_failed` | INT UNSIGNED | DEFAULT 0 — skip edilen geçersiz kayıtlar |
| `api_requests` | INT UNSIGNED | DEFAULT 0 — performans metriği |
| `error_message` | TEXT | NULLABLE |
| `created_at` / `updated_at` | TIMESTAMP | |

**İndeksler:** `INDEX (provider_type, status)`, `INDEX (created_at)` (history sıralaması ve
"son 10 kayıt" için), `INDEX (status, started_at)` (aktif sync sorgusu).

`status = partial`: bazı kayıtlar işlendi ama sync tam bitmeden durdu (ör. circuit breaker tetikledi).

### 4.3 `dead_letter_jobs` (DLQ)

Max retry'ı aşan job'lar. Laravel'in yerleşik `failed_jobs` tablosundan **ayrı**; manuel retry ve
domain metadata içerir. (Laravel'in `failed_jobs` tablosu da göç olarak bulunur; DLQ onun üzerine
inşa edilen domain görünümüdür.)

| Kolon | Tip | Kısıt / Not |
|---|---|---|
| `id` | BIGINT UNSIGNED | PK |
| `job_type` | VARCHAR(64) | NOT NULL — ör. `SyncProviderJob` |
| `provider_type` | VARCHAR(32) | NULLABLE, indexed |
| `payload` | JSON | NOT NULL — job'ı yeniden kurmak için gerekli girdi (provider, opsiyonlar) |
| `exception` | TEXT | NOT NULL — sınıf + mesaj + (kısaltılmış) trace |
| `retry_count` | INT UNSIGNED | DEFAULT 0 — job'ın toplam deneme sayısı |
| `status` | VARCHAR(16) | NOT NULL — `pending`\|`retrying`\|`resolved`\|`discarded` |
| `failed_at` | TIMESTAMP | NOT NULL |
| `last_retried_at` | TIMESTAMP | NULLABLE |
| `resolved_at` | TIMESTAMP | NULLABLE |
| `created_at` / `updated_at` | TIMESTAMP | |

**İndeksler:** `INDEX (status)`, `INDEX (provider_type, status)`, `INDEX (failed_at)`.

> **Güvenlik notu:** `payload` ve `exception` içine credential/secret sızmamalı. Payload sadece
> provider tipi ve iş opsiyonlarını taşır; HTTP header/token asla serialize edilmez (bkz. §11).

### 4.4 `sync_alerts` (opsiyonel, alert rate limiting için)

| Kolon | Tip | Not |
|---|---|---|
| `id` | BIGINT UNSIGNED | PK |
| `alert_type` | VARCHAR(48) | `SYNC_FAILURE`, `DLQ_THRESHOLD`, `API_FAILURE`, `QUEUE_BACKLOG` |
| `severity` | VARCHAR(16) | `warning`\|`critical` |
| `provider_type` | VARCHAR(32) | NULLABLE |
| `details` | JSON | Alert bağlamı (count, threshold vb.) |
| `notified_at` | TIMESTAMP | Son bildirim zamanı (5 dk debounce anahtarı) |
| `created_at` | TIMESTAMP | |

Not: Debounce Redis'te de tutulabilir; bu tablo denetim geçmişi (audit trail) sağlar.

### 4.5 Migration vs SQL

Laravel migration dosyaları (`database/migrations/`) canonical kaynaktır. Alternatif olarak
`docs/schema.sql` dump sağlanır. Seed data opsiyoneldir (`ProductSeeder` küçük bir örnek set).

---

## 5. Hash Hesaplama Stratejisi

**Amaç:** İki senkronizasyon arasında ürünün "iş açısından anlamlı" alanları değişti mi sorusunu
tek bir karşılaştırmayla yanıtlamak.

### 5.1 Hangi alanlar hash'e dahil?

Sadece iş verisi: `name`, `price`, `stock`, `description`.
**Hariç:** `id`, `external_id`, `provider_type` (kimlik, zaten anahtar), tüm timestamp'ler,
provider'ın volatil/anlamsız alanları (rating, image URL, view count vb.).

### 5.2 Normalizasyon (deterministiklik şart)

Hash girdisi platform/PHP versiyonundan bağımsız aynı çıktıyı vermelidir:

1. `name`, `description`: `trim()` + Unicode NFC normalize + iç boşlukları teklileştir.
2. `price`: her zaman 2 ondalık, string olarak `number_format($price, 2, '.', '')` → `"19.99"`.
   Float karşılaştırma yapılmaz (float precision tuzağından kaçınmak için).
3. `stock`: integer; `null` ise sabit sentinel `"__NULL__"`.
4. Alanlar **sabit sıra** ile associative array'e konur, `JSON_UNESCAPED_UNICODE |
   JSON_UNESCAPED_SLASHES` ile serialize edilir (anahtar sırası sabit).
5. `data_hash = hash('sha256', $canonicalJson)`.

```php
final class ProductHasher
{
    public function hash(ProductData $p): string
    {
        $canonical = json_encode([
            'name'        => $this->normalizeText($p->name),
            'price'       => number_format($p->price, 2, '.', ''),
            'stock'       => $p->stock ?? '__NULL__',
            'description' => $this->normalizeText($p->description ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return hash('sha256', $canonical);
    }
}
```

### 5.3 Karşılaştırma akışı

1. Provider'dan gelen kayıt için `newHash` hesaplanır.
2. DB'de `(provider_type, external_id)` ile kayıt aranır.
   - Yoksa → **added** (insert).
   - Varsa ve `existing.data_hash === newHash` → **unchanged** (yalnızca `last_synced_at` dokunulur;
     `updated_at` değişmez).
   - Varsa ve hash farklı → **updated** (alanlar + `data_hash` güncellenir).
   - Kayıt soft-deleted iken tekrar geldiyse → **restored** (deleted_at = null, updated sayılır).
3. Bu run'da hiç görülmeyen aktif kayıtlar → **deleted** (soft delete).

> **Hash collision:** SHA-256'da pratikte imkânsız; yine de risk kabul edilir ve dokümante edilir.
> `last_synced_at` her koşuda güncellendiği için bir kayıt asla "kaybolmaz".

---

## 6. Senkronizasyon Motoru

### 6.1 Tetikleme

- **Scheduler:** `routes/console.php` içinde `Schedule::command('sync:dispatch')->everyFiveMinutes()`
  (config'ten `sync.interval`). Komut her **aktif** provider için bir `SyncProviderJob` dispatch eder.
- **Manuel:** `POST /api/sync/trigger` aynı job'ı dispatch eder (`trigger_type = manual`).

### 6.2 Job Uniqueness (Provider bazında tekillik)

**Karar:** Laravel `ShouldBeUnique` + Redis lock kombinasyonu.

- `SyncProviderJob implements ShouldBeUnique`; `uniqueId()` = `"sync:{provider_type}"`.
- `uniqueFor` = beklenen max çalışma süresi + tampon (ör. 600 sn). Böylece bir DummyJSON sync'i
  çalışırken/kuyrukta beklerken **ikinci bir DummyJSON job'ı dispatch edilmez** (Laravel unique
  lock'u Redis'te tutar).
- İş yürütme (execution) başında ek olarak atomik `Cache::lock("sync-run:{provider}", ttl)`
  alınır. Lock alınamazsa job **çakışma** olmadan sonlanır ve log'lanır (double-guard, worker
  yeniden dağıtım senaryolarına karşı).
- **Stale lock koruması:** lock TTL'i vardır; worker ölürse lock süre sonunda otomatik serbest kalır
  (deadlock önlenir). `uniqueFor` de aynı amaca hizmet eder.

**Neden bu yaklaşım?** DB flag yaklaşımı race condition'a açık (check-then-set); cache/Redis lock
atomiktir. `ShouldBeUnique` dispatch anında, `Cache::lock` execution anında korur — iki katman.

### 6.3 Delta Sync akışı (idempotent)

```
SyncProviderJob::handle():
  1. run = SyncLog::create(status=running, started_at=now)   // audit başlangıç
  2. lock = Cache::lock("sync-run:{provider}")->get()
       if !lock: run.update(status=skipped); return
  3. seenExternalIds = []
  4. foreach (provider.fetchAll() as dto):   // rate-limited, sayfalı
       validate(dto) -> geçersizse products_failed++, continue
       seenExternalIds[] = dto.external_id
       upsertProduct(dto)  // hash compare, added/updated/unchanged sayaçları
  5. deleted = softDeleteMissing(provider, seenExternalIds)   // bu koşuda görülmeyenler
  6. run.update(status=completed, completed_at, duration_ms, sayaçlar)
  7. lock.release()
```

**Idempotency garantileri:**
- `upsertProduct`, `(provider_type, external_id)` unique key üzerinden **UPSERT** yapar
  (`INSERT ... ON DUPLICATE KEY UPDATE` / PostgreSQL `ON CONFLICT ... DO UPDATE`). Aynı job iki kez
  çalışsa da duplicate satır oluşmaz.
- Her ürün upsert'i kısa bir **transaction** içindedir; job yarıda kesilirse yarım kalan ürün
  bir sonraki koşuda hash aynıysa `unchanged` olur → **tekrar eklenmez**.
- `softDeleteMissing`, `seenExternalIds` set'ine ve `last_synced_at < run.started_at` koşuluna
  dayanır; kısmi (partial) koşularda yanlış silme yapmamak için **yalnızca sync `completed`
  olduğunda** çalışır (partial'da silme atlanır — bkz. #E19).

### 6.4 Batching ve bellek

`fetchAll()` bir **generator/iterable** döndürür; tüm ürünler belleğe yüklenmez. Upsert'ler
chunk'lar (ör. 50) hâlinde yapılabilir. 100 ürün küçük olsa da tasarım büyük katalogları destekler.

---

## 7. Rate Limiting, Throttling ve Circuit Breaker

**Varsayım:** saniyede max **5 request**.

### 7.1 Client-side throttling
- Redis tabanlı token-bucket / `RateLimiter::attempt("provider:{type}", 5, ..., perSecond)`.
  Her outbound request öncesi izin alınır; kota dolduysa bir sonraki pencereye kadar bekler.
- Ek olarak istekler arası minimum gecikme: `1000ms / max_rps` (≈200ms). Basit ve öngörülebilir.

### 7.2 429 (Too Many Requests) yönetimi
- 429 alınırsa: response'taki `Retry-After` header'ı varsa ona uyulur; yoksa **exponential backoff**
  (`1s, 2s, 4s, 8s...`, `max_backoff` ile sınırlı) + jitter uygulanır ve request retry edilir.
- 429'lar bir request'in iç retry döngüsündedir (job retry'ından ayrı); job'ın `tries`'ını tüketmez.

### 7.3 Circuit Breaker (ardışık hata koruması)
- **5 ardışık başarısız request** (network hatası, 5xx, timeout) olursa breaker `open` olur:
  sync **durdurulur**, `sync_logs.status = partial`, `error_message` yazılır, `API_FAILURE` alert'i
  tetiklenir.
- Sayaç başarılı bir request'te sıfırlanır (`half-open` → `closed`).
- Breaker durumu Redis'te provider bazında tutulur; kısa bir cooldown penceresinde yeni sync'ler
  hızlıca `skipped` olabilir (opsiyonel — API'yi dövmemek için).

### 7.4 Timeout'lar
- Connect timeout ve request timeout config'ten (ör. 5s / 15s). Sonsuz bekleme yasak.

---

## 8. Retry, Backoff ve Dead Letter Queue

### 8.1 Job retry
- `SyncProviderJob`: `public int $tries = 3;` ve `public function backoff(): array { return [1,2,4]; }`
  → denemeler arası **1s, 2s, 4s** (exponential).
- `retryUntil()` opsiyonel üst sınır (ör. +10 dk) — sonsuz retry'ı engeller.
- **Retriable vs non-retriable:** ağ/5xx/429 → retriable. Validation/programlama hatası (ör. config
  eksik) → non-retriable; `fail()` ile hemen DLQ'ya düşer (boşa retry yok).

### 8.2 Dead Letter Queue
- `SyncProviderJob::failed(Throwable $e)` hook'u tetiklenir (son deneme de başarısızsa):
  `DeadLetterService::record($job, $e)` → `dead_letter_jobs` tablosuna `status=pending`,
  `retry_count = $this->attempts()`, sanitize edilmiş `exception` ve minimal `payload` yazar.
- Aynı zamanda `SYNC_FAILURE` alert değerlendirmesi yapılır.

### 8.3 Manuel retry
- `POST /api/sync/retry/{jobId}`: `dead_letter_jobs.id` alınır, `payload`'tan yeni bir
  `SyncProviderJob` **yeniden kurulur** ve dispatch edilir; DLQ kaydı `status=retrying`,
  `last_retried_at=now` olur. Yeni koşu başarılıysa `resolved` (webhook/observer ile), yine
  başarısızsa yeni bir DLQ kaydı oluşur veya `retry_count` artar.
- Idempotency: retry de aynı unique-lock'a tabidir; aktif sync varsa beklemeli/atlamalı.

---

## 9. API Endpoint Spesifikasyonu

Tüm endpoint'ler `/api` prefix'i altında, **standart JSON zarfı** ile yanıt verir.

### 9.1 Standart Response Zarfı

Başarılı:
```json
{
  "success": true,
  "data": {},
  "meta": { "page": 1, "per_page": 20, "total": 100 },
  "message": "İşlem başarılı"
}
```
Hatalı:
```json
{
  "success": false,
  "error": { "code": "SYNC_FAILED", "message": "Senkronizasyon başarısız oldu" }
}
```

- `meta` alanı **pagination kullanan tüm endpoint'lerde zorunludur** (`page`, `per_page`, `total`,
  ayrıca `total_pages`).
- Hata `code` değerleri sabit bir enum'dan gelir: `VALIDATION_ERROR`, `SYNC_FAILED`,
  `SYNC_IN_PROGRESS`, `NOT_FOUND`, `JOB_NOT_RETRIABLE`, `RATE_LIMITED`, `UNAUTHENTICATED`,
  `FORBIDDEN`, `INTERNAL_ERROR`.
- HTTP status ↔ `code` tutarlıdır (422, 404, 409, 429, 401, 403, 500).
- Yanıt formatı merkezi bir `ApiResponse` helper + `Handler`/`shouldRenderJsonWhen` (bkz.
  `bootstrap/app.php`, zaten `api/*` için JSON render açık) ile üretilir; controller'lar zarfı elle
  kurmaz.

### 9.2 Endpoint'ler

| # | Method & Path | Amaç | Auth | Pagination |
|---|---|---|---|---|
| 1 | `POST /api/sync/trigger` | Manuel sync başlat | ✔ | — |
| 2 | `GET /api/sync/status` | Aktif sync durumu | ✔ | — |
| 3 | `GET /api/sync/history` | Geçmiş sync logları | ✔ | ✔ |
| 4 | `GET /api/sync/failed-jobs` | DLQ kayıtları | ✔ | ✔ |
| 5 | `POST /api/sync/retry/{jobId}` | Failed job retry | ✔ | — |
| 6 | `GET /api/products` | Local ürün listesi | ✔ | ✔ |
| 7 | `GET /api/health` | Health check | ✖ (public) | — |

Auth modeli §11.1'de. `health` public'tir ancak hassas iç bilgi sızdırmaz.

---

#### (1) `POST /api/sync/trigger`

Belirli bir provider (veya tümü) için manuel senkronizasyon dispatch eder.

**Request body**
```json
{ "provider": "dummyjson", "force": false }
```
- `provider`: opsiyonel; verilmezse tüm aktif provider'lar. Verilirse config'teki aktif
  provider listesinde olmalı (`in:dummyjson,fakestore`).
- `force`: opsiyonel bool; `true` ise breaker cooldown'ı yok sayar (yine de unique-lock geçerli).

**Davranış / yanıtlar**
- Yeni job dispatch edildi → `202 Accepted`, `data: { queued: ["dummyjson"], sync_log_ids: [...] }`.
- İlgili provider için zaten aktif sync var → `409 Conflict`, `code: SYNC_IN_PROGRESS`
  (unique-lock nedeniyle yeni job atılmaz; mevcut run bilgisi döner).
- **Duplicate submit koruması:** kısa süreli idempotency — opsiyonel `Idempotency-Key` header'ı
  desteklenir; aynı key 60 sn içinde tekrar gelirse ilk yanıt döner (çift buton tıklaması).

---

#### (2) `GET /api/sync/status`

Aktif (running/pending) sync'lerin anlık durumu. Query: `provider` (opsiyonel filtre).

**data örneği**
```json
{
  "active": [
    { "provider": "dummyjson", "status": "running", "started_at": "...",
      "elapsed_ms": 4200, "products_processed": 60 }
  ],
  "last_completed": {
    "dummyjson": { "status": "completed", "completed_at": "...", "duration_ms": 8100,
                   "added": 3, "updated": 12, "deleted": 1, "unchanged": 84 }
  }
}
```

---

#### (3) `GET /api/sync/history`

Geçmiş `sync_logs`, en yeni önce. **Pagination zorunlu.**

**Query params (validasyon)**
- `page`: integer, `min:1`, default 1.
- `per_page`: integer, `min:1`, `max:100`, default 20.
- `provider`: opsiyonel, `in:dummyjson,fakestore`.
- `status`: opsiyonel, `in:completed,failed,partial,running,pending`.

`meta` doldurulur (`page`, `per_page`, `total`, `total_pages`).

---

#### (4) `GET /api/sync/failed-jobs`

`dead_letter_jobs` listesi. Pagination + `status` filtresi
(`in:pending,retrying,resolved,discarded`, default `pending`). `exception` alanı response'ta
**kısaltılmış** döner (tam trace log'da; §11 output sanitization).

---

#### (5) `POST /api/sync/retry/{jobId}`

**Path param:** `jobId` → `dead_letter_jobs.id`, `integer`, `exists:dead_letter_jobs,id`.

**Davranış**
- Kayıt `pending`/`discarded` değilse → `409`, `code: JOB_NOT_RETRIABLE` (ör. zaten `retrying`).
- Aktif sync varsa → job kuyrukta bekler (unique-lock) veya `409 SYNC_IN_PROGRESS`.
- Başarılı dispatch → `202`, `data: { dead_letter_job_id, status: "retrying" }`.

---

#### (6) `GET /api/products`

Local ürünler. **Pagination + filtre + sıralama.** Varsayılan olarak soft-deleted hariç.

**Query params**
- `page`, `per_page` (yukarıdaki gibi).
- `provider`: `in:dummyjson,fakestore`.
- `search`: string, `max:100` — `name` üzerinde `LIKE` (bind'li, injection yok).
- `min_price` / `max_price`: numeric, `min:0`; `max_price >= min_price`.
- `in_stock`: boolean — `true` ise `stock > 0`.
- `include_deleted`: boolean, default false.
- `sort`: `in:name,price,stock,last_synced_at`; `direction`: `in:asc,desc`
  (allow-list — arbitrary kolon sıralaması engellenir, SQL injection koruması).

Her ürün `ProductResource` ile serialize edilir (`data_hash`, `raw_payload` **dışarı sızmaz**).

---

#### (7) `GET /api/health`

Bağımlılık sağlığı. Public, ama minimal bilgi.

```json
{
  "success": true,
  "data": {
    "status": "ok",
    "checks": { "database": "ok", "redis": "ok", "queue": "ok" },
    "queue_backlog": 3,
    "last_sync": { "dummyjson": "2026-07-03T10:00:00Z", "fakestore": "..." }
  }
}
```
- Herhangi bir check `fail` ise `status: degraded` + HTTP `503`.
- Sürüm/commit gibi hassas dağıtım detayları burada verilmez (§11).

---

## 10. Validasyon Kuralları

### 10.1 API girdisi (FormRequest ile)

Her yazma/sorgu endpoint'i için bir `FormRequest`; kurallar §9'da endpoint bazında listelendi.
Özet ilkeler:
- Tüm sayısal sınırlar açık (`min`, `max`); `per_page` **max 100** ile sınırlı (DoS/ağır sorgu
  koruması).
- Enum benzeri alanlar (`provider`, `status`, `sort`, `direction`) **allow-list** (`in:`) ile.
- Bilinmeyen/fazla alanlar reddedilir veya yok sayılır (mass-assignment'a kapalı; §11).
- Validation hatası → `422`, `code: VALIDATION_ERROR`, `error.details` alanında alan bazlı mesajlar.

### 10.2 Provider verisi (persist öncesi) — **3rd party asla güvenilmez**

Adapter ve `DeltaSyncService`, dış veriyi DB'ye yazmadan önce doğrular:

| Alan | Kural | İhlal davranışı |
|---|---|---|
| `external_id` | boş değil, `max:64` | kayıt **skip**, `products_failed++`, warn log |
| `name` | boş değil, `max:255` (fazlası truncate + warn) | truncate veya skip (config) |
| `price` | numeric, `>= 0`, `< 10^10` (kolon sınırı) | skip + warn (negatif/overflow) |
| `stock` | integer, `>= 0`, `<= 4294967295` veya null | negatifse `0`'a clamp + warn |
| `description` | string veya null; aşırı büyükse truncate | truncate + warn |

- Bir kaydın geçersizliği **tüm sync'i düşürmez** (resilience); sayaçlara yansır ve `partial`
  değerlendirmesine girer.
- Tip zorlaması (coercion) güvenli yapılır: `"19.99"` → `19.99`; parse edilemeyen fiyat → skip.

---

## 11. Security Kontrolleri

### 11.1 Authentication & Authorization
- Durum değiştiren ve veri döndüren API endpoint'leri **korumalı**: `auth:sanctum` (bearer token)
  veya oturum tabanlı (Inertia dashboard aynı origin'den çağırıyorsa). Public sadece `GET /health`.
- `sync/trigger` ve `sync/retry` gibi maliyetli/aksiyon endpoint'leri için ek yetki (policy/ability
  `sync:trigger`). En az yetki ilkesi.
- Başarısız auth → `401 UNAUTHENTICATED`; yetki yok → `403 FORBIDDEN`. Zarf formatı korunur.

### 11.2 Input & Injection
- **SQL injection:** yalnızca Eloquent/Query Builder + parametre binding. `search`, filtreler,
  `sort` allow-list ile; ham string interpolasyonu yok.
- **Mass assignment:** modellerde `$fillable` beyaz liste (veya `$guarded` + DTO ile açık atama).
  Provider verisi doğrudan `Model::create($request->all())` şeklinde **asla** yazılmaz.
- FormRequest ile katı tip/enum doğrulaması; `per_page` üst sınırı ile ağır sorgu/DoS engeli.

### 11.3 Outbound / SSRF
- Provider base URL'leri **yalnızca config'ten** gelir; kullanıcı girdisinden URL alınmaz →
  SSRF yüzeyi yok.
- Outbound HTTP **HTTPS** ve TLS doğrulaması açık (`verify => true`). Connect/request timeout zorunlu.
- Yanıt boyutu makul sınırla korunur; dev API'nin beklenmedik dev payload'ına karşı koruma.

### 11.4 Secrets & Config
- API anahtarları, DB/Redis kimlikleri **yalnızca `.env`**; repoya girmez. `.env.example` yer tutucu
  değerlerle sağlanır.
- Loglara ve DLQ `payload`/`exception` alanlarına **secret/token yazılmaz** (sanitizer devrede).
- Prod'da `APP_DEBUG=false`; hata yanıtlarında stack trace/iç detay sızmaz (`INTERNAL_ERROR` +
  korelasyon id).

### 11.5 Output Sanitization
- `ProductResource`/`SyncLogResource` yalnızca gerekli alanları döndürür; `raw_payload`, tam
  `exception` trace, iç ID'ler dışa verilmez.
- `health` endpoint'i versiyon/commit/altyapı detayı sızdırmaz.

### 11.6 CSRF, CORS, Rate Limiting (API abuse)
- Web (Inertia) tarafı CSRF korumalı (Laravel varsayılan). Token tabanlı API stateless.
- CORS yalnızca bilinen origin(ler)e açık (`config/cors.php`).
- **API throttling:** `throttle` middleware — genel endpoint'ler için makul limit; `sync/trigger`
  ve `sync/retry` için **daha katı** limit (ör. 10/dk) → kötüye kullanım ve gereksiz maliyet önlenir.
  Aşımda `429 RATE_LIMITED`.

### 11.7 Denetim (Audit)
- Kim, ne zaman manuel sync/retry tetikledi → `sync_logs.trigger_type` + (varsa) `triggered_by`
  kullanıcı id'si loglanır. Alert ve DLQ olayları kalıcı iz bırakır.

---

## 12. Edge Case Kataloğu

| # | Senaryo | Beklenen davranış |
|---|---|---|
| E01 | API timeout / connection refused | Request retry (backoff); ardışık 5 → circuit breaker `open`, sync `partial`, `API_FAILURE` alert |
| E02 | HTTP 429 | `Retry-After`/exponential backoff ile request-level retry; job retry'ı tüketmez |
| E03 | HTTP 5xx | Retriable; request retry, breaker sayacına dâhil |
| E04 | Malformed JSON / şema dışı response | Kayıt/sayfa skip + error log; parse edilemeyen tüm response → run `failed`/`partial` |
| E05 | Boş ürün listesi (`[]`) | Geçerli sonuç; **hiçbir ürün silinmez** yalnızca gerçekten "görülmeyen"ler; run `completed` (0 değişiklik). Ama tüm liste boşsa güvenlik için toplu silme **eşik korumalı** (bkz. E18) |
| E06 | Eksik/geçersiz alan (null name, negatif price) | Kayıt skip veya clamp/truncate (§10.2); `products_failed++`; sync devam |
| E07 | Aynı response'ta duplicate `external_id` | İlk kazanır / son kazanır (deterministik, "son kazanır"); tek satır upsert; warn log |
| E08 | Provider'dan ürün kalktı (DB'de var, API'de yok) | Soft delete (`deleted_at`), `products_deleted++` |
| E09 | Silinmiş ürün tekrar geldi | Restore (`deleted_at=null`), `updated` sayılır |
| E10 | Değişmemiş ürün | `unchanged`; sadece `last_synced_at`, `updated_at` sabit kalır |
| E11 | Unicode/emoji, uzun isim | NFC normalize; `name` > 255 → truncate + warn; DB utf8mb4 |
| E12 | FakeStore'da `stock` yok | `missing_stock_policy` (keep/zero/null); hash'te tutarlı sentinel |
| E13 | Fiyat string vs number ("19.99" vs 19.99) | Normalizasyonla aynı hash; gereksiz update yok |
| E14 | Numeric overflow (price ≥ 10^10, stock > uint max) | Kayıt skip + warn (kolon sınırı korunur) |
| E15 | Aynı provider için eşzamanlı sync (scheduler + manuel) | Unique-lock: ikincisi dispatch edilmez / `409 SYNC_IN_PROGRESS` |
| E16 | Job execution ortasında worker ölür | Lock TTL/`uniqueFor` ile lock serbest kalır; sonraki koşu idempotent (hash → unchanged, duplicate yok) |
| E17 | Aynı job iki kez çalışır (retry) | UPSERT + transaction sayesinde duplicate satır oluşmaz |
| E18 | API tüm ürünleri "yok" gösteriyor (provider arızası) | **Toplu silme koruması:** bir koşuda silinecek oran/sayı eşiği (ör. > %50) aşarsa silme **iptal**, `partial` + alert; yanlış kitlesel soft-delete engellenir |
| E19 | Circuit breaker ile yarıda kesilen sync | `status=partial`; **silme adımı atlanır** (eksik `seen` set'iyle yanlış silme yapılmaz) |
| E20 | Redis down (lock/queue) | Job dispatch/lock başarısız → graceful degrade + alert; scheduler bir sonraki periyotta tekrar dener |
| E21 | DB bağlantısı sync ortasında düşer | Aktif ürün transaction'ı rollback; run `failed`; retry devreye girer |
| E22 | Clock skew / periyot çakışması | Unique-lock zaten korur; `retryUntil` ile zombie job engeli |
| E23 | Çok büyük description (TEXT sınırı) | Truncate + warn; hash truncate sonrası değerle hesaplanır (tutarlı) |
| E24 | Hash collision (teorik) | Kabul edilen risk; `last_synced_at` her koşuda güncellenir, kayıt kaybolmaz |
| E25 | Retry edilen DLQ job'ı tekrar patlar | `retry_count` artar / yeni DLQ kaydı; tekrarlıysa `discarded` + alert |

---

## 13. Alerting

Alert değerlendirmesi merkezi `AlertManager` içinde; her ilgili olaydan sonra tetiklenir.

### Alert senaryoları ve eşikleri (config'ten)

| Alert Type | Koşul | Severity |
|---|---|---|
| `SYNC_FAILURE` | Bir provider için **3 ardışık** başarısız sync | critical |
| `DLQ_THRESHOLD` | `dead_letter_jobs (pending)` sayısı > `10` | critical |
| `API_FAILURE` | 5+ ardışık başarısız request (circuit breaker `open`) | critical |
| `QUEUE_BACKLOG` | Bekleyen job sayısı > `100` | warning |

### Uygulama

- **Structured logging (zorunlu minimum):** `LOG_LEVEL=ALERT/CRITICAL` ile JSON log entry:
  ```json
  { "level": "ALERT", "type": "SYNC_FAILURE", "severity": "critical",
    "provider": "dummyjson", "count": 3, "timestamp": "2026-07-03T10:00:00Z",
    "details": { "last_error": "..." } }
  ```
  Bu loglar bir monitoring aracına (Loki/ELK/Datadog) kolayca parse edilebilir.
- **Notification (tercih edilen):** Slack/Discord/webhook — endpoint `.env`'den (`ALERT_WEBHOOK_URL`).
  Eşikler config/env'den ayarlanabilir.
- **Alert rate limiting:** aynı `(alert_type, provider)` için **5 dakikada 1 kez** bildirim
  (Redis debounce anahtarı + `sync_alerts` audit kaydı). Alert fırtınası önlenir.

Alerting stratejisi README'de de özetlenir.

---

## 14. Frontend Dashboard

Tek sayfa, mevcut **Inertia v3 + React** mimarisiyle uyumlu (`resources/js/pages/sync/dashboard.tsx`).
Route korumalı (auth). Wayfinder ile tip güvenli endpoint çağrıları; Tailwind ile responsive layout.

**Bölümler**
- **Header:** "Sync Dashboard" başlığı.
- **Status section:** her provider için son sync durumu (tarih, süre, sonuç), eklenen/güncellenen/
  silinen/değişmeyen sayıları; **manuel trigger** butonu (`POST /sync/trigger`, çift tıklama
  koruması / disabled-while-pending).
- **History section:** son 10 sync (`GET /sync/history?per_page=10`) — basit tablo.
- **Failed jobs section:** DLQ listesi + satır başına **Retry** butonu (`POST /sync/retry/{id}`).
- **Auto-refresh:** Inertia polling (ör. 5 sn) veya `useHttp` ile periyodik `status` çekme.
- **Responsive:** mobil uyumlu; süslü animasyon beklenmez, fonksiyonel yeterli.

Hata durumları kullanıcıya sade mesajla gösterilir (zarf `error.message`).

---

## 15. Configuration (Environment)

`.env.example` tüm değişkenleri açıklamalı içerir. Örnek anahtarlar:

```dotenv
# App
APP_ENV=local
APP_DEBUG=true            # prod'da false
APP_URL=http://localhost:8000

# Database (MySQL veya PostgreSQL)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=data_sync
DB_USERNAME=root
DB_PASSWORD=

# Queue & Cache & Lock
QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# Sync engine
SYNC_INTERVAL_MINUTES=5             # 5–10
SYNC_MAX_RPS=5                      # provider başına saniyede max request
SYNC_JOB_TRIES=3
SYNC_JOB_BACKOFF=1,2,4             # saniye (exponential)
SYNC_CONSECUTIVE_FAILURE_LIMIT=5   # circuit breaker
SYNC_DELETE_GUARD_RATIO=0.5        # bir koşuda max silme oranı (E18)
SYNC_MISSING_STOCK_POLICY=keep     # keep|zero|null

# Providers
PROVIDER_DUMMYJSON_ENABLED=true
PROVIDER_DUMMYJSON_BASE_URL=https://dummyjson.com
PROVIDER_FAKESTORE_ENABLED=true
PROVIDER_FAKESTORE_BASE_URL=https://fakestoreapi.com
HTTP_CONNECT_TIMEOUT=5
HTTP_REQUEST_TIMEOUT=15

# Alerting
ALERT_WEBHOOK_URL=
ALERT_DLQ_THRESHOLD=10
ALERT_QUEUE_BACKLOG_THRESHOLD=100
ALERT_SYNC_FAILURE_STREAK=3
ALERT_DEBOUNCE_MINUTES=5

# API security
API_THROTTLE_DEFAULT=60,1          # istek,dakika
API_THROTTLE_TRIGGER=10,1
```

`config/sync.php` bu env'leri tipli config'e map eder; provider tanımları burada bir dizidir.

---

## 16. Logging ve Observability

- **Structured (JSON) logging:** sync başlangıç/bitiş, süre, sayaçlar, hata detayları, per-request
  metrikler. Korelasyon için her run'a `sync_log_id`/`run_id` eklenir.
- **Performance metrics:** `duration_ms`, `api_requests`, işlenen ürün/sn. `sync_logs`'ta kalıcı.
- **Log seviyeleri:** `info` (normal akış), `warning` (skip/clamp/truncate), `error` (request/DB
  hatası), `alert/critical` (§13).
- **Secret sanitization:** logger'a giden context'ten token/credential temizlenir.
- **Health/backlog** metrikleri `GET /health` üzerinden dışa açılır (monitoring probe).

---

## 17. Test Stratejisi

**Hedef:** minimum **%70** coverage (birim odaklı). Framework: **PHPUnit** (bkz. `phpunit.xml`,
in-memory SQLite). Factory'ler ile veri üretimi; HTTP `Http::fake()`, queue `Queue::fake()`,
`Cache::lock`/Redis test double'ları.

### Zorunlu senaryo matrisi

| Alan | Testler |
|---|---|
| Hash doğruluğu | Aynı veri → aynı hash; alan değişince farklı hash; normalizasyon (string vs number price, trim, unicode) → stabil hash |
| Delta sync | Yeni ürün (added), değişen (updated), değişmeyen (unchanged), silinen (soft delete), restore |
| Idempotency | Aynı job 2 kez → duplicate satır yok, sayaçlar tutarlı; yarıda kesilme sonrası tekrar |
| Retry & backoff | `tries=3`, backoff dizisi `[1,2,4]`; non-retriable hata direkt DLQ |
| Rate limiting | 5 req/s aşılmıyor; istekler arası delay; 429 → backoff + retry |
| Circuit breaker | 5 ardışık hata → sync durur (`partial`) + alert |
| Job uniqueness | Aktif sync varken ikinci dispatch engelleniyor (`409`/skip) |
| DLQ | Max retry sonrası kayıt oluşur; manuel retry re-dispatch eder; tekrar patlarsa `discarded` |
| API failure handling | timeout/5xx/malformed JSON → doğru durum, log, sayaç |
| Delete guard (E18) | Aşırı silme oranında silme iptal + alert |
| API endpoints (feature) | Her endpoint: auth, validasyon (422), pagination `meta`, response zarfı, error kodları |
| Security | Auth zorunluluğu (401/403), throttle (429), sort/search allow-list, resource'ta sızıntı yok |

Integration testler (gerçek mock API'ye karşı, opsiyonel) artı puan; CI'da `Http::fake` tercih edilir.

**Çalıştırma:** `php artisan test --compact`; tek dosya/filtre ile hedefli koşu (bkz. CLAUDE.md).

---

## 18. Kabul Kriterleri

1. `.env.example` ile 5 dakikada ayağa kalkma; README'de kurulum/çalıştırma/test adımları.
2. Scheduler + queue worker ile otomatik sync (5–10 dk); manuel trigger çalışıyor.
3. Delta sync yalnızca değişenleri günceller; added/updated/deleted/unchanged doğru raporlanır.
4. Idempotency ve job uniqueness kanıtlı (test + doküman).
5. Rate limiting, retry+exponential backoff, circuit breaker, DLQ + manuel retry çalışır.
6. 7 API endpoint'i standart zarf + pagination `meta` ile; validasyon ve hata kodları tutarlı.
7. Security kontrolleri (auth, throttle, injection/mass-assignment koruması, secret hijyeni) mevcut.
8. Dashboard: status, history (son 10), failed jobs + retry, auto-refresh, responsive.
9. ≥ %70 test coverage; zorunlu senaryo matrisi karşılanır.
10. README'de tüm teknik kararlar gerekçeleriyle: şema/index/constraint, hash stratejisi, job
    uniqueness, idempotency, rate limiting, alerting.

### Bonus (artı puan)
- Multiple provider (DummyJSON + FakeStore) — bu spec'te tasarlandı.
- Docker Compose: `app` (php-fpm+nginx), `db` (MySQL/PostgreSQL), `redis`, `queue-worker`
  container'ları; tek komutla ayağa kalkma.
- Alerting: structured log (zorunlu) + webhook notification (tercih edilen) + 5 dk debounce.
- Postman collection: `docs/postman_collection.json`.
