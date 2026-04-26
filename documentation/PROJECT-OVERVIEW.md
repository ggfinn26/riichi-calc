# Riichi Mahjong Calculator & AI Assistant - Project Overview

## Visi Proyek

Membangun sebuah mesin (*backend engine*) Riichi Mahjong berbasis PHP murni yang tidak hanya berfungsi sebagai pencatat skor atau kalkulator pasif, tetapi juga memiliki kemampuan AI (*Artificial Intelligence*) untuk memberikan rekomendasi keputusan (Nani Kiru) layaknya pemain profesional.

Arsitektur dibangun menggunakan prinsip **Domain-Driven Design (DDD)** dan **Clean Architecture** untuk memastikan skalabilitas, performa tinggi, dan kode yang mudah diuji (*testable*).

---

## Stack Teknologi

| Komponen   | Detail                                          |
| ---------- | ----------------------------------------------- |
| Bahasa     | PHP 8.2.4                                       |
| Database   | MariaDB 10.4.28                                 |
| Web Server | XAMPP (Apache)                                  |
| Namespace  | `Dewa\Mahjong`                                |
| Autoload   | Composer PSR-4                                  |
| Arsitektur | Domain-Driven Design (DDD) + Clean Architecture |

---

## Peta Arsitektur Keseluruhan

```
src/
├── Entity/          ← Fase 1: "Hukum Fisika" (SELESAI)
├── Repository/      ← Fase 2: "Gudang Penyimpanan" (SELESAI)
├── Service/         ← Fase 3: "Sang Otak" (IN PROGRESS)
│   ├── Calculation/
│   ├── GameFlow/       (belum dibuat)
│   └── Recommendation/ (belum dibuat)
├── Controller/      ← Fase 4: "Pintu Masuk" (belum dibuat)
└── Utils/
```

---

## Fase 1: Pemodelan "Hukum Fisika" (Entity Layer)

**Status: SELESAI 100%**

Seluruh benda fisik dan aturan Mahjong dipetakan ke dalam objek *Entity* yang kaya (*Rich Domain Model*). Objek-objek ini tidak hanya menyimpan data, tetapi juga menjaga aturan permainan.

### Daftar Entity

| Entity            | File                             | Peran                                                |
| ----------------- | -------------------------------- | ---------------------------------------------------- |
| `Tile`          | `src/Entity/Tile.php`          | Representasi 1 dari 34 jenis batu Mahjong            |
| `Yaku`          | `src/Entity/Yaku.php`          | Kamus pola kemenangan standar (readonly)             |
| `CustomYaku`    | `src/Entity/CustomYaku.php`    | *House Rules* yang bisa dibuat oleh User           |
| `User`          | `src/Entity/User.php`          | Pemain/Pengguna sistem                               |
| `Hand`          | `src/Entity/Hand.php`          | Tangan 13-14 batu milik satu pemain                  |
| `Meld`          | `src/Entity/Meld.php`          | Set batu terbuka (Chi/Pon/Kan)                       |
| `DiscardedPile` | `src/Entity/DiscardedPile.php` | Riwayat buangan satu pemain                          |
| `GameContext`   | `src/Entity/GameContext.php`   | **Aggregate Root** — pusat komando satu ronde |

### Pencapaian Kunci Entity

- `GameContext` akan melempar *error* jika pemain mencoba melakukan Kan ke-5 (Suukaikan, melanggar batas 4 Kan per meja).
- `Hand` akan menolak deklarasi Riichi jika pemain sudah membuka batu (Pon/Chi).
- `CustomYaku` mendukung *soft-delete* (`is_deleted`) agar riwayat tidak terhapus permanen.

---

## Fase 2: Infrastruktur & Optimalisasi (Database & Repository Layer)

**Status: SELESAI 100%**

### Skema Database (`riichi-calc`)

Database terdiri dari **12 tabel** yang dioptimalkan untuk pencatatan *turn-by-turn* dan *replay*.

#### Diagram Relasi Tabel

