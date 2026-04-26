# Scoring Calculation Service — Desain Presisi

Dokumen ini merangkum aturan scoring Riichi Mahjong dari **RiichiBook1.pdf — Chapter 6 (Scoring)** dan menerjemahkannya menjadi spesifikasi service `ScoreCalculator` yang **presisi** (bukan shortcut), supaya semua kasus tepi (kan, honor pair ganda, wait, pinfu tsumo, chiitoitsu, limit hands, dll.) tertangani benar.

Sumber utama: RiichiBook1.pdf §6.1–§6.4, Tabel 6.1, 6.2, 6.6, 6.10, 6.11.

---

## 1. Tiga Langkah Score Calculation (§6.1)

> _Step 1: Count han → Step 2: Figure minipoints (fu) → Step 3: Get score._

1. **Hitung Han** — jika ≥ 5 han, lewati Step 2 (limit hand, fu tidak berpengaruh).
2. **Hitung Fu (minipoints)** — hanya untuk hand dengan ≤ 4 han. Punya beberapa exception (pinfu tsumo = 20, chiitoitsu = 25, open pinfu = 30).
3. **Hitung Score** — lookup ke score table berdasarkan (han, fu, isDealer, isTsumo).

Service kita **wajib** mengimplementasikan jalur presisi (perhitungan fu eksak), karena shortcut "almost always 30/40 fu" hanya mengcover ~75% kasus (book §6.2 footnote 1).

---

## 2. Aturan Han Counting (Step 1)

Han = total kontribusi semua yaku yang valid + dora + uradora + akadora + kita (jika dipakai).

Rule penting:
- Yaku khusus tertutup (pinfu, iipeiko, riichi, dll.) **gugur** jika hand terbuka. Ini sudah jadi tanggung jawab `YakuEvaluator`, bukan scoring service.
- **Yakuman tidak digabung dengan han biasa**. Yakuman selalu jadi limit hand sendiri (32000/48000 dasar). Han dora **tidak menambah** ke yakuman.
- **Kuisaagari (kuitagari)**: yaku seperti _sanshoku_, _ittsuu_, _chanta_, _junchan_, _honitsu_, _chinitsu_ kehilangan 1 han ketika hand terbuka (sudah ditangani di tabel `yakus`).
- Hand harus punya **minimal 1 yaku** (selain dora) untuk bisa menang. Validasi ini dilakukan _sebelum_ scoring.

---

## 3. Aturan Fu Counting (Step 2) — §6.3.1

### 3.1 Base fu

| Kondisi | Fu |
|---|---|
| Base (semua standard hand) | 20 |
| Chiitoitsu | **25 (flat, tidak ada tambahan)** |
| Pinfu tsumo | **20 (flat)** |
| Open pinfu (ron / tsumo) | **30 (flat)** — kasus eksepsional, lihat §6.2 fn 4 |
| Kokushi & yakuman lain | tidak relevan (limit) |

### 3.2 Win-condition fu

| Kondisi menang | Fu |
|---|---|
| Tsumo (kecuali pinfu) | +2 |
| Ron menzen (closed) | +10 (menzen kafu / menzen ron bonus) |
| Ron open | +0 |

### 3.3 Set / quad fu (Tabel 6.6)

|  | Open | Concealed |
|---|---|---|
| Set (koutsu) — simple (2–8) | 2 | 4 |
| Set (koutsu) — terminal/honor (1, 9, wind, dragon) | 4 | 8 |
| Quad (kantsu) — simple | 8 | 16 |
| Quad (kantsu) — terminal/honor | 16 | 32 |

Catatan implementasi:
- **Shouminkan (added kan)** dianggap **open** kantsu (karena diturunkan dari pon).
- **Daiminkan** = open kantsu.
- **Ankan** = concealed kantsu (dan TIDAK mematahkan menzen — lihat `Hand::isMenzen()`).
- **Concealed triplet via ron**: jika tile pemenang menyelesaikan koutsu lewat ron, koutsu tersebut diperlakukan **open** (penting untuk pinfu, sanankou, dan fu). Lewat tsumo, tetap concealed.

### 3.4 Pair fu (head)

Tambah +2 untuk:
- Pair naga (haku/hatsu/chun)
- Pair angin seat (jikaze)
- Pair angin round (bakaze)

