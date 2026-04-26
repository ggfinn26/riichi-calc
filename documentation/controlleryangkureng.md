Melihat struktur kode yang ada, aplikasi ini telah memiliki lapisan domain yang sangat kuat melalui `Entity`, `Repository`, dan berbagai `Service` (Calculation, GameFlow, Recommendation). Untuk menghubungkan logika bisnis ini dengan antarmuka luar (seperti REST API atau web frontend), berikut adalah analisis arsitektur controller yang akan dibutuhkan:

### 1. ScoringController (Controller Perhitungan)

Controller ini bertugas menangani semua permintaan yang berkaitan dengan perhitungan skor dan analisis tangan pemain (hand analysis). Ini adalah core utama dari kalkulator.

* **Dependency yang Dibutuhkan:** `ScoringService`, `ScoreCalculator`, `YakuEvaluator`, `WaitCalculator`.
* **Contoh Endpoint:**
  * `POST /api/score/calculate`: Menerima payload berisi ubin di tangan (hand), ubin panggilan (melds), ubin kemenangan, dan kondisi permainan (GameContext). Mengembalikan total poin, jumlah Han, Fu, dan daftar Yaku.
  * `POST /api/score/waits`: Menerima payload susunan tangan saat ini (Tenpai) dan mengembalikan daftar ubin apa saja yang bisa membuat tangan tersebut menang (machi) menggunakan `WaitCalculator`.
  * `POST /api/score/shanten`: Menghitung nilai shanten (jarak menuju tenpai) menggunakan `ShantenCalculator`.

### 2. GameProgressionController / ActionController (Controller Alur Permainan)

Jika aplikasi ini tidak hanya sekadar kalkulator statis melainkan juga mensimulasikan alur permainan atau mencatat jalannya pertandingan, controller ini sangat esensial.

* **Dependency yang Dibutuhkan:** `GameProgressionService`, `PlayerActionService`.
* **Contoh Endpoint:**
  * `POST /api/game/start`: Menginisialisasi `GameContext` baru (ronde, putaran angin, poin awal).
  * `POST /api/game/{gameId}/draw`: Mengeksekusi aksi pemain untuk mengambil ubin dari dinding.
  * `POST /api/game/{gameId}/discard`: Mengeksekusi aksi membuang ubin dan mencatatnya ke `DiscardedPile`.
  * `POST /api/game/{gameId}/call`: Menangani deklarasi pemain seperti Chi, Pon, Kan, atau Riichi.

### 3. RecommendationController (Controller Asisten/AI)

Karena terdapat namespace `Recommendation` di dalam service, controller ini dibutuhkan jika ada fitur asisten yang memberikan saran langkah optimal kepada pemain.

* **Dependency yang Dibutuhkan:** `DiscardRecommendationService`, `DefenseEvaluatorService`, `ExpectedValueCalculator`.
* **Contoh Endpoint:**
  * `POST /api/recommendation/discard`: Mengevaluasi tangan pemain dan tumpukan buangan (visible tiles) untuk memberikan saran ubin mana yang paling optimal untuk dibuang (misalnya berdasarkan efisiensi blok atau perlindungan nilai tangan).
  * `POST /api/recommendation/defense`: Memberikan analisis tingkat bahaya (danger pitch) dari ubin-ubin yang ada di tangan terhadap pemain lawan yang sedang Riichi atau terlihat melakukan tenpai.

### 4. GameContextController / MatchController (Controller Manajemen Pertandingan)

Controller ini lebih bersifat administratif untuk mengelola data pertandingan secara keseluruhan.

* **Dependency yang Dibutuhkan:** `GameContextRepository`, `UserRepository`.
* **Contoh Endpoint:**
  * `GET /api/matches/{gameId}`: Mengambil state/kondisi permainan saat ini secara utuh (skor masing-masing pemain, angin putaran, jumlah riichi stick di meja, sisa ubin).
  * `GET /api/users/{userId}/history`: Mengambil riwayat pertandingan atau statistik kalkulasi yang pernah dilakukan oleh pengguna/pemain tertentu.

**Saran Implementasi:**

Karena logika bisnis (seperti pengecekan Furiten, dekomposisi Fu, perhitungan nilai ekspektasi) sudah terenkapsulasi dengan baik di dalam folder `Service`, Controller nantinya hanya perlu bertugas sebagai "pengatur lalu lintas". Controller cukup menerima *request* HTTP, melakukan validasi payload input, meneruskan data tersebut ke *Service* yang relevan, dan membungkus hasil dari *Service* tersebut ke dalam respons JSON.