```
games
 └── game_contexts (N per game)
      ├── game_context_dora_indicators  (Dora indicator per ronde)
      ├── game_context_custom_yakus     (House Rules aktif per ronde)
      ├── hands (1 per pemain per ronde)
      │    ├── hand_tiles               (Batu fisik di tangan)
      │    └── melds                   (Set terbuka)
      │         └── meld_tiles         (Batu penyusun set)
      └── discard_actions             (Log setiap buangan)

tiles          ← Master data 34 jenis batu (statis, di-seed)
yakus          ← Master data Yaku standar (statis)
users          ← Akun pemain
custom_yakus   ← House Rules buatan user
```

#### Detail Tabel

**`games`** — Satu sesi permainan penuh (Tonpuusen/Hanchan)

```
id | created_at | ended_at | status (active/finished/abandoned)
```

**`game_contexts`** — Satu ronde (contoh: Timur 1, Timur 2, dst.)

```
id | game_id | round_number | round_wind (east/south/west/north)
   | dealer_id | current_turn_user_id | status (active/finished/draw)
   | honba | riichi_sticks | kan_count | left_wall_tiles | next_turn_order_index
```

**`game_context_dora_indicators`** — Batu penunjuk Dora aktif pada ronde

```
id | game_context_id | tile_id | order_index
```

Catatan: `order_index` + UNIQUE KEY memastikan Dora tidak terduplikasi. Bisa menampung hingga 5 Dora Indicator (4 dari Kan + 1 awal).

**`game_context_custom_yakus`** — Pivot: House Rules yang aktif di ronde

```
game_context_id | custom_yaku_id  ← Composite Primary Key
```

**`hands`** — Tangan pemain di satu ronde (UNIQUE per user per game_context)

```
id | game_context_id | user_id | is_dealer
   | is_riichi_declared | riichi_discard_action_id (FK → discard_actions)
   | nagashi_mangan_discard_id (FK → discard_actions, nullable)
```

Catatan: `riichi_discard_action_id` menyimpan aksi buangan pemicu Riichi. `nagashi_mangan_discard_id` mencatat referensi untuk pengecekan Nagashi Mangan.

**`hand_tiles`** — Batu fisik di tangan (kondisi real-time)

```
id | hand_id | tile_id | order_index
```

**`melds`** — Set batu terbuka (Chi/Pon/Kan)

```
id | game_context_id | user_id | hand_id
   | type (chi/pon/ankan/daiminkan/shouminkan) | is_closed
```

Catatan: `ankan` adalah Kan tertutup (is_closed=1), `daiminkan` adalah Kan terbuka dari lawan, `shouminkan` adalah menambah batu ke Pon yang sudah ada.

**`meld_tiles`** — Batu penyusun satu set/meld

```
id | meld_id | tile_id | order_index  ← UNIQUE (meld_id, order_index)
```

**`discard_actions`** — Log setiap buangan (urut secara global per ronde)

```
id | game_context_id | user_id | tile_id
   | turn_order | is_riichi_declare | is_tsumogiri | order_index
```

Catatan: `is_tsumogiri` = true jika batu yang dibuang adalah batu yang baru saja ditarik (penting untuk logika Furiten dan AI). `turn_order` adalah urutan global di seluruh meja.

**`tiles`** — Master data 34 jenis batu (di-seed, tidak berubah)

```
id (1-34) | name | value | unicode | type (man/pin/sou/honor) | colors (JSON array)
```

Catatan: Kolom `colors` bertipe `longtext` dengan constraint `json_valid()`, menyimpan array warna batu, contoh: `["red","black"]`. Haku (batu putih) tidak memiliki warna sehingga nilainya `[]`.

Data seed lengkap tersedia di `database/riichi-calc.sql`.

| ID    | Kelompok        | Batu                                      |
| ----- | --------------- | ----------------------------------------- |
| 1-9   | Man (Karakter)  | 1m–9m                                    |
| 10-18 | Pin (Lingkaran) | 1p–9p                                    |
| 19-27 | Sou (Bambu)     | 1s–9s                                    |
| 28-31 | Angin (Honor)   | East, South, West, North                  |
| 32-34 | Naga (Honor)    | Haku (Putih), Hatsu (Hijau), Chun (Merah) |

**`users`** — Akun pemain

```
id | username (UNIQUE) | email (UNIQUE) | password_hash
   | created_at | updated_at | is_deleted (soft-delete)
```

**`yakus`** — Master data Yaku standar Riichi Mahjong

