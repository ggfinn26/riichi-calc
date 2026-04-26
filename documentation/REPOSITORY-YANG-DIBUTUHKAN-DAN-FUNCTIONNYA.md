# REPOSITORY YANG DIBUTUHKAN DAN FUNCTION-NYA

Dokumen ini menjelaskan repository apa saja yang dibutuhkan oleh project Riichi Mahjong Calculator,
hasil analisis dari `src/Entity/` dan `database/schema.sql`.

---

## Ringkasan Cepat

| Entity              | Butuh Repository? | Alasan                                                                                          |
|---------------------|:-----------------:|-------------------------------------------------------------------------------------------------|
| `Tile`              | YA (sudah ada)    | Data referensi statis (34 batu). Di-FK oleh banyak tabel lain.                                  |
| `Yaku`              | YA                | Data referensi statis (yaku standar). Dipakai untuk display & perhitungan han.                  |
| `User`              | YA                | CRUD + auth (login, register), audit trail, soft-delete.                                        |
| `CustomYaku`        | YA                | CRUD konten user, toggle aktif/nonaktif, perlu filter per user.                                 |
| `GameContext`       | YA                | Persistensi ronde permainan, query rekap skor, relasi ke games/users/tiles.                     |
| `Hand`              | YA                | Snapshot tangan per pemain per ronde, junction ke `hand_tiles` dan `melds`.                     |
| `Meld`              | YA                | History meld (chi/pon/kan), junction ke `meld_tiles`.                                           |
| `DiscardedPile`     | YA                | History buangan permanen, dibutuhkan untuk Nagashi Mangan & replay.                             |

> **Catatan:** Tabel `games` di schema belum punya entity di `src/Entity/`.
> Direkomendasikan membuat `Game` entity + `GameRepository` (lihat catatan di bagian akhir).

---

## 1. TileRepository (sudah ada)

**Entity:** `Dewa\Mahjong\Entity\Tile`
**Tabel:** `tiles`
**Sifat:** Read-only (data di-seed sekali, tidak pernah berubah).

### Function yang dibutuhkan

| Method                                    | Tujuan                                                                  |
|-------------------------------------------|-------------------------------------------------------------------------|
| `findById(int $id): ?Tile`                | Ambil 1 batu by id (1–34). Dipakai saat hidrasi dari FK tile_id.        |
| `findByName(string $name): ?Tile`         | Ambil batu by nama (mis. `'chun'`, `'1-man'`).                          |
| `findAll(): Tile[]`                       | Ambil 34 batu sekaligus (initial load / cache).                         |
| `findByType(string $type): Tile[]`        | Filter by `'man'/'pin'/'sou'/'honor'`. Penting untuk Honitsu/Chinitsu.  |
| `findByColor(string $color): Tile[]`      | Filter by label warna UI.                                               |

> Status: `TileRepository.php` & `TileRepositoryInterface.php` **sudah dibuat**.

---

## 2. YakuRepository

**Entity:** `Dewa\Mahjong\Entity\Yaku`
**Tabel:** `yakus`
**Sifat:** Read-only (data baku Riichi).

### Function yang dibutuhkan

| Method                                     | Tujuan                                                                |
|--------------------------------------------|-----------------------------------------------------------------------|
| `findById(int $id): ?Yaku`                 | Ambil yaku by id, untuk hidrasi hasil kalkulasi.                      |
| `findByNameJp(string $nameJp): ?Yaku`      | Cari yaku via nama Jepang (mis. `'riichi'`, `'pinfu'`).               |
| `findByNameEng(string $nameEng): ?Yaku`    | Cari yaku via nama Inggris.                                           |
| `findAll(): Yaku[]`                        | Daftar lengkap yaku standar.                                          |
| `findAllYakuman(): Yaku[]`                 | Filter `is_yakuman = TRUE`. Berguna untuk evaluator yakuman.          |
| `findClosedOnly(): Yaku[]`                 | Yaku yang hanya valid saat tangan menzen (`han_opened = 0`).          |

---

## 3. UserRepository

**Entity:** `Dewa\Mahjong\Entity\User`
**Tabel:** `users`
**Sifat:** Full CRUD + soft delete + auth lookup.

### Function yang dibutuhkan

