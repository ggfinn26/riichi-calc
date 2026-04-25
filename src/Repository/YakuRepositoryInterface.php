<?php

namespace Dewa\Mahjong\Repository;

use Dewa\Mahjong\Entity\Yaku;

interface YakuRepositoryInterface
{
    public function findById(int $id): ?Yaku;
    public function findByNameJp(string $nameJp): ?Yaku;
    public function findByNameEng(string $nameEng): ?Yaku;
    
    /** @return Yaku[] */
    public function findAll(): array;
    
    /** @return Yaku[] */
    public function findClosedOnly(): array;   
}