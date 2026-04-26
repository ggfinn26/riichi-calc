# Ringkasan Pekerjaan — Riichi Mahjong Calculator

**Proyek:** `riichi-calc` (XAMPP: `/htdocs/riichi-calc`)  
**Namespace:** `Dewa\Mahjong\` → `src/`  
**Autoload:** Composer PSR-4

---

## Apa yang Sudah Dikerjakan

### 1. Controller (CalculatorController)
`src/Controller/CalculatorController.php`

Dibuat dari scratch (file sebelumnya kosong). Berisi 9 endpoint:

| Method | Route | Action |
|---|---|---|
| POST | `/api/score/calculate` | Hitung skor (han/fu/payment) |
| POST | `/api/score/evaluate-yaku` | Deteksi yaku dari tangan |
| GET | `/api/game/{id}` | Ambil state GameContext |
| GET | `/api/hand/{id}` | Ambil tiles, melds, waits |
| POST | `/api/action/draw` | Pemain tarik batu |
| POST | `/api/action/discard` | Pemain buang batu |
| POST | `/api/action/riichi` | Deklarasi Riichi |
| POST | `/api/action/call` | Chi / Pon / Kan |
| POST | `/api/round/resolve` | Selesaikan ronde |
| GET | `/api/health` | Health check |

Controller menerima `Request $request` + optional route params (e.g. `string $id`), membuat DTO untuk validasi, memanggil service, lalu output via `Response::json()`. Menggunakan PSR-3 `LoggerInterface`.

---

### 2. HTTP Layer
`src/Http/`

- **`Request.php`** — Wrapper thin di atas `$_SERVER` + `php://input`. Method: `getBody()`, `getMethod()`, `getPath()`, `getHeader()`, `getIp()`, `input()`.
- **`Response.php`** — Static helper. Method: `json()`, `error()`, `validationError()`. Semua set HTTP status + `Content-Type: application/json`.

---

### 3. Middleware Stack
`src/Http/Middleware/`

- **`MiddlewareInterface.php`** — Kontrak: `handle(Request $request): void`
- **`CorsMiddleware.php`** — Set header CORS; handle preflight OPTIONS → 204 exit. Konfigurasi via env `CORS_*`.
- **`RateLimitMiddleware.php`** — File-based, 60 req/menit per IP. Counter disimpan di `storage/rate_limit/*.json`. Balas 429 jika terlampaui.
- **`AuthMiddleware.php`** — Bearer token auth. **Dinonaktifkan default** (`AUTH_ENABLED=false`). Support strategi `static` (bandingkan `AUTH_STATIC_TOKEN`) dan placeholder `db`.

---

### 4. DTO Validation
`src/DTO/`

Tiap DTO memiliki static factory `fromArray(array $data): self` yang memvalidasi dan melempar `\InvalidArgumentException` jika invalid.

| DTO | Validasi Kunci |
|---|---|
| `CalculateScoreRequest` | `winning_tile_id` 1–34, `yaku_ids` min 1 item, `dora_count` 0–12, `pao_yakuman_type` enum |
| `EvaluateYakuRequest` | `winning_tile_id` 1–34, booleans |
| `DrawActionRequest` | `tile_id` 1–34 |
| `DiscardActionRequest` | `tile_id` 1–34, `is_tsumogiri` bool |
| `CallActionRequest` | `meld_type` enum (chi/pon/daiminkan/ankan/shouminkan), `tile_ids_from_hand` 2–3 items |
| `RiichiActionRequest` | `hand_id` + `discard_action_id` positif |
| `ResolveRoundRequest` | `end_type` enum (agari/ryuukyoku/chombo/suukaikan), `winner_user_id` wajib jika agari |

---

### 5. Exception Handler
`src/Exception/ExceptionHandler.php`

Global handler: `set_exception_handler` + `set_error_handler` + `register_shutdown_function`.

| Exception | HTTP Status |
|---|---|
| `\InvalidArgumentException` | 422 Unprocessable Entity |
| `\RuntimeException` / `\LogicException` | 400 Bad Request |
| Fatal / Unexpected `\Throwable` | 500 + log via Monolog |

Di mode `APP_ENV=development` → tampilkan detail error. Di production → pesan generik.