| Method                                          | Tujuan                                                                 |
|-------------------------------------------------|------------------------------------------------------------------------|
| `findById(int $id): ?User`                      | Ambil user by id (FK dari hampir semua tabel game).                    |
| `findByEmail(string $email): ?User`             | Login & cek duplikat saat register.                                    |
| `findByUsername(string $username): ?User`       | Cek unique username, login alternatif.                                 |
| `findAllActive(): User[]`                       | List user yang `is_deleted = FALSE`.                                   |
| `save(User $user): User`                        | INSERT (id == 0) atau UPDATE (id > 0). Mengembalikan entity ber-id.    |
| `softDelete(int $id): void`                     | Set `is_deleted = TRUE` + `updated_at`. Tidak menghapus baris fisik.   |
| `existsByEmail(string $email): bool`            | Helper validasi register cepat tanpa hidrasi.                          |
| `existsByUsername(string $username): bool`      | Helper validasi register cepat tanpa hidrasi.                          |

---

## 4. CustomYakuRepository

**Entity:** `Dewa\Mahjong\Entity\CustomYaku`
**Tabel:** `custom_yakus` (dengan `conditions JSON`).
**Sifat:** Full CRUD + toggle + filter per pembuat.

### Function yang dibutuhkan

| Method                                                | Tujuan                                                                  |
|-------------------------------------------------------|-------------------------------------------------------------------------|
| `findById(int $id): ?CustomYaku`                      | Hidrasi by id (FK dari `game_context_custom_yakus`).                    |
| `findAllActive(): CustomYaku[]`                       | Daftar yaku custom yang siap dipakai (`is_active = TRUE`).              |
| `findByCreatedByUserId(int $userId): CustomYaku[]`    | Daftar yaku milik 1 user (untuk halaman manage).                        |
| `findByGameContextId(int $gameContextId): CustomYaku[]` | Yaku custom yang aktif di 1 ronde (lewat junction).                   |
| `save(CustomYaku $yaku): CustomYaku`                  | INSERT/UPDATE. Serialisasi `conditions` ke kolom JSON.                  |
| `delete(int $id): void`                               | Hapus permanen (atau bisa diubah jadi soft delete jika diperlukan).     |
| `toggleActive(int $id): void`                         | Optional shortcut menyalakan/mematikan flag `is_active`.                |

> Hidrasi harus `json_decode` kolom `conditions` ke array PHP, dan `json_encode` saat save.

---

## 5. GameContextRepository

**Entity:** `Dewa\Mahjong\Entity\GameContext`
**Tabel:** `game_contexts` + junction (`game_context_dora_indicators`, `game_context_custom_yakus`).
**Sifat:** Aggregate root untuk 1 ronde — perlu memuat sub-collection.

> **Penting:** `GameContext` punya array `doraIndicators`, `discardPile`, `hands`, `activeCustomYakus`.
> State **real-time** disimpan di Redis/Session. Repository hanya untuk **flush** & **load** ronde yang sudah selesai.

### Function yang dibutuhkan

| Method                                                          | Tujuan                                                                         |
|-----------------------------------------------------------------|--------------------------------------------------------------------------------|
| `findById(int $id): ?GameContext`                               | Ambil 1 ronde lengkap dengan dora, hands, discards.                            |
| `findByGameId(int $gameId): GameContext[]`                      | Semua ronde dari 1 sesi game (untuk rekap skor).                               |
| `findActiveByGameId(int $gameId): ?GameContext`                 | Ronde yang sedang berjalan (`status = 'active'`).                              |
| `save(GameContext $ctx): GameContext`                           | INSERT/UPDATE row utama + sinkron dora indicators + custom yaku junction.      |
| `addDoraIndicator(int $gameContextId, int $tileId, int $orderIndex): void` | Insert ke `game_context_dora_indicators`.                           |
| `attachCustomYaku(int $gameContextId, int $customYakuId): void` | Insert ke `game_context_custom_yakus`.                                         |
| `detachCustomYaku(int $gameContextId, int $customYakuId): void` | Hapus dari junction.                                                           |
| `markFinished(int $id, string $status): void`                   | Update status ke `'finished'` atau `'draw'`.                                   |

---

## 6. HandRepository

**Entity:** `Dewa\Mahjong\Entity\Hand`
**Tabel:** `hands` + junction `hand_tiles`.
**Sifat:** Aggregate child dari GameContext, tetap perlu repository karena dipanggil per pemain.

### Function yang dibutuhkan

| Method                                                              | Tujuan                                                                  |
|---------------------------------------------------------------------|-------------------------------------------------------------------------|
| `findById(int $id): ?Hand`                                          | Ambil 1 tangan beserta tiles + melds.                                   |
| `findByGameContextId(int $gameContextId): Hand[]`                   | 4 tangan dalam 1 ronde.                                                 |
| `findByGameContextAndUser(int $gameContextId, int $userId): ?Hand`  | Tangan spesifik 1 pemain di 1 ronde (UNIQUE di schema).                 |
| `save(Hand $hand): Hand`                                            | INSERT/UPDATE row utama + sinkron `hand_tiles`.                         |
| `replaceTiles(int $handId, Tile[] $tiles): void`                    | Hapus & insert ulang isi `hand_tiles` (snapshot saat ronde selesai).    |
| `setRiichiDiscard(int $handId, int $discardActionId): void`         | Update `riichi_discard_action_id` setelah deklarasi Riichi.             |
| `setNagashiManganDiscard(int $handId, int $discardActionId): void`  | Update `nagashi_mangan_discard_id`.                                     |

