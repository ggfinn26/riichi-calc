# Dokumentasi Repository

Direktori `src/Repository/` berisi antarmuka (interface) dan implementasi untuk mengakses dan memanipulasi data dari database. Pola Repository digunakan untuk memisahkan logika bisnis dari detail akses data, sehingga memudahkan pengujian dan pemeliharaan.

Berikut adalah daftar antarmuka repository yang tersedia beserta fungsinya:

## 1. UserRepositoryInterface
Menangani operasi data untuk entitas `User`.

*   `findById(int $id): ?User` - Mencari pengguna berdasarkan ID.
*   `findByEmail(string $email): ?User` - Mencari pengguna berdasarkan alamat email.
*   `findByUsername(string $username): ?User` - Mencari pengguna berdasarkan username.
*   `findAllActive(): array` - Mengambil semua pengguna yang aktif.
*   `save(User $user): User` - Menyimpan atau memperbarui data pengguna.
*   `softDelete(User $user): void` - Melakukan penghapusan lunak (soft delete) pada pengguna.
*   `existByEmail(string $email): bool` - Memeriksa apakah email sudah terdaftar.
*   `existByUsername(string $username): bool` - Memeriksa apakah username sudah terdaftar.

## 2. TileRepositoryInterface
Menangani operasi data untuk entitas `Tile` (Batu Mahjong).

*   `findById(int $id): ?Tile` - Mencari satu batu spesifik berdasarkan ID-nya (1-34).
*   `findByName(string $name): ?Tile` - Mencari satu batu spesifik berdasarkan namanya (misal: '1-man', 'chun').
*   `findAll(): array` - Mengambil seluruh 34 batu (biasanya untuk inisialisasi awal atau cache).
*   `findByType(string $type): array` - Mengambil sekumpulan batu berdasarkan tipe (misal: 'man', 'pin', 'sou', 'honor'). Sangat berguna untuk YakuEvaluator saat mengecek Chinitsu/Honitsu.
*   `findByColor(string $color): array` - Mengambil sekumpulan batu berdasarkan label warna visualnya.

## 3. MeldRepositoryInterface
Menangani operasi data untuk entitas `Meld` (Kumpulan batu seperti Chi, Pon, Kan).

*   `findById(int $id): ?Meld` - Mencari meld berdasarkan ID.
*   `findByHandId(int $handId): array` - Mengambil semua meld yang terkait dengan suatu Hand.
*   `findByGameContextId(int $gameContextId): array` - Mengambil semua meld dalam suatu konteks permainan (ronde).
*   `findByGameContextIdGroupedByHand(int $gameContextId): array` - Batch fetch: mengambil semua meld dalam 1 ronde lalu mengelompokkannya per `hand_id`. Dipakai oleh `HandRepository` untuk menghindari masalah N+1 saat hidrasi banyak Hand.
*   `findByUserId(int $userId): array` - Mengambil semua meld yang dibuat oleh pengguna tertentu.
*   `save(Meld $meld): Meld` - Menyimpan atau memperbarui data meld.
*   `delete(int $id): void` - Menghapus meld berdasarkan ID.

## 4. CustomYakuRepositoryInterface
Menangani operasi data untuk entitas `CustomYaku` (Yaku kustom/aturan rumah).

*   `findById(int $id): ?CustomYaku` - Mencari custom yaku berdasarkan ID.
*   `findByCreatedByUserId(int $userId): array` - Mengambil semua custom yaku yang dibuat oleh pengguna tertentu.
*   `findByGameContextId(int $gameContextId): array` - Mengambil semua custom yaku yang aktif dalam suatu konteks permainan.
*   `save(CustomYaku $customYaku): CustomYaku` - Menyimpan atau memperbarui data custom yaku.
*   `delete(int $id): void` - Menghapus custom yaku berdasarkan ID.

## 5. DiscardedPileRepositoryInterface
Menangani operasi data untuk entitas `DiscardedPile` (Tumpukan batu buangan).