**Double-wind pair** (mis. pair Timur saat round Timur untuk pemain Timur) = **+4 fu** (§6.3.1 fn 6, EMA & Tenhou rules). Service akan pakai aturan ini sebagai default; bisa di-flag lewat `GameContext` jika nanti perlu rule berbeda.

### 3.5 Wait fu

| Wait | Fu |
|---|---|
| Kanchan (closed wait, mis. 4 menunggu di 3-5) | +2 |
| Penchan (edge wait, 1-2 nunggu 3 atau 8-9 nunggu 7) | +2 |
| Tanki (single wait pada pair) | +2 |
| Shanpon (dual pon wait) | +0 |
| Ryanmen (two-sided) | +0 |

Implementasi: ketika sebuah hand bisa di-decompose >1 cara (mis. hand Scoring 1 §6.3 dengan hasil ‌‰ı vs ‰ȷ), **pilih dekomposisi dengan fu tertinggi** (rule "highest scoring interpretation").

### 3.6 Rounding

Total fu (selain chiitoitsu & pinfu tsumo) **dibulatkan ke atas ke kelipatan 10**.
Contoh: 20 + 10 + 8 + 4 = 42 → **50 fu** (lihat contoh `‌‰ı` di §6.3.1).

### 3.7 Minimum fu

- Pinfu ron menzen: 20 + 10 = **30 fu** (otomatis, sesuai aturan).
- Pinfu tsumo: **20 fu flat** (override).
- Open pinfu-shape: **30 fu flat** (override).
- Hand standar lain minimum efektif: 30 fu setelah rounding (karena base 20 + ron 10 = 30, atau base 20 + tsumo 2 = 22 → 30).

---

## 4. Score Formula (Step 3)

### 4.1 Base points

```
basePoints = fu × 2^(2 + han)
```

Cap pada **2000** untuk hand non-limit (mangan = basePoints ≥ 2000 dipotong jadi 2000).

### 4.2 Limit hand cap (Tabel 6.1)

| Han | Tier | basePoints |
|---|---|---|
| 5 | Mangan | 2000 |
| 6–7 | Haneman | 3000 |
| 8–10 | Baiman | 4000 |
| 11–12 | Sanbaiman | 6000 |
| 13+ (kazoe) | Yakuman* | 8000 |
| Yakuman murni | Yakuman | 8000 |

*Catatan: di EMA revised, 13+ han dibatasi ke **sanbaiman** (§Tabel 6.1 footnote). Service akan menyediakan flag `kazoeYakumanEnabled` (default `true` agar konsisten dengan Tenhou).

**Kiriage mangan** (opsional, default off): 4 han 30 fu (7700) dan 3 han 70 fu dibulatkan ke mangan. Beberapa rule set memakai ini; expose sebagai flag.

### 4.3 Pembayaran

Notasi: `roundUp100(x)` = bulatkan x ke atas ke kelipatan 100.

**Non-dealer ron:**
```
score = roundUp100(basePoints × 4)
```

**Dealer ron:**
```
score = roundUp100(basePoints × 6)
```

**Non-dealer tsumo:**
```
fromDealer    = roundUp100(basePoints × 2)
fromNonDealer = roundUp100(basePoints × 1)
total         = fromDealer + 2 × fromNonDealer
```

**Dealer tsumo:**
```
fromEach = roundUp100(basePoints × 2)
total    = 3 × fromEach
```

### 4.4 Honba & riichi sticks

- Tiap honba menambah **300 poin** ke total pemenang.
  - Ron: dibayar penuh oleh deal-in (300 × honba).
  - Tsumo: 100 × honba per pembayar.
- Tiap riichi stick di meja menambah **1000 poin** ke pemenang (tidak displit).

### 4.5 Verifikasi vs Tabel 6.10 / 6.11

Service **harus** menghasilkan hasil identik dengan Tabel 6.10 (non-dealer) dan 6.11 (dealer). Ini dipakai sebagai test fixture (lihat §7).

Contoh sanity check (non-dealer):
- 1 han 30 fu ron → 30 × 2^3 = 240 base → ×4 = 960 → roundUp100 = **1000** ✓
- 4 han 30 fu ron → 30 × 2^6 = 1920 base → ×4 = 7680 → **7700** ✓
- 3 han 25 fu tsumo → 25 × 2^5 = 800 base → dealer-pay 1600, non-dealer-pay 800 → **800-1600** ✓
- 1 han 110 fu ron → 110 × 2^3 = 880 base → ×4 = 3520 → **3600** ✓