```
id | name_jp | name_eng | description | han_closed | han_opened | is_yakuman
```

**`custom_yakus`** — House Rules buatan user

```
id | name | description | han_closed | han_opened | is_yakuman
   | conditions (JSON) | created_by_user_id | is_deleted (soft-delete)
   | created_at | updated_at
```

Catatan: Kolom `conditions` bertipe `longtext` dengan constraint `json_valid()` untuk memastikan integritas data kondisi evaluasi yaku kustom.

### Daftar Repository

| Repository                  | Interface                            | Tabel Utama                        |
| --------------------------- | ------------------------------------ | ---------------------------------- |
| `GameContextRepository`   | `GameContextRepositoryInterface`   | `game_contexts` + semua subtabel |
| `HandRepository`          | `HandRepositoryInterface`          | `hands`, `hand_tiles`          |
| `MeldRepository`          | `MeldRepositoryInterface`          | `melds`, `meld_tiles`          |
| `DiscardedPileRepository` | `DiscardedPileRepositoryInterface` | `discard_actions`                |
| `TileRepository`          | `TileRepositoryInterface`          | `tiles`                          |
| `YakuRepository`          | `YakuRepositoryInterface`          | `yakus`                          |
| `UserRepository`          | `UserRepositoryInterface`          | `users`                          |
| `CustomYakuRepository`    | `CustomYakuRepositoryInterface`    | `custom_yakus`                   |

### Pencapaian Kunci Repository

1. **Anti N+1 Query Problem:** `HandRepository` dan `MeldRepository` menggunakan teknik *Batch Fetching* (klausa `IN`) dan *Grouping* di PHP untuk menarik data seluruh pemain hanya dengan 1-2 *query*.
2. **Deep Hydration:** `GameContextRepository` merakit pecahan data dari banyak tabel menjadi satu objek `GameContext` yang utuh (berisi pemain, batu, dan sampah) secara transparan.
3. **Nested-Transaction-Aware:** Repositori anak (`HandRepo`, `MeldRepo`) bisa menumpang pada transaksi induk (`GameContextRepo`) menggunakan mekanisme ACID untuk mencegah data korup jika server mati mendadak.

---

## Fase 3: Desain "Sang Otak" (Service Layer)

**Status: IN PROGRESS — Implementasi Awal `YakuEvaluator`**

Layer Service dibagi menjadi 3 sub-direktori berdasarkan kategori:

```
src/Service/
├── Calculation/          ← A. Kalkulator Matematis (Stateless)
├── GameFlow/             ← B. Manajer Alur Permainan (Belum dibuat)
└── Recommendation/       ← C. Sistem Rekomendasi AI (Belum dibuat)
```

### A. Kalkulator Matematis (`src/Service/Calculation/`)

Service bersifat *stateless*: menerima data, memproses pola, mengembalikan hasil — tidak mengubah database secara langsung.

| Service             | Status        | Fungsi Utama                                   |
| ------------------- | ------------- | ---------------------------------------------- |
| `YakuEvaluator`   | Kerangka awal | Mendeteksi Yaku yang valid dari 14 batu        |
| `ScoreCalculator` | File kosong   | Menghitung Han, Fu, distribusi bayar Tsumo/Ron |
| `WaitCalculator`  | Belum dibuat  | Algoritma pencari Machi (batu tunggu Tenpai)   |
| `FuritenChecker`  | File kosong   | Mendeteksi Furiten permanen, sementara, Riichi |

**Dependency `YakuEvaluator`:**

```
YakuEvaluator
  ├── YakuRepositoryInterface    (Yaku standar dari DB)
  ├── CustomYakuRepositoryInterface (House Rules aktif di ronde)
  ├── HandRepositoryInterface
  └── MeldRepositoryInterface
```

**Signature method utama:**

```php
evaluate(Hand $hand, Tile $winningTile, GameContext $context, bool $isTsumo): array
// Return: ['yakus' => Yaku[], 'total_han' => int]
```

### B. Manajer Alur Permainan (`src/Service/GameFlow/`) — Belum Dibuat

| Service                    | Fungsi Utama                                                                                      |
| -------------------------- | ------------------------------------------------------------------------------------------------- |
| `PlayerActionService`    | Orkestrator aksi pemain (Draw, Discard, Call, Riichi). Menjembatani Controller dengan Repository. |
| `GameProgressionService` | Mengatur transisi ronde: Agari, Ryuukyoku, rotasi Dealer, inisialisasi meja baru.                 |

