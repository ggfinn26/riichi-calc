Dokumentasi Layer Service - Riichi Mahjong Calculator

Direktori src/Service/ berisi kelas-kelas yang menangani logika bisnis utama (domain logic) dari aplikasi Riichi Mahjong Calculator. Jika Entity adalah "Hukum Fisika" dan Repository adalah "Gudang Penyimpanan", maka Service adalah "Sang Otak".

Service bertugas memproses data dari entitas, menjalankan algoritma aturan Mahjong, merakit data menggunakan repositori, dan menghasilkan output final. Layer Service di aplikasi ini dibagi menjadi tiga kategori utama: Kalkulator Matematis, Manajer Alur Permainan, dan Sistem Rekomendasi.

A. Kalkulator Matematis (Pure Calculation Services)

Service ini bersifat stateless. Mereka menerima data mentah, memproses pola, dan mengembalikan hasil hitungan tanpa mengubah data di database.

1. YakuEvaluator

Bertanggung jawab untuk mengevaluasi kombinasi tangan (Hand) pemain dan menentukan Yaku apa saja yang berhasil didapatkan.
Fungsi Utama:

Menerima input berupa Hand (termasuk batu di tangan dan meld yang terbuka/tertutup), batu kemenangan (winning tile), dan kondisi permainan (seperti Riichi, Ippatsu, Tsumo/Ron, dll).

Mengecek setiap pola Yaku standar (seperti Tanyao, Pinfu, Yakuhai, dll) terhadap tangan pemain.

Mengecek Yaku kustom (Custom Yaku) yang sedang aktif pada ronde tersebut.

Mengembalikan daftar Yaku yang valid beserta total Han yang didapatkan.

2. ScoreCalculator

Bertanggung jawab untuk menghitung total skor (poin) yang didapatkan pemain berdasarkan Han, Fu, dan kondisi permainan.
Fungsi Utama:

Menerima input berupa total Han (dari YakuEvaluator), total Fu, status pemain (Dealer/Oya atau Non-Dealer/Ko), dan cara menang (Tsumo atau Ron).

Menghitung skor dasar (Basic Points) berdasarkan rumus standar Riichi Mahjong.

Menentukan kategori skor (Mangan, Haneman, Baiman, Sanbaiman, Yakuman) jika Han mencapai batas tertentu.

Menghitung pembagian pembayaran poin (siapa membayar berapa) berdasarkan cara menang (Tsumo: semua membayar, Ron: pembuang batu membayar penuh).

Menambahkan bonus poin dari Honba dan poin taruhan Riichi (Riichi stick).

3. WaitCalculator (Machi Pendeteksi Tenpai)

Bertanggung jawab untuk menganalisis tangan yang belum menang (berisi 13 batu) dan mencari tahu batu apa saja yang bisa membuat tangan tersebut menang.
Fungsi Utama:

Menerima input berupa Hand pemain (13 batu fisik + Meld).

Menjalankan algoritma backtracking atau pattern matching untuk mendeteksi Machi (Tanki, Kanchan, Penchan, Ryanmen, Shanpon).

Mengembalikan array berisi objek Tile yang menjadi Waits (batu kemenangan yang ditunggu).

Output dari service ini sangat esensial untuk digunakan oleh FuritenChecker dan indikator Tenpai di layar pemain.

4. FuritenChecker

Bertanggung jawab untuk mengecek apakah seorang pemain berada dalam status Furiten. Status Furiten melarang pemain untuk menang melalui Ron (mengambil batu buangan lawan).
Fungsi Utama:

Menerima input berupa daftar batu yang ditunggu/Waits (dari WaitCalculator) dan riwayat batu buangan (DiscardedPile).

Mengecek Furiten Permanen: Apakah pemain pernah membuang salah satu batu yang bisa membuatnya menang.

Mengecek Furiten Sementara (Temporary Furiten): Apakah pemain melewatkan kesempatan menang (Ron) pada putaran yang sama.

Mengecek Furiten Riichi: Apakah pemain melewatkan kesempatan menang (Ron) setelah mendeklarasikan Riichi.

Mengembalikan nilai boolean (true jika Furiten, false jika tidak).

B. Manajer Alur Permainan (Game Flow Services)

Service ini bersifat organisasional. Mereka menerima input aksi dari user interface (pemain), memodifikasi Entity, dan memanggil Repository untuk menyimpan perubahan ke dalam database secara permanen.

5. PlayerActionService (Sutradara Aksi Pemain)

Bertanggung jawab sebagai orkestrator dari semua tindakan fisik yang dilakukan pemain di meja selama ronde berlangsung. Service ini adalah jembatan utama antara Controller/UI dan Layer Infrastruktur.
Fungsi Utama:

Draw & Discard: Mengatur perpindahan batu dari dinding ke tangan pemain, dan dari tangan pemain ke DiscardedPile.

Call/Meld Declaration: Memproses deklarasi Chi, Pon, Kan dari pemain. Mengubah susunan batu di tangan menjadi objek Meld.

Riichi Declaration: Menjalankan Smart Action declareRiichi() pada entitas Hand, memindahkan 1000 poin ke tengah meja (GameContext), dan mencatat batu pemutar status Riichi.