---

## 5. Arsitektur Service yang Diusulkan

```
src/Service/Calculation/
├── ScoreCalculator.php          # Orchestrator publik (entry point)
├── HanCounter.php               # Aggregator yaku → han
├── Fu/
│   ├── FuCalculator.php         # Loop: base + win + meld + pair + wait, lalu round-up
│   ├── HandDecomposer.php       # Mendekomposisi 14 tile → semua kombinasi (4 set + pair)
│   └── WaitClassifier.php       # ryanmen / kanchan / penchan / tanki / shanpon
├── Score/
│   ├── BasePointsCalculator.php # fu × 2^(2+han) + cap mangan/haneman/...
│   ├── PaymentResolver.php      # base → (dealer? × tsumo?) → split & roundUp100
│   └── LimitHandResolver.php    # han ≥ 5 routing
└── ScoreResult.php              # Value object: han, fu, basePoints, payments[], yakuList
```

### 5.1 API publik

```php
final class ScoreCalculator
{
    public function calculate(
        Hand $hand,
        Tile $winningTile,
        bool $isTsumo,
        GameContext $context,
        ScoringOptions $opts = new ScoringOptions(),
    ): ScoreResult;
}
```

`ScoringOptions`:
- `kazoeYakumanEnabled` (bool, default true)
- `kiriageManganEnabled` (bool, default false)
- `doubleWindPairFu` (int, default 4) — bisa di-set 2 untuk rule lama
- `honbaCount` (int)
- `riichiSticksOnTable` (int)

### 5.2 ScoreResult (value object)

```
- han: int
- fu: int                 // setelah rounding
- fuBreakdown: array      // ['base'=>20, 'menzenRon'=>10, 'concealedHonorTriplet'=>8, ...]
- yakuList: Yaku[]
- basePoints: int
- limitName: ?string      // 'mangan' | 'haneman' | ... | null
- payment: Payment        // {fromDealer, fromNonDealer, fromDealInPlayer, total}
- honbaBonus: int
- riichiStickBonus: int
- finalTotal: int
```

### 5.3 Alur eksekusi

1. **Validasi input** — `Hand::isValidTileCount()`, ada winning tile, yaku ≥ 1.
2. **Decompose hand** — hasilkan semua dekomposisi 4-set+pair yang valid (chiitoitsu & kokushi jadi cabang khusus).
3. **Untuk tiap dekomposisi** → jalankan `YakuEvaluator` dan `FuCalculator`.
4. **Pilih dekomposisi pemenang** dengan rule:
   - Pertama: total skor tertinggi (han × fu setelah konversi base points).
   - Tie-break: han tertinggi (estetis / konvensi).
5. **Resolve limit** jika han ≥ 5 atau yakuman.
6. **Hitung payment** + honba + riichi sticks.
7. Kembalikan `ScoreResult`.

---

## 6. Edge Cases yang Wajib Tertangani

Mengacu langsung ke contoh-contoh di §6.3.3 dan tabel:

| # | Kasus | Perilaku |
|---|---|---|
| 1 | **Pinfu tsumo** | fu = 20 flat, jangan pakai +2 tsumo |
| 2 | **Pinfu ron menzen** | fu = 30 flat (20 + 10 menzen ron, no other) |
| 3 | **Open pinfu-shape** | fu = 30 flat |
| 4 | **Chiitoitsu** | fu = 25, han ≥ 2 (yaku 2 han), tabel 6.5 |
| 5 | **Concealed triplet via ron** | triplet yang diselesaikan winning tile = OPEN, bisa membatalkan pinfu / sanankou |
| 6 | **Ankan menzen-safe** | tetap concealed, tidak hapus menzen |
| 7 | **Double-wind pair** | +4 fu (Tabel 6.10 contoh 110 fu) |
| 8 | **Wait ambigu** (mis. ‌‰ı menunggu —/ı) | pilih dekomposisi fu tertinggi per winning tile |
| 9 | **Kazoe yakuman** | 13+ han → yakuman (atau sanbaiman jika EMA flag) |
| 10 | **Multi-yakuman** (mis. daisangen + tsuuiisou) | sum yakuman count, tiap yakuman = 1× yakuman base |
| 11 | **Mangan rounding** | 4 han 30 fu non-dealer ron = 7700 (bukan mangan), kecuali kiriage flag |
| 12 | **Honba & riichi sticks** | tambah ke `finalTotal`, jangan ganggu `basePoints` |
| 13 | **110 fu, 1 han, non-dealer ron** | tabel mengatakan "—" (mustahil tanpa yaku); validasi awal harus reject hand tanpa yaku |
| 14 | **Dealer renchan** | hanya pengaruhi honba count, scoring sama dengan dealer biasa |

