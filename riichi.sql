-- 1. Tabel Users
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_deleted BOOLEAN NOT NULL DEFAULT FALSE
);

-- 2. Tabel Custom Yaku
CREATE TABLE custom_yakus (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    han_closed INT NOT NULL,
    han_opened INT NOT NULL,
    is_yakuman BOOLEAN NOT NULL DEFAULT FALSE,
    conditions JSON NOT NULL COMMENT 'Aturan kustom dalam format JSON',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by_user_id INT NOT NULL,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 3. Tabel Game Context (Meja Permainan)
CREATE TABLE game_contexts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    game_id INT NOT NULL COMMENT 'Bisa untuk referensi ke session/room eksternal',
    status ENUM('active', 'draw', 'finished') NOT NULL DEFAULT 'active',
    round_number INT NOT NULL DEFAULT 1,
    round_wind ENUM('east', 'south', 'west', 'north') NOT NULL DEFAULT 'east',
    honba INT NOT NULL DEFAULT 0,
    riichi_sticks INT NOT NULL DEFAULT 0,
    dealer_id INT NOT NULL,
    current_turn_user_id INT NOT NULL,
    next_turn_order_index INT NOT NULL DEFAULT 1,
    left_wall_tiles INT NOT NULL DEFAULT 70,
    dora_indicators JSON NOT NULL COMMENT 'Array berisi objek Tile Dora',
    FOREIGN KEY (dealer_id) REFERENCES users(id),
    FOREIGN KEY (current_turn_user_id) REFERENCES users(id)
);

-- Tabel Pivot: Mengaktifkan Custom Yaku tertentu pada Game tertentu
CREATE TABLE game_active_custom_yakus (
    game_context_id INT NOT NULL,
    custom_yaku_id INT NOT NULL,
    PRIMARY KEY (game_context_id, custom_yaku_id),
    FOREIGN KEY (game_context_id) REFERENCES game_contexts(id) ON DELETE CASCADE,
    FOREIGN KEY (custom_yaku_id) REFERENCES custom_yakus(id) ON DELETE CASCADE
);

-- 4. Tabel Hands (Tangan Pemain)
CREATE TABLE hands (
    id INT AUTO_INCREMENT PRIMARY KEY,
    game_context_id INT NOT NULL,
    user_id INT NOT NULL,
    is_dealer BOOLEAN NOT NULL DEFAULT FALSE,
    is_riichi_declared BOOLEAN NOT NULL DEFAULT FALSE,
    riichi_discard_id INT NULL COMMENT 'Akan diisi ID dari discard_actions saat Riichi',
    nagashi_mangan_discard_id INT NULL,
    tiles JSON NOT NULL COMMENT 'Batu tersembunyi yang ada di tangan',
    FOREIGN KEY (game_context_id) REFERENCES game_contexts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 5. Tabel Melds (Batu Terbuka / Call)
CREATE TABLE melds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    game_context_id INT NOT NULL,
    user_id INT NOT NULL,
    type ENUM('chi', 'pon', 'ankan', 'daiminkan', 'shouminkan') NOT NULL,
    is_closed BOOLEAN NOT NULL,
    tiles JSON NOT NULL COMMENT 'Batu-batu yang membentuk meld ini',
    FOREIGN KEY (game_context_id) REFERENCES game_contexts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 6. Tabel Discard Actions (Kolam Buangan)
CREATE TABLE discard_actions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    game_context_id INT NOT NULL,
    user_id INT NOT NULL,
    tile JSON NOT NULL COMMENT 'Detail 1 batu yang dibuang',
    turn_order INT NOT NULL,
    is_riichi_declare BOOLEAN NOT NULL DEFAULT FALSE,
    is_tsumo BOOLEAN NOT NULL COMMENT 'Tsumogiri (true) atau Tebidashi (false)',
    order_index INT NOT NULL,
    FOREIGN KEY (game_context_id) REFERENCES game_contexts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Menambahkan Foreign Key untuk riichi_discard_id di tabel hands
-- (Ditambahkan pakai ALTER agar tidak error urutan pembuatan tabel)
ALTER TABLE hands 
ADD FOREIGN KEY (riichi_discard_id) REFERENCES discard_actions(id) ON DELETE SET NULL;