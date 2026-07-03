# Code Review — Veri Senkronizasyon Sistemi

Bu doküman, projenin bu oturumda yazılan implementasyonuna yapılan Staff Engineer düzeyindeki
kod incelemesinin çıktısıdır. İnceleme kapsamı: bu oturumda oluşturulan tüm implementasyon
(sync motoru, API katmanı, dashboard, Docker altyapısı, testler). Repo git deposu olmadığından
"diff" olarak oturum boyunca yazılan/değiştirilen dosyalar alınmıştır.

---

## Kullanılan Prompt

```
Review this code like a Staff Engineer.

Look for:

- Bugs
- Edge cases
- Performance
- Readability
- Maintainability
- Scalability
- Security
```

İnceleme metodolojisi (yüksek efor / recall-biased): 3 doğruluk açısı + 3 temizlik açısı +
1 altitude + 1 conventions açısından aday bulgular çıkarma, ardından her adayı doğrulama
(CONFIRMED / PLAUSIBLE / REFUTED) ve en fazla 10 bulguyu ciddiyet sırasına göre raporlama.

---

## Bulgular (ciddiyet sırasına göre)

Toplam **7 bulgu**. `CONFIRMED` = koddan kesin izlenebilir; `PLAUSIBLE` = gerçekçi ama duruma
bağlı bir yolda tetiklenir.

### 1. Docker: uygulama şifreleme anahtarı olmadan boot ediyor — KRİTİK

- **Dosya:** `docker-compose.yml:8` (ilgili: `.env.example:3`, `docker/entrypoint.sh`)
- **Kategori:** correctness · **Doğrulama:** CONFIRMED
- **Özet:** `env_file`, boş bir `APP_KEY` enjekte ediyor ve entrypoint'in ürettiği anahtarı
  geçersiz kılıyor; Dockerize uygulama şifreleme anahtarı olmadan başlıyor.
- **Senaryo:** `.env` (`.env.example`'dan kopyalanır, satır 3 `APP_KEY=`) `env_file: .env` ile
  yükleniyor ve konteyner ortamına `APP_KEY=""` enjekte ediyor. Entrypoint'teki `key:generate`
  anahtarı `.env` *dosyasına* yazıyor, ancak Laravel'in immutable Dotenv'i zaten set edilmiş boş
  ortam değişkenini override etmiyor; üstelik `config:cache` boş `app.key` değerini
  kalıcılaştırıyor. Sonuç: `docker compose up` → session/cookie/Sanctum kullanan her istek
  "No application encryption key has been specified" ile patlıyor. **Dokümante edilen 5 dakikalık
  kurulum çalışmıyor.**

### 2. Eşzamanlı `key:generate` yarışı — YÜKSEK

- **Dosya:** `docker/entrypoint.sh:7`
- **Kategori:** correctness · **Doğrulama:** CONFIRMED
- **Özet:** Aynı imajı kullanan üç konteyner (app, queue, scheduler) aynı bind-mount `.env`
  üzerinde `key:generate`'i eşzamanlı çalıştırıp read-modify-write yarışına giriyor.
- **Senaryo:** Üç servis de boş `APP_KEY` ortam değişkeniyle başladığından üçü de
  `key:generate --force` dalına giriyor ve tek host-mount `./.env`'e aynı anda yazıyor.
  İç içe geçen yazmalar dosyayı bozabilir veya konteynerleri farklı anahtarlara sabitleyerek
  servisler arası session/cookie çözme hatalarına yol açabilir.

### 3. Circuit breaker sayacı retry'lar arası kalıcı — ORTA

- **Dosya:** `app/Sync/Services/SyncOrchestrator.php:61`
- **Kategori:** correctness · **Doğrulama:** CONFIRMED
- **Özet:** Breaker hata sayacı yalnızca `force` durumunda sıfırlanıyor; job denemeleri arasında
  kalıcı olduğu için çökük bir provider'da DLQ yolu kısa devre oluyor.
- **Senaryo:** threshold=5 ve request_retries=3 ile tamamen çökük bir provider, job denemesi 1'de
  4 hata biriktiriyor (ProviderRequestException → status Failed → retry). Deneme 2'de kalıcı sayaç
  ilk istekte 5'e ulaşıyor, breaker açılıyor ve `run()` CircuitBreakerOpenException'ı yakalayıp
  koşuyu **rethrow etmeden** `partial` bitiriyor. Böylece job "başarılı" sayılıyor, `tries=3` hiç
  tükenmiyor ve **en yaygın hata senaryosunda (provider offline) dead letter queue + manuel retry
  hiç dolmuyor.**

### 4. QUEUE_BACKLOG alert'i hiç tetiklenmiyor — ORTA

- **Dosya:** `app/Sync/Services/AlertManager.php:68`
- **Kategori:** correctness · **Doğrulama:** CONFIRMED
- **Özet:** `evaluateQueueBacklog()` hiçbir yerden çağrılmıyor; QUEUE_BACKLOG alert'i asla
  çalışmıyor.
- **Senaryo:** SPEC §13, kuyruktaki job'lar eşiği (varsayılan 100) aştığında alert gerektiriyor.
  `evaluateQueueBacklog()` tanımlı ama sıfır çağırıcısı var (HealthController bir backlog sayısı
  hesaplıyor ama beslemede kullanmıyor), dolayısıyla kontrolsüz büyüyen kuyruk asla alert
  üretmiyor — ölü kod ve karşılanmamış alerting gereksinimi.

### 5. `retry` endpoint'i geçersiz payload'da 500 dönebilir — DÜŞÜK-ORTA

- **Dosya:** `app/Http/Controllers/Api/SyncController.php:149`
- **Kategori:** correctness · **Doğrulama:** PLAUSIBLE
- **Özet:** `provider_type` null ve payload'da geçerli `provider` yoksa `ProviderType::from()`
  500 üretebiliyor.
- **Senaryo:** `$provider = $deadLetter->provider_type ?? ProviderType::from($deadLetter->payload['provider'])`.
  provider_type'ı null olan (nullable kolon; SyncProviderJob dışı girişler veya elle eklenmiş
  kayıtlar için mümkün) ve payload'ında geçerli `provider` anahtarı bulunmayan bir DLQ kaydı için
  `$deadLetter->payload['provider']` null → `ProviderType::from(null)` TypeError/ValueError fırlatır
  → temiz bir 4xx yerine yakalanmamış 500.

