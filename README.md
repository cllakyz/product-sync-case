# Veri Senkronizasyon Sistemi

E-ticaret platformu için 3rd party tedarikçi API'lerinden (DummyJSON, FakeStore) ürün verilerini
periyodik olarak senkronize eden, hataya dayanıklı bir sistem. Değişiklikleri **hash tabanlı delta
sync** ile tespit eder, işleri **queue** üzerinde asenkron çalıştırır, **retry + exponential backoff
+ dead letter queue** ile hatayı yönetir ve durumu bir **dashboard** ile REST API üzerinden sunar.

- **Tam teknik tasarım:** [`SPEC.md`](SPEC.md)
- **Uygulama planı / kararlar:** [`PLAN.md`](PLAN.md)

## Stack

Laravel 13 · PHP 8.5 · MySQL 8 · Redis 7 (queue + cache + lock) · Inertia v3 + React 19 (dashboard) ·
Laravel Sanctum (API auth) · PHPUnit 12.

---

## Özellikler

- **Delta sync (hash tabanlı):** yalnızca değişen kayıtlar güncellenir; yeni/silinen/geri gelen ürünler tespit edilir.
- **Queue + Scheduler:** her aktif provider için 5 dakikada bir otomatik sync; manuel tetikleme.
- **Job uniqueness:** provider başına aynı anda tek sync (Redis lock + `ShouldBeUnique`).
- **Idempotency:** `(provider_type, external_id)` unique anahtar + transaction → tekrar çalışsa da duplicate yok.
- **Rate limiting & circuit breaker:** saniyede max 5 istek, 429/5xx için backoff, 5 ardışık hata → durdur.
- **Dead Letter Queue:** 3 denemeden sonra park; API'den manuel retry.
- **Alerting:** structured (JSON) log + audit; 5 dk debounce.
- **Güvenlik:** Sanctum auth, throttle, allow-list sort/filtre, mass-assignment & injection koruması, resource sızıntı koruması.
- **Multiple provider:** DummyJSON + FakeStore (yeni provider = 1 sınıf + config).

---

## Hızlı Kurulum (Docker) — ~5 dakika

Gereksinim: Docker + Docker Compose.

```bash
# 1) Ortam dosyası
cp .env.example .env

# 2) Tüm servisleri ayağa kaldır (app, nginx, mysql, redis, queue-worker, scheduler)
docker compose up -d --build
```

İlk açılışta `app` konteyneri otomatik olarak `APP_KEY` üretir ve migration'ları çalıştırır.
Birkaç saniye sonra uygulama hazırdır:

- **Uygulama / Dashboard:** http://localhost:8000  (dashboard için: `/sync`)
- **Health check:** http://localhost:8000/api/health

```bash
# İlk senkronizasyonu hemen tetikle (beklemeden)
docker compose exec app php artisan sync:dispatch
```

Scheduler konteyneri zaten her 5 dakikada bir sync'i tetikler; `queue` konteyneri job'ları işler.

Kapatmak için: `docker compose down` (veriyi de silmek için `-v`).

---

## Yerel Geliştirme

Bu proje yerelde yalnızca Docker Compose profiliyle çalıştırılır. `php artisan serve` bilinçli olarak
kapalıdır; uygulamayı `docker compose up -d --build` ile başlatın.

---

## Test

```bash
# İlk kurulumdan sonra dev bağımlılıkları yerelde hazır olmalı
composer install
npm ci

# PHPUnit suite
php artisan test --compact

# Hedefli test dosyası veya filtre
php artisan test --compact tests/Feature/ExampleTest.php
php artisan test --compact --filter=testName

# Coverage (pcov veya Xdebug gerekir)
php artisan test --coverage --min=70
```