Mengingat di dalam struktur kode Anda sudah terdapat `UserRepository` dan `UserRepositoryInterface` (di dalam `src/Repository/`), `UserController` akan menjadi komponen yang sangat penting jika aplikasi ini memiliki sistem akun pemain, menyimpan riwayat pertandingan, atau melacak statistik individu.

Berikut adalah analisis apa saja yang harus ditangani oleh `UserController` dan bagaimana rancangannya:

### 1. Tanggung Jawab Utama (Responsibilities)

* **Autentikasi & Otorisasi:** Menangani proses pendaftaran pemain baru, login, logout, dan manajemen sesi (atau penerbitan token seperti JWT jika menggunakan REST API).
* **Manajemen Profil:** Memungkinkan pengguna untuk memperbarui data diri, preferensi pengaturan (misalnya pengaturan default apakah bermain dengan *Akadora* / ubin merah atau tidak).
* **Statistik Pemain (Player Stats):** Menampilkan rekam jejak pemain, seperti *win rate* (persentase menang), rata-rata poin, Yaku yang paling sering didapatkan, atau tingkat bahaya (deal-in rate /  *hōjū-ritsu* ).

### 2. Dependency yang Dibutuhkan

Controller ini akan membutuhkan beberapa service dan repository untuk beroperasi:

* **`UserRepository`** : Untuk operasi CRUD data pengguna di database (mencari user berdasarkan email, menyimpan user baru).
* **`GameContextRepository` / `MatchHistoryRepository`** : Untuk menarik data riwayat pertandingan yang pernah dimainkan oleh user tersebut.
* **`AuthService` (Perlu ditambahkan)** : Service khusus untuk menangani logika enkripsi/hashing password (misal menggunakan `password_hash()` bawaan PHP) dan verifikasi kredensial, agar logika ini tidak menumpuk di Controller.

### 3. Contoh Endpoint / Routing

Jika diimplementasikan sebagai API, berikut adalah endpoint yang biasanya ada di dalam `UserController`:

* **`POST /api/users/register`**
  * **Payload:** `username`, `email`, `password`.
  * **Aksi:** Memvalidasi input, melakukan *hashing* password via `AuthService`, lalu menyimpannya melalui `UserRepository`.
* **`POST /api/users/login`**
  * **Payload:** `email`, `password`.
  * **Aksi:** Memeriksa kecocokan data, dan jika valid, mengembalikan Token (misal: Bearer Token) atau mengatur *Session* PHP.
* **`GET /api/users/me`**
  * **Aksi:** Mengembalikan data profil pengguna yang sedang login. Middleware harus memastikan endpoint ini hanya bisa diakses jika user sudah login.
* **`GET /api/users/{id}/statistics`**
  * **Aksi:** Mengambil agregasi data dari riwayat permainan. Misalnya, memanggil service yang menghitung berapa kali pemain ini mendeklarasikan *Riichi* atau menang dengan *Tsumo* dibanding  *Ron* .

### 4. Gambaran Implementasi Sederhana

Sebagai gambaran, karena Anda menyusun aplikasi ini dengan prinsip yang rapi (memisahkan interface dan implementasi), controllernya akan terlihat seperti ini:

**PHP**

```
namespace App\Controller;

use App\Repository\UserRepositoryInterface;
// use App\Service\AuthService; // Jika ada

class UserController {
    private UserRepositoryInterface $userRepository;
  
    public function __construct(UserRepositoryInterface $userRepository) {
        $this->userRepository = $userRepository;
    }

    public function getProfile($request) {
        // Asumsi kita mendapatkan ID user dari sesi atau token JWT
        $userId = $request->getAuthenticatedUserId(); 
      
        $user = $this->userRepository->findById($userId);
      
        if (!$user) {
            // Kembalikan response 404 Not Found
            return new JsonResponse(['error' => 'User tidak ditemukan'], 404);
        }

        // Kembalikan data user (pastikan password tidak ikut terkirim)
        return new JsonResponse([
            'id' => $user->getId(),
            'username' => $user->getUsername(),
            'created_at' => $user->getCreatedAt()
        ]);
    }
}
```

**Kapan Anda butuh UserController?**

Jika saat ini aplikasi mahjong Anda murni hanya sebuah kalkulator skor (*stateless* - tidak perlu login untuk menghitung skor), `UserController` mungkin belum menjadi prioritas. Namun, jika Anda berencana mengembangkannya menjadi platform di mana orang bisa menyimpan hasil kalkulasi mereka atau bermain dalam mode karir simulasi, controller ini wajib dibangun di awal bersamaan dengan sistem *Middleware* (untuk memblokir akses ke halaman tertentu jika belum login).