### C. Sistem Rekomendasi AI (`src/Service/Recommendation/`) — Belum Dibuat

| Service                          | Fungsi Utama                                                                                            |
| -------------------------------- | ------------------------------------------------------------------------------------------------------- |
| `ShantenCalculator`            | Menghitung jarak minimum ke Tenpai (Standard, Chiitoitsu, Kokushi)                                      |
| `VisibleTileTrackerService`    | Menghitung sisa batu yang belum terlihat di meja untuk probabilitas akurat                              |
| `DefenseEvaluatorService`      | Menilai tingkat bahaya buangan: Genbutsu (0%), Suji, Kabe, Live Tile                                    |
| `ExpectedValueCalculator`      | Mensimulasikan EV (Expected Value) skor jika menang dengan tangan tertentu                              |
| `DiscardRecommendationService` | **Konduktor AI utama** — menggabungkan Speed + Defense + Value untuk rekomendasi buangan optimal |

**Alur kerja `DiscardRecommendationService`:**

```
Untuk setiap batu di tangan:
  1. Simulasikan pembuangan batu X
  2. ShantenCalculator  → Shanten & Ukeire (Kecepatan)
  3. VisibleTileTracker → Ukeire Realistis (batu berguna yang benar-benar tersisa)
  4. DefenseEvaluator   → Danger Level (Keamanan)
  5. ExpectedValueCalc  → EV Skor (Nilai)
  → Urutkan dan kembalikan rekomendasi terbobot
```

---

## Fase 4: Controller Layer

**Status: Belum Dimulai**

`src/Controller/CalculatorController.php` sudah ada sebagai placeholder kosong. Controller bertugas menerima request HTTP, memanggil Service yang sesuai, dan mengembalikan response JSON ke frontend/klien.

---

## Prinsip Coding yang Diterapkan

1. **Strict Typing:** `declare(strict_types=1)` di setiap file.
2. **Dependency Injection:** Constructor injection via Interface — tidak ada `new` di dalam method.
3. **Statelessness:** Service kalkulasi tidak menyimpan *state* di properti class.
4. **Single Responsibility:** Satu class, satu tanggung jawab.
5. **Interface Segregation:** Setiap Repository punya Interface-nya sendiri untuk kemudahan pengujian (*mocking*).
6. **Soft Delete:** Tabel `users` dan `custom_yakus` menggunakan `is_deleted` agar data historis tetap terjaga.

---

## Tantangan Teknis Utama Saat Ini

**Hand Parser / Backtracking Algorithm**

Pondasi dari seluruh Fase 3 adalah algoritma yang membelah 14 batu di tangan pemain menjadi kombinasi valid **(4 Set + 1 Pair)**. Tanpa algoritma ini, deteksi Yaku kompleks seperti *Pinfu*, *Iipeikou*, *Sanankou*, maupun kalkulasi *Shanten* tidak bisa diimplementasikan.

Algoritma ini harus menangani:

- Susunan reguler: 4 set (Shuntsu/Koutsu) + 1 pair
- Chiitoitsu: 7 pair berbeda
- Kokushi Musou: 13 jenis batu terminal/honor unik + 1 duplikat
- Tangan dengan Meld terbuka (jumlah batu fisik di tangan berkurang)

---

## Langkah Selanjutnya (Roadmap)

| Prioritas | Task                                                             |
| --------- | ---------------------------------------------------------------- |
| 1         | Implementasi Hand Parser / Backtracking di `YakuEvaluator`     |
| 2         | Implementasi `ShantenCalculator` (bergantung pada Hand Parser) |
| 3         | Implementasi `WaitCalculator` (bergantung pada Shanten)        |
| 4         | Implementasi `FuritenChecker` (bergantung pada WaitCalculator) |
| 5         | Implementasi `ScoreCalculator` (Han/Fu → poin)                |
| 6         | Bangun `PlayerActionService` dan `GameProgressionService`    |
| 7         | Bangun sistem Rekomendasi AI (`Recommendation/`)               |
| 8         | Implementasi `CalculatorController` dan API endpoint           |
