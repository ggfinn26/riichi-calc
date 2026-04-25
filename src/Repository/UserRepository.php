<?php

namespace Dewa\Mahjong\Repository;

use Dewa\Mahjong\Entity\User;

class UserRepository implements UserRepositoryInterface
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Konversi data mentah MySQL menjadi Objek Entitas User
     */
    private function hydrate(array $row): User
    {
        return new User(
            (int) $row["id"],
            $row["username"], 
            $row["email"], 
            $row["password_hash"],
            new \DateTime($row["created_at"]), // Konversi string MySQL ke DateTime PHP
            new \DateTime($row["updated_at"]),
            (bool) $row["is_deleted"]
        );
    }

    public function findById(int $id): ?User
    {
        $sql = "SELECT * FROM users WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]); // Cara paling ringkas untuk binding
        
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    public function findByEmail(string $email): ?User
    {
        $sql = "SELECT * FROM users WHERE email = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$email]);
        
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    public function findByUsername(string $username): ?User
    {
        $sql = "SELECT * FROM users WHERE username = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$username]);
        
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    /** @return User[] */
    public function findAllActive(): array
    {
        $sql = "SELECT * FROM users WHERE is_deleted = 0";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $active = [];
        foreach($rows as $row){
            $active[] = $this->hydrate($row);
        }
        return $active;
    }

    /**
     * Menyimpan User (Bisa untuk Insert akun baru atau Update profil)
     */
    public function save(User $user): User
    {
        // Jika ID 0, berarti ini user baru (INSERT)
        if ($user->getId() === 0) {
            $sql = "INSERT INTO users (username, email, password_hash, created_at, updated_at, is_deleted) 
                    VALUES (?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $user->getUserName(),
                $user->getEmail(),
                $user->getPasswordHash(),
                $user->getCreatedAt()->format('Y-m-d H:i:s'), // Ubah ke format MySQL
                $user->getUpdatedAt()->format('Y-m-d H:i:s'),
                (int) $user->isDeleted() // Ubah boolean ke 0/1
            ]);

            // Ambil ID yang baru saja digenerate oleh MySQL
            $newId = (int) $this->pdo->lastInsertId();

            // Kembalikan objek User baru yang sudah memiliki ID
            return new User(
                $newId,
                $user->getUserName(),
                $user->getEmail(),
                $user->getPasswordHash(),
                $user->getCreatedAt(),
                $user->getUpdatedAt(),
                $user->isDeleted()
            );
        } 
        // Jika sudah punya ID, berarti ini edit profil (UPDATE)
        else {
            $sql = "UPDATE users SET username = ?, email = ?, password_hash = ?, updated_at = ?, is_deleted = ? WHERE id = ?";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $user->getUserName(),
                $user->getEmail(),
                $user->getPasswordHash(),
                $user->getUpdatedAt()->format('Y-m-d H:i:s'),
                (int) $user->isDeleted(),
                $user->getId()
            ]);

            return $user;
        }
    }

    /**
     * Soft Delete: Hanya update status, bukan DELETE dari database
     */
    public function softDelete(User $user): void
    {
        $sql = "UPDATE users SET is_deleted = 1, updated_at = NOW() WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$user->getId()]);
    }

    public function existByEmail(string $email): bool
    {
        $sql = "SELECT COUNT(*) FROM users WHERE email = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$email]);
        
        $count = (int) $stmt->fetchColumn();
        return $count > 0;
    }

    public function existByUsername(string $username): bool 
    {
        $sql = "SELECT COUNT(*) FROM users WHERE username = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$username]);

        $count = (int) $stmt->fetchColumn();
        return $count > 0;
    }
}