*   `findById(int $id): ?DiscardedPile` - Mencari batu buangan berdasarkan ID.
*   `findByGameContextId(int $gameContextId): array` - Mengambil semua batu buangan dalam suatu konteks permainan.
*   `findByGameContextAndUser(int $gameContextId, int $userId): array` - Mengambil semua batu buangan dari pengguna tertentu dalam suatu konteks permainan.
*   `findRiichiDeclareByHand(int $gameContextId, int $userId): ?DiscardedPile` - Mencari batu buangan yang digunakan untuk mendeklarasikan Riichi oleh pengguna tertentu dalam suatu ronde.
*   `save(DiscardedPile $discard): DiscardedPile` - Menyimpan data batu buangan baru.
*   `countByGameContext(int $gameContextId): int` - Menghitung total batu buangan dalam suatu konteks permainan.

## 6. GameContextRepositoryInterface
Menangani operasi data untuk entitas `GameContext` (Konteks permainan/Ronde).

*   `findById(int $id): ?GameContext` - Mengambil satu ronde lengkap dengan deep hydration: dora indicators, hands (+ tiles + melds), discard pile, dan custom yaku aktif.
*   `findAllRoundsByGameId(int $gameId): array` - Mengambil semua ronde dalam satu game (Timur 1, Timur 2, Selatan 1, dst). Shallow hydration — hanya kolom skalar. Untuk rekap skor/list view.
*   `findByActiveGameId(int $gameId): ?GameContext` - Mengambil ronde yang sedang berjalan untuk sebuah game. Deep hydration — siap dipakai oleh evaluator.
*   `save(GameContext $gameContext): GameContext` - Menyimpan atau memperbarui data konteks permainan.
*   `addDoraIndicator(int $gameContextId, int $tileId, int $orderIndex): void` - Menambahkan indikator dora ke dalam ronde.
*   `attachCustomYaku(int $gameContextId, int $customYakuId): void` - Mengaktifkan custom yaku untuk ronde tertentu.
*   `detachCustomYaku(int $gameContextId, int $customYakuId): void` - Menonaktifkan custom yaku dari ronde tertentu.
*   `markFinished(int $id, string $status): void` - Menandai ronde sebagai selesai dengan status tertentu.

## 7. HandRepositoryInterface
Menangani operasi data untuk entitas `Hand` (Tangan pemain).

*   `findById(int $id): ?Hand` - Mencari hand berdasarkan ID.
*   `findByGameContextId(int $gameContextId): array` - Mengambil semua hand dalam suatu konteks permainan.
*   `findByGameContextAndUser(int $gameContextId, int $userId): ?Hand` - Mengambil hand milik pengguna tertentu dalam suatu konteks permainan.
*   `save(Hand $hand): Hand` - Menyimpan atau memperbarui data hand.
*   `replaceTiles(int $handId, array $tiles): void` - Mengganti seluruh batu di tangan pemain (biasanya saat inisialisasi atau sinkronisasi).
*   `setRiichiDiscard(int $handId, int $discardActionId): void` - Menandai aksi buangan mana yang merupakan deklarasi Riichi.
*   `setNagashiManganDiscard(int $handId, int $discardActionId): void` - Menandai aksi buangan untuk Nagashi Mangan.

## 8. YakuRepositoryInterface
Menangani operasi data untuk entitas `Yaku` (Pola kemenangan standar).

*   `findById(int $id): ?Yaku` - Mencari yaku berdasarkan ID.
*   `findByNameJp(string $nameJp): ?Yaku` - Mencari yaku berdasarkan nama Jepangnya.
*   `findByNameEng(string $nameEng): ?Yaku` - Mencari yaku berdasarkan nama Inggrisnya.
*   `findAll(): array` - Mengambil semua daftar yaku standar.
*   `findClosedOnly(): array` - Mengambil daftar yaku yang hanya valid jika tangan tertutup (Menzenchin).