---

## 7. Strategi Testing (Test Fixtures)

Dua sumber kebenaran sebagai golden fixtures:

### 7.1 Tabel 6.10 (non-dealer) & 6.11 (dealer)

Generate matriks (han × fu × isTsumo × isDealer) dan assert exact match. Setiap sel jadi 1 test case parametrik.

### 7.2 Contoh §6.3.3 (Scoring 1–5)

```
Scoring 1: ——‌‰ı‹››“”%%% (Red Dragon)
  - ron pada — / “ → 1 han 40 fu = 1300
  - ron pada ı     → 1 han 40 fu = 1300 (closed wait → ada decomposisi yg jadi 40 fu)
  - tsumo ‚       → 2 han 30 fu = 500-1000 (2000 total)
  - tsumo “       → 2 han 40 fu = 700-1300 (2700 total)
  - tsumo ı       → 2 han 40 fu = 700-1300 (closed wait)
```

(Test cases setara untuk Scoring 2–5.)

### 7.3 Edge stress

- Hand 110 fu (§ contoh dengan 2 ankan terminals + double-wind pair).
- Pinfu tsumo riichi (3 han 20 fu = 700-1300).
- Chiitoitsu 4 han = 6400 ron / 1600-3200 tsumo.

---

## 8. Mapping ke Entity yang Ada

Service ini **konsumen** entity, bukan pemilik. Mapping data yang dibutuhkan:

| Data | Sumber |
|---|---|
| isDealer, isMenzen | `Hand::isDealer()`, `Hand::isMenzen()` |
| concealed tiles | `Hand::getTiles()` |
| open/closed melds | `Hand::getMelds()` + `Meld::isClosed()` / `isAnkan()` / dll. |
| isRiichi | `Hand::isRiichiDeclared()` |
| round wind, seat wind, honba, riichi sticks | `GameContext` |
| winning tile, isTsumo | parameter eksplisit ke `calculate()` |
| yaku list & han counts | `YakuRepository` (master) + `YakuEvaluator` (deteksi) |

Yang **belum** ada di entity tapi dibutuhkan service:
- `Tile` perlu helper `isTerminalOrHonor()`, `isSimple()`, `isDragon()`, `isWind()`, `isSameAs(Tile)`. Cek `Tile.php` dan tambahkan jika belum ada.
- Mungkin `WaitType` enum (ryanmen/kanchan/penchan/tanki/shanpon).

---

## 9. Roadmap Implementasi

1. ✅ Baca PDF, susun spec ini.
2. **Tile helpers** — pastikan `Tile` punya predicate yang dibutuhkan.
3. **HandDecomposer** + unit test (chiitoitsu, kokushi, standard).
4. **WaitClassifier** + unit test.
5. **FuCalculator** + unit test pakai contoh §6.3.1 & §6.3.3.
6. **HanCounter** (thin wrapper di atas `YakuEvaluator`).
7. **BasePointsCalculator** + **LimitHandResolver**.
8. **PaymentResolver** + roundUp100 + honba/riichi sticks.
9. **ScoreCalculator** orchestrator.
10. **Golden test** lawan Tabel 6.10 dan 6.11 (288 sel).
11. **Acceptance test** lawan §6.3.3 contoh.

---

## 10. Open Questions & Rekomendasi

### 10.1 Kiriage mangan?

**Rekomendasi: OFF by default**, expose lewat `ScoringOptions::kiriageManganEnabled`.

- **Alasan**: Tenhou (referensi utama buku ini, lihat §1.4.3) **tidak** memakai kiriage mangan. EMA juga tidak. Mengaktifkannya secara default akan menghasilkan skor 8000 untuk 4han30fu non-dealer, padahal Tabel 6.10 dengan tegas menulis 7700.
- **Konsekuensi kalau ON**: 4han30fu, 3han60fu, 3han70fu di-promote ke mangan. Hanya relevan di rule set tertentu (mis. beberapa ruleset Jepang amatir).
- **Cara kerja flag**: di `BasePointsCalculator`, setelah hitung basePoints, jika `kiriageManganEnabled && (han,fu) ∈ {(4,30),(3,60),(3,70)}` maka set basePoints = 2000.

