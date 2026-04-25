-- =============================================================================
-- RIICHI MAHJONG CALCULATOR - DATABASE SCHEMA
-- =============================================================================
-- Analisis per Entity:
--
-- [SQL]   Tile          → Tabel referensi statis (34 batu, tidak pernah berubah)
-- [SQL]   Yaku          → Tabel referensi statis (yaku standar, tidak pernah berubah)
-- [SQL]   User          → Data user, auth, perlu audit trail → wajib SQL
-- [SQL]   CustomYaku    → Konten buatan user, perlu relasi ke User → SQL
--                         Field `conditions` (array) → JSON column, struktur fleksibel
-- [SQL]   GameContext   → Kolom scalar per ronde → SQL
--                         Array (doraIndicators, hands, discardPile) → tabel relasi
-- [SQL]   Hand          → Kolom scalar per pemain per ronde → SQL
--                         Array tiles/melds → junction tables
-- [SQL]   Meld          → Kolom scalar (chi/pon/kan) → SQL
--                         Array tiles → junction table
-- [SQL]   DiscardAction → History buangan → SQL (relasi ke tile, game_context, user)
--
-- [BUKAN SQL - Keterangan di bawah schema]:
--   - State GameContext aktif (real-time) → Redis / PHP Session
--   - conditions[] di CustomYaku          → JSON column (sudah di dalam SQL)
--   - Data seed Tile & Yaku               → PHP seed file (immutable, di-cache)
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS
    game_context_custom_yakus,
    game_context_dora_indicators,
    hand_tiles,
    meld_tiles,
    discarded_piles,
    melds,
    hands,
    game_contexts,
    games,
    custom_yakus,
    users,
    yakus,
    tiles;
SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- 1. TILES (Referensi statis - 34 jenis batu unik)
-- =============================================================================
-- Kenapa SQL: Data tetap, dipakai sebagai FK oleh banyak tabel lain.
-- Tidak pernah di-INSERT oleh user, cukup di-seed sekali.
CREATE TABLE tiles (
    id          TINYINT UNSIGNED NOT NULL,          -- 1–34 (enum de facto)
    name        VARCHAR(20)      NOT NULL,           -- 'iichan', 'hatsu', dll
    value       VARCHAR(2)       NOT NULL,           -- '1'..'9', 'E','S','W','N','H','C','P'
    unicode     VARCHAR(10)      NOT NULL,           -- karakter unicode batu
    type        ENUM('man','pin','sou','honor') NOT NULL,
    color       VARCHAR(20)      NOT NULL,           -- label warna UI

    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- 2. YAKUS (Referensi statis - yaku standar Riichi Mahjong)
-- =============================================================================
-- Kenapa SQL: Data baku, dipakai sebagai FK oleh hasil kalkulasi & tampilan.
CREATE TABLE yakus (
    id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name_jp     VARCHAR(50)  NOT NULL,
    name_eng    VARCHAR(100) NOT NULL,
    description TEXT         NOT NULL,
    han_closed  TINYINT      NOT NULL DEFAULT 0,    -- 0 = tidak valid saat tertutup
    han_opened  TINYINT      NOT NULL DEFAULT 0,    -- 0 = tidak valid saat terbuka
    is_yakuman  BOOLEAN      NOT NULL DEFAULT FALSE,

    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- 3. USERS
-- =============================================================================
-- Kenapa SQL: Data user membutuhkan integritas (UNIQUE email/username),
-- audit trail (created_at, updated_at), dan soft-delete.
CREATE TABLE users (
    id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    username        VARCHAR(50)      NOT NULL,
    email           VARCHAR(255)     NOT NULL,
    password_hash   VARCHAR(255)     NOT NULL,
    created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_deleted      BOOLEAN          NOT NULL DEFAULT FALSE,

    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email    (email),
    UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- 4. CUSTOM YAKUS
-- =============================================================================
-- Kenapa SQL: Dibuat oleh user (relasi ke users), butuh toggle aktif/nonaktif,
-- audit trail. Field `conditions` (array PHP) → JSON column karena strukturnya
-- fleksibel dan tidak perlu di-query per key.
CREATE TABLE custom_yakus (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name                VARCHAR(100) NOT NULL,
    description         TEXT         NOT NULL,
    han_closed          TINYINT      NOT NULL DEFAULT 0,
    han_opened          TINYINT      NOT NULL DEFAULT 0,
    is_yakuman          BOOLEAN      NOT NULL DEFAULT FALSE,
    conditions          JSON         NOT NULL,          -- ← array $conditions dari PHP
    is_active           BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by_user_id  INT UNSIGNED NOT NULL,

    PRIMARY KEY (id),
    CONSTRAINT fk_custom_yakus_user FOREIGN KEY (created_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- 5. GAMES (Parent dari GameContext)
-- =============================================================================
-- Kenapa SQL: Wadah satu sesi permainan penuh (bisa multi-ronde).
-- Menyimpan siapa saja pemainnya dan kapan game dimulai/selesai.
CREATE TABLE games (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at    DATETIME     NULL,
    status      ENUM('active','finished','abandoned') NOT NULL DEFAULT 'active',

    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- 6. GAME CONTEXTS (Satu baris = satu ronde dalam satu game)
-- =============================================================================
-- Kenapa SQL: Data scalar ronde (honba, riichi sticks, angin, dealer) perlu
-- diquery untuk rekap skor. Array (tangan, buangan, dora) → tabel relasi terpisah.
--
-- CATATAN PENTING: Saat game SEDANG BERJALAN (status='active'), state terkini
-- (leftWallTiles, kanCount, nextTurnOrderIndex) sebaiknya di-cache di Redis agar
-- tidak hit database setiap giliran. Simpan ke SQL hanya saat ronde selesai.
CREATE TABLE game_contexts (
    id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    game_id                 INT UNSIGNED NOT NULL,
    round_number            TINYINT      NOT NULL,           -- 1–4
    round_wind              ENUM('east','south','west','north') NOT NULL,
    dealer_id               INT UNSIGNED NOT NULL,
    current_turn_user_id    INT UNSIGNED NOT NULL,
    status                  ENUM('active','finished','draw') NOT NULL DEFAULT 'active',
    honba                   SMALLINT     NOT NULL DEFAULT 0,
    riichi_sticks           TINYINT      NOT NULL DEFAULT 0,
    kan_count               TINYINT      NOT NULL DEFAULT 0,
    left_wall_tiles         TINYINT      NOT NULL DEFAULT 70,
    next_turn_order_index   SMALLINT     NOT NULL DEFAULT 1,

    PRIMARY KEY (id),
    CONSTRAINT fk_gc_game   FOREIGN KEY (game_id)              REFERENCES games (id),
    CONSTRAINT fk_gc_dealer FOREIGN KEY (dealer_id)            REFERENCES users (id),
    CONSTRAINT fk_gc_turn   FOREIGN KEY (current_turn_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- 7. GAME CONTEXT ↔ DORA INDICATORS (Junction)
-- =============================================================================
-- Kenapa SQL: Dora indicator adalah batu spesifik (FK ke tiles) milik sebuah
-- game_context. Maksimal 5, urutan penting → pakai kolom order_index.
CREATE TABLE game_context_dora_indicators (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    game_context_id  INT UNSIGNED NOT NULL,
    tile_id          TINYINT UNSIGNED NOT NULL,
    order_index      TINYINT      NOT NULL,   -- 1 = dora awal, 2–5 = dari Kan

    PRIMARY KEY (id),
    UNIQUE KEY uq_gc_dora (game_context_id, order_index),
    CONSTRAINT fk_gcdi_gc   FOREIGN KEY (game_context_id) REFERENCES game_contexts (id) ON DELETE CASCADE,
    CONSTRAINT fk_gcdi_tile FOREIGN KEY (tile_id)         REFERENCES tiles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- 8. GAME CONTEXT ↔ CUSTOM YAKUS AKTIF (Junction)
-- =============================================================================
-- Kenapa SQL: Satu ronde bisa mengaktifkan beberapa custom yaku sekaligus.
CREATE TABLE game_context_custom_yakus (
    game_context_id  INT UNSIGNED NOT NULL,
    custom_yaku_id   INT UNSIGNED NOT NULL,

    PRIMARY KEY (game_context_id, custom_yaku_id),
    CONSTRAINT fk_gccу_gc FOREIGN KEY (game_context_id) REFERENCES game_contexts (id) ON DELETE CASCADE,
    CONSTRAINT fk_gccy_cy FOREIGN KEY (custom_yaku_id)  REFERENCES custom_yakus (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- 9. HANDS (Tangan pemain per game_context)
-- =============================================================================
-- Kenapa SQL: Satu row = satu tangan, terhubung ke game_context dan user.
-- Boolean (is_dealer, is_riichi_declared) dan FK ke discard_actions simpan langsung.
-- Array tiles/melds → junction tables di bawah.
CREATE TABLE hands (
    id                          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    game_context_id             INT UNSIGNED NOT NULL,
    user_id                     INT UNSIGNED NOT NULL,
    is_dealer                   BOOLEAN      NOT NULL DEFAULT FALSE,
    is_riichi_declared          BOOLEAN      NOT NULL DEFAULT FALSE,
    riichi_discard_action_id    INT UNSIGNED NULL,     -- FK diisi setelah discard dibuat
    nagashi_mangan_discard_id   INT UNSIGNED NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_hand_gc_user (game_context_id, user_id),
    CONSTRAINT fk_hands_gc   FOREIGN KEY (game_context_id) REFERENCES game_contexts (id) ON DELETE CASCADE,
    CONSTRAINT fk_hands_user FOREIGN KEY (user_id)         REFERENCES users (id)
    -- FK ke discard_actions ditambah setelah tabel itu dibuat (lihat ALTER di bawah)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- 10. MELDS (Chi / Pon / Kan yang sudah terjadi)
-- =============================================================================
-- Kenapa SQL: Perlu diquery untuk cek Menzen, hitung Han, rekap statistik.
-- Array tiles → meld_tiles junction.
CREATE TABLE melds (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    game_context_id  INT UNSIGNED NOT NULL,
    user_id          INT UNSIGNED NOT NULL,
    hand_id          INT UNSIGNED NOT NULL,
    type             ENUM('chi','pon','ankan','daiminkan','shouminkan') NOT NULL,
    is_closed        BOOLEAN      NOT NULL DEFAULT FALSE,

    PRIMARY KEY (id),
    CONSTRAINT fk_melds_gc   FOREIGN KEY (game_context_id) REFERENCES game_contexts (id) ON DELETE CASCADE,
    CONSTRAINT fk_melds_user FOREIGN KEY (user_id)         REFERENCES users (id),
    CONSTRAINT fk_melds_hand FOREIGN KEY (hand_id)         REFERENCES hands (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- 11. MELD ↔ TILES (Junction)
-- =============================================================================
-- Kenapa SQL: Setiap meld berisi 3 atau 4 batu spesifik (FK ke tiles).
-- order_index menjaga urutan visual batu dalam set.
CREATE TABLE meld_tiles (
    id           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    meld_id      INT UNSIGNED     NOT NULL,
    tile_id      TINYINT UNSIGNED NOT NULL,
    order_index  TINYINT          NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_meld_tile_order (meld_id, order_index),
    CONSTRAINT fk_mt_meld FOREIGN KEY (meld_id)  REFERENCES melds (id) ON DELETE CASCADE,
    CONSTRAINT fk_mt_tile FOREIGN KEY (tile_id)  REFERENCES tiles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- 12. HAND ↔ TILES (Junction - batu tersembunyi di tangan)
-- =============================================================================
-- Kenapa SQL: Snapshot batu di tangan pada saat ronde selesai / kemenangan.
-- Tidak perlu disimpan real-time saat game aktif (lebih baik di cache).
CREATE TABLE hand_tiles (
    id           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    hand_id      INT UNSIGNED     NOT NULL,
    tile_id      TINYINT UNSIGNED NOT NULL,
    order_index  TINYINT          NOT NULL,   -- posisi batu dalam tangan

    PRIMARY KEY (id),
    CONSTRAINT fk_ht_hand FOREIGN KEY (hand_id) REFERENCES hands (id) ON DELETE CASCADE,
    CONSTRAINT fk_ht_tile FOREIGN KEY (tile_id) REFERENCES tiles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- 13. DISCARD ACTIONS (Riwayat buangan per ronde)
-- =============================================================================
-- Kenapa SQL: History permanen per game, dibutuhkan untuk Nagashi Mangan,
-- statistik, dan replay. Semua field scalar langsung → cocok jadi tabel SQL.
CREATE TABLE discard_actions (
    id                INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    game_context_id   INT UNSIGNED     NOT NULL,
    user_id           INT UNSIGNED     NOT NULL,
    tile_id           TINYINT UNSIGNED NOT NULL,
    turn_order        SMALLINT         NOT NULL,   -- giliran keberapa
    is_riichi_declare BOOLEAN          NOT NULL DEFAULT FALSE,
    is_tsumogiri      BOOLEAN          NOT NULL DEFAULT FALSE,  -- langsung buang tarikan
    order_index       TINYINT UNSIGNED NOT NULL,   -- urutan buangan 1–24

    PRIMARY KEY (id),
    CONSTRAINT fk_da_gc   FOREIGN KEY (game_context_id) REFERENCES game_contexts (id) ON DELETE CASCADE,
    CONSTRAINT fk_da_user FOREIGN KEY (user_id)         REFERENCES users (id),
    CONSTRAINT fk_da_tile FOREIGN KEY (tile_id)         REFERENCES tiles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- FK hands → discard_actions (ditambah setelah tabel discard_actions dibuat)
-- =============================================================================
ALTER TABLE hands
    ADD CONSTRAINT fk_hands_riichi_discard
        FOREIGN KEY (riichi_discard_action_id) REFERENCES discard_actions (id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_hands_nagashi_discard
        FOREIGN KEY (nagashi_mangan_discard_id) REFERENCES discard_actions (id) ON DELETE SET NULL;


-- =============================================================================
-- KESIMPULAN: APA YANG TIDAK DISIMPAN DI SQL
-- =============================================================================
--
-- 1. STATE REAL-TIME GameContext (saat game AKTIF)
--    → Simpan di: Redis / PHP Session
--    → Kenapa: leftWallTiles berkurang setiap giliran, tangan berubah setiap draw/discard.
--      Hit SQL setiap aksi akan membebani server. Flush ke SQL hanya saat ronde selesai.
--    → Data: kanCount, leftWallTiles, nextTurnOrderIndex, status='active'
--
-- 2. conditions[] pada CustomYaku
--    → Simpan di: JSON column (sudah ditangani di tabel custom_yakus.conditions)
--    → Kenapa: Struktur array conditions bersifat fleksibel (key bervariasi antar yaku).
--      Tidak ada query "WHERE conditions.allowed_suits = ..." → tidak butuh normalisasi.
--
-- 3. Data seed Tile (34 batu) & Yaku standar
--    → Simpan di: PHP seed file (database/seeds/TileSeeder.php, YakuSeeder.php)
--    → Kenapa: Immutable. Bisa di-load ke memory atau di-cache sepenuhnya.
--      Tidak perlu round-trip ke DB setiap kali kalkulator butuh daftar batu.
-- =============================================================================