---

### 6. Logger
`src/Infrastructure/Logger.php`

Singleton Monolog. Konfigurasi via `.env`:
- `LOG_LEVEL` (default: `debug`)
- `LOG_PATH` (default: `storage/logs/app.log`)

Processor: `WebProcessor` (IP/method/URI) + `IntrospectionProcessor` (file/line). Mendukung `RotatingFileHandler` (30 hari) jika tersedia.

---

### 7. Config Files

**`config/routes.php`**
FastRoute `simpleDispatcher`. Semua route di-group `/api`. Mengembalikan instance `FastRoute\Dispatcher`.

**`config/container.php`**
PHP-DI `ContainerBuilder` dengan auto-wiring aktif. Mendefinisikan:
- `PDO::class` → factory (baca `DB_*` dari env)
- `LoggerInterface::class` → `Logger::get()`
- Semua `RepositoryInterface::class` → `autowire(ConcreteRepository::class)`

**`config/app.php`** *(sebelumnya kosong, sekarang berisi bootstrap manual — superseded oleh container.php)*

---

### 8. Entry Point
**`public/index.php`** — Alur:
```
autoload
→ ExceptionHandler::register()
→ new Request()
→ CorsMiddleware → RateLimitMiddleware → AuthMiddleware
→ PHP-DI Container (dari config/container.php)
→ FastRoute Dispatcher (dari config/routes.php)
→ $container->get($controllerClass)->$method($request, ...$routeVars)
```

---

### 9. Storage & Env
- `storage/logs/.gitkeep` — direktori log
- `storage/rate_limit/.gitkeep` — direktori counter rate limiter
- `.env.example` — semua key terdokumentasi (`DB_*`, `LOG_*`, `CORS_*`, `RATE_LIMIT_*`, `AUTH_*`)

---

### 10. Dokumentasi API
**`documentation/openapi.yaml`** — OpenAPI 3.1 lengkap:
- Semua 9 endpoint + health check
- Schema: `TileSummary`, `YakuSummary`, `Payment`, `ScoreResult`, `Error`, `ValidationError`
- Request body + response per endpoint
- Bisa dibuka di Swagger UI / Redocly

---

## Dependency yang Diinstall

```json
"require": {
  "vlucas/phpdotenv": "^5.6",
  "nikic/fast-route": "^1.3",
  "php-di/php-di": "^7.0",
  "monolog/monolog": "^3.0"
}
```

---

## Arsitektur Layer (Alur Request)

```
Browser / Frontend
    │
    ▼
public/index.php
    │
    ├─ ExceptionHandler (global catch)
    ├─ CorsMiddleware
    ├─ RateLimitMiddleware
    ├─ AuthMiddleware (optional)
    │
    ▼
FastRoute Dispatcher
    │
    ▼
PHP-DI Container → CalculatorController
    │
    ├─ DTO::fromArray() → validasi input
    ├─ Repository (PDO via container)
    └─ Service (ScoringService / YakuEvaluator / PlayerActionService / GameProgressionService)
         │
         ▼
    Response::json([...])
```

---

## Domain Layer (Sudah Ada Sebelumnya)

| Komponen | File |
|---|---|
| **Entity** | `Hand`, `Tile`, `Meld`, `GameContext`, `DiscardedPile`, `Yaku`, `CustomYaku`, `User` |
| **Repository** | Interface + Concrete untuk semua entity |
| **Service/Calculation** | `ScoringService`, `YakuEvaluator`, `FuCalculator`, `HanCounter`, `ScoreCalculator`, `WaitCalculator`, `FuritenChecker` |
| **Service/GameFlow** | `PlayerActionService`, `GameProgressionService` |
| **Service/Recommendation** | `ShantenCalculator`, `DiscardRecommendationService`, dll |

---

## Status Saat Ini

✅ **Siap dijalankan** — tinggal:
1. `cp .env.example .env` dan isi nilai DB
2. Pastikan database + tabel sudah ada
3. Akses via `http://localhost/riichi-calc/public/api/health`

❌ **Belum ada:**
- Database migrations (Phinx) — tabel dibuat manual
- Unit test untuk layer baru (Http, DTO, Exception)
- Frontend / Views