### 10.2 Pao (sekinin barai / responsibility payment)?

**Rekomendasi: implementasikan di iterasi pertama**, karena hanya mempengaruhi *redistribusi pembayaran*, bukan perhitungan skor inti.

- **Aturan**: jika seorang pemain men-discard tile yang menyelesaikan **daisangen** (tile naga ke-3), **daisuushii** (tile angin ke-4), atau **suukantsu** (kan ke-4) bagi lawan, dan lawan itu kemudian menang dengan yakuman tersebut, **pao player** menanggung pembayaran:
  - Tsumo: pao player bayar penuh basePoints×8 (untuk non-dealer winner) / ×12 (dealer winner), pemain lain bebas.
  - Ron: pao player dan deal-in player split 50-50.
- **Desain**: tambah ke `ScoringOptions`:
  ```php
  ?int $paoPlayerId = null;       // pemain yang kena pao
  ?string $paoYakumanType = null; // 'daisangen' | 'daisuushii' | 'suukantsu'
  ```
  `PaymentResolver` punya cabang khusus: jika `paoPlayerId` di-set DAN yaku pemenang termasuk yakuman pao-eligible, redistribusikan pembayaran sesuai aturan di atas.
- **Deteksi otomatis pao** (siapa yang discard tile pemicu) **bukan** tanggung jawab service ini — itu state game-flow. Caller yang tahu, lalu pass ke `ScoringOptions`.

### 10.3 Nagashi mangan?

**Rekomendasi: keluar dari `ScoreCalculator`**, jadikan service terpisah `NagashiManganResolver`.

- **Alasan**: nagashi mangan dipicu oleh **kondisi exhaustive draw** (semua discard pemain adalah terminal/honor & tidak ada yang dicall lawan), **bukan** oleh winning hand. Ia tidak punya yaku, tidak punya fu, tidak punya wait — semua input yang dibutuhkan `ScoreCalculator::calculate()` tidak relevan.
- **Skor**: flat mangan, dibayar **seolah-olah tsumo** (4000-all untuk dealer, 2000/4000 untuk non-dealer), tidak menggeser dealer (renchan logic terpisah).
- **Reuse**: service nagashi cukup memanggil `PaymentResolver` dengan `basePoints = 2000` dan `isTsumo = true`, supaya formula pembayaran tetap satu sumber kebenaran.
- Field `Hand::nagashiManganDiscardId` dipakai sebagai marker; resolver yang baca-nya.

### 10.4 Tenpai / noten payments (ryuukyoku)?

**Rekomendasi: keluar dari scope scoring**, masuk ke service game-flow (mis. `RoundResolver`).

- **Alasan**: ini transfer poin **tanpa hand winner** dan **tanpa han/fu**. Semata-mata fungsi dari "berapa pemain yang tenpai saat exhaustive draw":
  - 1 tenpai → 3 noten bayar 1000 each (winner dapat 3000).
  - 2 tenpai → 2 noten bayar 1500 each, dibagi rata 1500 ke 2 tenpai.
  - 3 tenpai → 1 noten bayar 3000, dibagi rata 1000 ke 3 tenpai.
- Tidak ada bagian dari Chapter 6 yang relevan. Memasukkannya ke `ScoreCalculator` akan mencemari API.

### 10.5 Ringkasan keputusan

| Topik | Keputusan | Lokasi |
|---|---|---|
| Kiriage mangan | OFF default, opsional via flag | `ScoringOptions` + `BasePointsCalculator` |
| Pao | Diimplementasikan di v1 | `ScoringOptions` + `PaymentResolver` |
| Nagashi mangan | Service terpisah | `NagashiManganResolver` (reuse `PaymentResolver`) |
| Tenpai/noten payment | Service terpisah | `RoundResolver` (di luar dokumen ini) |

Dengan ini, `ScoreCalculator` tetap fokus: **input = winning hand + context, output = skor presisi**. Logic round-flow & exhaustive-draw dipisah supaya tidak saling kontaminasi.
