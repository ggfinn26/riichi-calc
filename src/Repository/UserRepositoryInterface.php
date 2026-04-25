<?php

namespace Dewa\Mahjong\Repository;

use Dewa\Mahjong\Entity\User;

interface UserRepositoryInterface{
    public function findById(int $id): ?User;
    public function findByEmail(string $email): ?User;
    public function findByUsername(string $username) : ?User;
    public function findAllActive(): array;
    public function save(User $user): User;
    public function softDelete(User $user): void;
    public function existByEmail(string $email): bool;

    public function existByUsername(string $username): bool;

}