90 test; unit (hash, delta, circuit breaker, rate limiter) + feature (provider mapping, orchestrator
idempotency/silme/breaker/hard-failure, DLQ, alerting, 7 API endpoint'i). Testler `database/testing.sqlite` +
array cache + sync queue ile çalışır; gerçek provider'a çıkılmaz (`Http::fake`).

Kod kalitesi:

```bash
vendor/bin/pint --test          # kod stili
vendor/bin/phpstan analyse --memory-limit=1G   # statik analiz (larastan, level 7)
npm run lint:check && npm run types:check      # frontend
```

---

## API Dokümantasyonu

Tüm uç noktalar `/api` altında ve standart zarf ile yanıt verir:

```jsonc
// başarılı
{ "success": true, "data": {}, "meta": { "page": 1, "per_page": 20, "total": 100 }, "message": "…" }
// hatalı
{ "success": false, "error": { "code": "VALIDATION_ERROR", "message": "…", "details": { } } }
```

### Kimlik doğrulama

- **Dashboard** (tarayıcı): Sanctum SPA cookie auth — otomatik.
- **Postman / dış istemci:** Bearer token gerekir. Token üretmek için:

```bash
docker compose exec app php artisan tinker --execute \
  '$u = App\Models\User::factory()->create(); echo $u->createToken("api")->plainTextToken;'
```

İstekte: `Authorization: Bearer <token>` ve `Accept: application/json`.

### Uç noktalar

| Method & Path | Açıklama | Auth |
|---|---|---|
| `POST /api/sync/trigger` | Manuel sync başlat (`{ "provider": "dummyjson" }` veya boş = tümü) | ✔ |
| `GET /api/sync/status` | Aktif sync + provider başına son tamamlanan | ✔ |
| `GET /api/sync/history` | Geçmiş sync logları (pagination: `page`, `per_page`≤100, `provider`, `status`) | ✔ |
| `GET /api/sync/failed-jobs` | Dead letter queue (pagination, `status`) | ✔ |
| `POST /api/sync/retry/{jobId}` | Başarısız job'ı yeniden kuyruğa al | ✔ |
| `GET /api/products` | Ürünler (`provider`, `search`, `min_price`, `max_price`, `in_stock`, `sort`, `direction`, pagination) | ✔ |
| `GET /api/health` | Sistem sağlığı (db/redis/queue) | Public |

Örnekler:

```bash
BASE=http://localhost:8000/api
TOKEN=... # yukarıdaki komuttan

curl $BASE/health

curl -X POST $BASE/sync/trigger -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"provider":"dummyjson"}'                       # 202 Accepted

curl "$BASE/products?provider=dummyjson&in_stock=1&per_page=20&sort=price&direction=asc" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"

curl "$BASE/sync/history?per_page=10" -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

Hata kodları: `VALIDATION_ERROR` (422), `SYNC_IN_PROGRESS` (409), `JOB_NOT_RETRIABLE` (409),
`NOT_FOUND` (404), `UNAUTHENTICATED` (401), `RATE_LIMITED` (429), `INTERNAL_ERROR` (500).

---

## Dashboard

`http://localhost:8000/sync` (giriş gerekir). Bölümler: provider başına son durum + istatistikler,
manuel trigger butonları, geçmiş (son 10), başarısız job'lar + retry. 5 saniyede bir otomatik yenilenir.
Veriyi `/api` uç noktalarından Sanctum SPA cookie ile çeker.

---

## Teknik Kararlar ve Gerekçeleri

### Veritabanı tasarımı
- `products`: **`UNIQUE(provider_type, external_id)`** — idempotency ve upsert'in temeli; duplicate insert imkânsız.
- İndeksler: `(provider_type, deleted_at)` (aktif/silinen tespiti), `(data_hash)` (hash karşılaştırma), `(last_synced_at)` (görülmeyen kayıtlar), `sync_logs(created_at)` (geçmiş sıralaması). `stock` `UNSIGNED` → DB-level `>= 0`. `price` `DECIMAL(12,2)`; negatif/overflow uygulama katmanında elenir (MySQL/SQLite taşınabilirliği).
- Soft delete (`deleted_at`): provider'dan kalkan ürün silinmez, işaretlenir; geri gelirse restore edilir.

### Hash hesaplama stratejisi
- SHA-256; yalnızca **iş alanları**: `name`, `price`, `stock`, `description` (kimlik/timestamp hariç).
- Deterministik normalizasyon: trim + iç boşluk teklileştirme + Unicode NFC; fiyat her zaman 2 ondalık string (`"19.99"`), böylece `19.99` vs `"19.99"` aynı hash; `null` stok için sabit sentinel. Kod: `app/Sync/Services/ProductHasher.php`.

### Job uniqueness
- İki katman: dispatch anında `ShouldBeUnique` (Redis) + execution anında `Cache::lock("sync-run:{provider}")`. Race condition'a kapalı, atomik. Lock TTL ile worker ölse de deadlock olmaz. API ayrıca aktif `SyncLog` varsa `409 SYNC_IN_PROGRESS` döner.

### Idempotency
- `updateOrCreate`/upsert `(provider_type, external_id)` üzerinden + her ürün kısa transaction içinde. Aynı job iki kez çalışsa da (yarıda kesilme sonrası dahil) duplicate oluşmaz; değişmeyen kayıt yalnızca `last_synced_at` günceller, `updated_at`'e dokunmaz.

### Rate limiting yaklaşımı
- Saniyede max 5 istek: istekler arası `1000/max_rps` ms aralık (`app/Sync/Services/RateLimiter.php`). 429'da `Retry-After`/exponential backoff + jitter; 5xx retriable. **Circuit breaker**: 5 ardışık hata → sync durur, `partial` + `API_FAILURE` alert. Gecikmeler enjekte edilebilir `Sleeper` üzerinden (test edilebilir).

### Silme koruması (delete guard)
- Bir koşuda ürünlerin %50'sinden fazlası silinecekse silme **iptal** edilir (provider arızasının katalogu silmesini engeller); koşu `partial` + alert. Partial koşularda silme adımı atlanır.

### Alerting
- `SYNC_FAILURE` (3 ardışık başarısız sync), `DLQ_THRESHOLD` (>10 bekleyen), `API_FAILURE` (breaker), `QUEUE_BACKLOG` (>100). Structured JSON log + `sync_alerts` audit; aynı alert 5 dakikada 1 kez (Redis debounce). Webhook (Slack/Discord) bildirimi **ertelendi**; arayüz hazır.

### Güvenlik
- Sanctum auth (health hariç); durum değiştiren uçlarda daha katı throttle. Eloquent binding (SQL injection yok), `sort`/filtre allow-list. `$fillable` whitelist + DTO (mass-assignment yok); provider verisi persist öncesi doğrulanır/temizlenir. API Resource'lar `data_hash`/`raw_payload`/tam trace sızdırmaz. Provider URL'leri yalnızca config'ten (SSRF yüzeyi yok). Secret'lar `.env`'de; DLQ payload/exception sanitize edilir.

Ayrıntılar için [`SPEC.md`](SPEC.md) (§4 veri modelleri, §5 hash, §6 motor, §7 rate limiting, §10 validasyon, §11 security, §12 edge case'ler).

---

## Konfigürasyon

Tüm ayarlar `config/sync.php` içinde ve `.env` üzerinden yönetilir (bkz. `.env.example`): sync aralığı,
`SYNC_MAX_RPS`, retry/backoff, breaker eşiği, `SYNC_DELETE_GUARD_RATIO`, `SYNC_MISSING_STOCK_POLICY`,
provider URL/aktiflik, alert eşikleri, API throttle.

## Kapsam Notu

Bu teslim **core** odaklıdır. Uygulanan bonuslar: **Multiple provider** (DummyJSON + FakeStore) ve
**Docker** (tek komutla ayağa kalkma). **Ertelenenler:** webhook notification kanalı (structured-log
alerting core'da mevcut) ve Postman collection.