Mengingat di dalam struktur kode Anda sudah terdapat `UserRepository` dan `UserRepositoryInterface` (di dalam `src/Repository/`), `UserController` akan menjadi komponen yang sangat penting jika aplikasi ini memiliki sistem akun pemain, menyimpan riwayat pertandingan, atau melacak statistik individu.

Berikut adalah analisis apa saja yang harus ditangani oleh `UserController` dan bagaimana rancangannya:

### 1. Tanggung Jawab Utama (Responsibilities)

* **Autentikasi & Otorisasi:** Menangani proses pendaftaran pemain baru, login, logout, dan manajemen sesi (atau penerbitan token seperti JWT jika menggunakan REST API).
* **Manajemen Profil:** Memungkinkan pengguna untuk memperbarui data diri, preferensi pengaturan (misalnya pengaturan default apakah bermain dengan *Akadora* / ubin merah atau tidak).
* **Statistik Pemain (Player Stats):** Menampilkan rekam jejak pemain, seperti *win rate* (persentase menang), rata-rata poin, Yaku yang paling sering didapatkan, atau tingkat bahaya (deal-in rate /  *hōjū-ritsu* ).

### 2. Dependency yang Dibutuhkan

Controller ini akan membutuhkan beberapa service dan repository untuk beroperasi:

* **`UserRepository`** : Untuk operasi CRUD data pengguna di database (mencari user berdasarkan email, menyimpan user baru).
* **`GameContextRepository` / `MatchHistoryRepository`** : Untuk menarik data riwayat pertandingan yang pernah dimainkan oleh user tersebut.
* **`AuthService` (Perlu ditambahkan)** : Service khusus untuk menangani logika enkripsi/hashing password (misal menggunakan `password_hash()` bawaan PHP) dan verifikasi kredensial, agar logika ini tidak menumpuk di Controller.

### 3. Contoh Endpoint / Routing

Jika diimplementasikan sebagai API, berikut adalah endpoint yang biasanya ada di dalam `UserController`:

* **`POST /api/users/register`**
  * **Payload:** `username`, `email`, `password`.
  * **Aksi:** Memvalidasi input, melakukan *hashing* password via `AuthService`, lalu menyimpannya melalui `UserRepository`.
* **`POST /api/users/login`**
  * **Payload:** `email`, `password`.
  * **Aksi:** Memeriksa kecocokan data, dan jika valid, mengembalikan Token (misal: Bearer Token) atau mengatur *Session* PHP.
* **`GET /api/users/me`**
  * **Aksi:** Mengembalikan data profil pengguna yang sedang login. Middleware harus memastikan endpoint ini hanya bisa diakses jika user sudah login.
* **`GET /api/users/{id}/statistics`**
  * **Aksi:** Mengambil agregasi data dari riwayat permainan. Misalnya, memanggil service yang menghitung berapa kali pemain ini mendeklarasikan *Riichi* atau menang dengan *Tsumo* dibanding  *Ron* .

### 4. Gambaran Implementasi Sederhana

Sebagai gambaran, karena Anda menyusun aplikasi ini dengan prinsip yang rapi (memisahkan interface dan implementasi), controllernya akan terlihat seperti ini:

**PHP**

```
namespace App\Controller;

use App\Repository\UserRepositoryInterface;
// use App\Service\AuthService; // Jika ada

class UserController {
    private UserRepositoryInterface $userRepository;
  
    public function __construct(UserRepositoryInterface $userRepository) {
        $this->userRepository = $userRepository;
    }

    public function getProfile($request) {
        // Asumsi kita mendapatkan ID user dari sesi atau token JWT
        $userId = $request->getAuthenticatedUserId(); 
      
        $user = $this->userRepository->findById($userId);
      
        if (!$user) {
            // Kembalikan response 404 Not Found
            return new JsonResponse(['error' => 'User tidak ditemukan'], 404);
        }

        // Kembalikan data user (pastikan password tidak ikut terkirim)
        return new JsonResponse([
            'id' => $user->getId(),
            'username' => $user->getUsername(),
            'created_at' => $user->getCreatedAt()
        ]);
    }
}
```

**Kapan Anda butuh UserController?**

Jika saat ini aplikasi mahjong Anda murni hanya sebuah kalkulator skor (*stateless* - tidak perlu login untuk menghitung skor), `UserController` mungkin belum menjadi prioritas. Namun, jika Anda berencana mengembangkannya menjadi platform di mana orang bisa menyimpan hasil kalkulasi mereka atau bermain dalam mode karir simulasi, controller ini wajib dibangun di awal bersamaan dengan sistem *Middleware* (untuk memblokir akses ke halaman tertentu jika belum login).