> Repository ini wajib memanggil `MeldRepository::findByHandId()` saat hidrasi agar entitas `Hand` lengkap.

---

## 7. MeldRepository

**Entity:** `Dewa\Mahjong\Entity\Meld`
**Tabel:** `melds` + junction `meld_tiles`.
**Sifat:** Child dari Hand, tetap berdiri sendiri karena di-query per game/per user.

### Function yang dibutuhkan

| Method                                                | Tujuan                                                                       |
|-------------------------------------------------------|------------------------------------------------------------------------------|
| `findById(int $id): ?Meld`                            | Ambil 1 meld lengkap dengan tiles-nya.                                       |
| `findByHandId(int $handId): Meld[]`                   | Semua meld milik 1 tangan (dipakai saat hidrasi `Hand`).                     |
| `findByGameContextId(int $gameContextId): Meld[]`     | Semua meld dalam 1 ronde (untuk validasi & statistik).                       |
| `findByUserId(int $userId): Meld[]`                   | Riwayat meld 1 user lintas game (statistik personal).                        |
| `save(Meld $meld): Meld`                              | INSERT/UPDATE + sinkron `meld_tiles` dengan `order_index`.                   |
| `delete(int $id): void`                               | Hapus meld (mis. saat rollback aksi).                                        |

---

## 8. DiscardedPileRepository

**Entity:** `Dewa\Mahjong\Entity\DiscardedPile`
**Tabel:** `discard_actions` (catatan: nama tabel di schema beda dengan nama entity — perlu diluruskan).
**Sifat:** Append-mostly history.

### Function yang dibutuhkan

| Method                                                                  | Tujuan                                                                |
|-------------------------------------------------------------------------|-----------------------------------------------------------------------|
| `findById(int $id): ?DiscardedPile`                                     | Ambil 1 buangan (FK dari `hands.riichi_discard_action_id`).           |
| `findByGameContextId(int $gameContextId): DiscardedPile[]`              | Semua buangan dalam 1 ronde, urut `order_index`.                      |
| `findByGameContextAndUser(int $gameContextId, int $userId): DiscardedPile[]` | Buangan 1 pemain di 1 ronde (untuk cek Furiten / Nagashi Mangan). |
| `findRiichiDeclareByHand(int $gameContextId, int $userId): ?DiscardedPile` | Buangan yang menandai deklarasi Riichi.                            |
| `save(DiscardedPile $discard): DiscardedPile`                           | INSERT buangan baru. Hidrasi mengembalikan id auto-increment.         |
| `countByGameContext(int $gameContextId): int`                           | Helper cepat menghitung total buangan tanpa hidrasi.                  |

> **Konsistensi penamaan:** entity bernama `DiscardedPile`, tabel bernama `discard_actions`.
> Putuskan satu konvensi (rekomendasi: rename tabel ke `discarded_piles` agar konsisten dengan entity & list `DROP TABLE` di awal schema).

---

## Catatan Tambahan

### A. Entity yang belum ada: `Game`
Tabel `games` ada di schema tapi tidak ada `Game` entity di `src/Entity/`.
Bila dibutuhkan menyimpan sesi multi-ronde, rekomendasinya:

- Buat `Dewa\Mahjong\Entity\Game` (id, createdAt, endedAt, status).
- Buat `GameRepository` dengan minimal: `findById`, `findActiveByUser`, `save`, `markEnded`.

### B. Pola umum semua repository
Mengikuti pola `TileRepository` yang sudah ada:

1. Constructor menerima `\PDO`.
2. Method privat `hydrate(array $row)` untuk konversi row → entity.
3. Method publik mengembalikan `?Entity` atau `Entity[]`.
4. Setiap repository punya **interface**-nya sendiri (`XxxRepositoryInterface`) agar mudah di-mock saat unit test & sesuai prinsip Dependency Inversion.

### C. Yang TIDAK perlu repository
- State real-time `GameContext` saat game aktif → **Redis / PHP Session**.
- Seed `Tile` & `Yaku` standar → bisa di-cache di memori setelah `findAll()` pertama.
- Field `conditions` `CustomYaku` → cukup JSON column, tidak butuh tabel terpisah.