### 6. Ürün aramasında LIKE joker karakterleri kaçırılmıyor — DÜŞÜK

- **Dosya:** `app/Http/Controllers/Api/ProductController.php:21`
- **Kategori:** security · **Doğrulama:** PLAUSIBLE
- **Özet:** `search` kullanıcı girdisini `%`/`_` kaçırmadan LIKE içine koyuyor.
- **Senaryo:** `->where('name', 'like', '%'.$v.'%')` değeri bind ediyor (SQL injection yok) ama LIKE
  metakarakterlerini kaçırmıyor. `search=%` gönderen bir istemci tüm satırları eşleştirir; çok
  sayıda `%`/`_` içeren desenler büyük katalogda pahalı tam tablo tarama zorlar — endpoint'i ucuza
  yormanın bir yolu.

### 7. `Running` update'i try/finally dışında → lock sızıntısı — DÜŞÜK

- **Dosya:** `app/Sync/Services/SyncOrchestrator.php:58`
- **Kategori:** correctness · **Doğrulama:** PLAUSIBLE
- **Özet:** Running-status update'i, lock'u serbest bırakan try/finally'den önce çalışıyor;
  buradaki bir DB hatası provider lock'unu TTL'ine kadar sızdırıyor.
- **Senaryo:** `$lock->get()` başarılı olduktan sonra `$log->update(['status' => Running])`, finally
  bloğu lock'u serbest bırakan try'ın dışında çalışıyor. Bu UPDATE fırlatırsa (geçici DB
  hatası/bağlantı kopması) exception `$lock->release()` çağrılmadan yayılır, böylece
  `sync-run:{provider}` lock'u `unique_for_seconds` (600s) süresince tutulur ve o provider'ın
  senkronizasyonlarını 10 dakikaya kadar bloklar.

---

## Genel Değerlendirme

- **En kritik açıklar (#1, #2)** aynı alt sistemde (Docker / `.env` / `APP_KEY`) ve teslimin en
  önemli riski: `docker compose up`'ı gerçekten kırıyorlar.
- Bu açıkların test paketinde yakalanmama sebebi: testler in-memory SQLite + array cache + sync
  queue ile çalışıyor ve **Docker boot yolunu hiç çalıştırmıyor.**
- Doğruluk açısından motor mantığı (delta sync, idempotency, hash, delete-guard) sağlam; başlıca
  sorunlar altyapı (Docker) ve iki davranışsal boşlukta (#3 breaker/DLQ etkileşimi, #4 wiring
  eksiği) yoğunlaşıyor.