Orkestrasi Database: Setelah sebuah aksi selesai tervalidasi, service ini akan memanggil repositori untuk menjalankan mekanisme Cascade Save dan menyimpan status meja terkini ke dalam MySQL.

6. GameProgressionService (Manajer Transisi Ronde)

Bertanggung jawab untuk mengatur siklus hidup (lifecycle) dari sebuah game, dari akhir sebuah ronde (Timur 1) hingga transisi ke ronde berikutnya (Timur 2), atau mengakhiri permainan.
Fungsi Utama:

Resolusi Akhir Ronde: Mengevaluasi bagaimana ronde berakhir (Agari/Menang, Ryuukyoku/Seri karena dinding habis, Chombo/Pelanggaran, atau Suukaikan/4 Kan).

Kalkulasi Ryuukyoku (No-ten Bappu): Menghitung perpindahan poin denda untuk pemain yang tidak Tenpai (dibantu oleh WaitCalculator) kepada pemain yang Tenpai saat seri.

Rotasi Dealer & Honba: Menentukan apakah Dealer (Oya) berhak mempertahankan kursinya (Renchan) dan menambah stik Honba, atau apakah peran Dealer bergeser ke pemain berikutnya.

Inisialisasi Meja Baru: Membuat entitas GameContext baru untuk ronde selanjutnya berdasarkan status Dealer dan Honba terkini, lalu menyimpannya ke database untuk memulai putaran baru.

C. Sistem Rekomendasi & Efisiensi (Recommendation Services)

Service ini menganalisis probabilitas matematis dan memberikan panduan bagi pengguna tentang keputusan terbaik yang bisa diambil selama permainan (fitur kalkulator tingkat lanjut).

7. ShantenCalculator

Bertanggung jawab untuk menghitung Shanten, yaitu jumlah minimum batu yang perlu ditukar/dibuang agar tangan mencapai kondisi Tenpai (siap menang).
Fungsi Utama:

Menerima input Hand pemain.

Menghitung nilai Shanten untuk susunan tangan reguler (4 set + 1 pair), Chiitoitsu (7 pairs), dan Kokushi Musou (13 orphans), lalu mengambil nilai minimumnya.

Mengembalikan angka integer (contoh: 0 untuk Tenpai, 1 untuk Iishanten, 2 untuk Ryanshanten).

8. VisibleTileTrackerService (Pendeteksi Sisa Batu)

Bertanggung jawab untuk memindai seluruh meja dan menghitung probabilitas sisa batu secara akurat.
Fungsi Utama:

Memindai DiscardedPile (buangan semua pemain), DoraIndicators, dan Meld terbuka milik pemain lain.

Menghitung berapa banyak sisa dari suatu batu spesifik (maksimal 4) yang masih mungkin ditarik dari dinding atau dipegang oleh pemain lain.

Data ini krusial untuk memastikan nilai Ukeire yang realistis (bukan sekadar teoritis).

9. DefenseEvaluatorService (Kalkulator Keamanan/Safety)

Bertanggung jawab untuk menilai tingkat bahaya (Danger Level) dari setiap batu jika dibuang, terutama saat ada lawan yang sudah Tenpai atau Riichi.
Fungsi Utama:

Mengevaluasi batu berdasarkan aturan pertahanan Mahjong: Genbutsu (batu buangan lawan/100% aman), Suji (aman parsial), dan Kabe (blokir angka).

Memeriksa efek Furiten lawan berdasarkan tumpukan buangan mereka.

Mengembalikan persentase risiko (contoh: 0% untuk Genbutsu, 15% untuk Suji, 80% untuk Live Tile tak terlihat).

10. ExpectedValueCalculator (Estimasi Nilai Tangan / EV)

Bertanggung jawab untuk mensimulasikan nilai akhir dari sebuah tangan jika berhasil menang, untuk memastikan pemain tidak salah memilih kecepatan vs skor.
Fungsi Utama:

Mensimulasikan YakuEvaluator terhadap kemungkinan batu yang ditarik (Waits/Ukeire).

Menghitung peluang tercapainya Yaku tertentu (misal: "Jika membuang 4-Pin, Ukeire lebih kecil, tapi 100% menjamin Tanyao dan Pinfu").

Memberikan estimasi skor (EV) yang sangat berguna bagi sistem pembuat keputusan (decision-making).

11. DiscardRecommendationService (Sistem Inti "Nani Kiru")

Sebagai konduktor dari seluruh sistem rekomendasi, menyatukan metrik kecepatan, keselamatan, dan nilai.
Fungsi Utama:

Menyimulasikan pembuangan setiap batu di tangan pemain (satu per satu).

Memanggil ShantenCalculator untuk melihat jarak ke kemenangan.

Memanggil VisibleTileTrackerService untuk menghitung Ukeire Realistis (jumlah batu berguna yang benar-benar tersisa).

Memanggil DefenseEvaluatorService untuk membobot tingkat keamanan batu yang dibuang.

Memanggil ExpectedValueCalculator untuk membobot ekspektasi skor.

Mengembalikan rekomendasi akhir berupa peringkat (ranking) batu yang paling optimal untuk dibuang berdasarkan keseimbangan Kecepatan (Speed), Keamanan (Defense), dan Nilai (Value).