<?php

namespace Dewa\Mahjong\Entity;

class User
{
    private int $id;
    private string $userName;
    private string $email;
    private string $passwordHash;
    private \DateTime $createdAt;
    private \DateTime $updatedAt;
    private bool $isDeleted;

    public function __construct(int $id, string $userName, string $email, string $passwordHash, \DateTime $createdAt, \DateTime $updatedAt, bool $isDeleted)
    {
        $this->id = $id;
        $this->userName = $userName;
        $this->email = $email;
        $this->passwordHash = $passwordHash;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->isDeleted = $isDeleted;
    }

    //Getter

    public function getId(): int
    {
        return $this->id;
    }
    public function getUserName(): string
    {
        return $this->userName;
    }
    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }
    public function getEmail(): string
    {
        return $this->email;
    }

    public function getCreatedAt(): \DateTime
    {
        return $this->createdAt;
    }
    public function getUpdatedAt(): \DateTime
    {
        return $this->updatedAt;
    }
    public function isDeleted(): bool
    {
        return $this->isDeleted;
    }

    // --- SMART ACTION METHODS (Pengganti Setter Biasa) ---
    private function markAsUpdated(): void
    {
        $this->updatedAt = new \DateTime();
    }

    public function changeUserName(string $newUserName): void
    {
        $this->userName = $newUserName;
        $this->markAsUpdated();
    }

    public function changeEmail(string $newEmail): void
    {
        $this->email = $newEmail;
        $this->markAsUpdated();
    }

    public function changePassword(string $newPasswordHash): void
    {
        $this->passwordHash = $newPasswordHash;
        $this->markAsUpdated();
    }

    public function delete(): void
    {
        $this->isDeleted = true;
        $this->markAsUpdated();
    }


}