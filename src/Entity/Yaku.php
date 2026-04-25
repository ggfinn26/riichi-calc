<?php

namespace Dewa\Mahjong\Entity;

class Yaku
{
    private int $id;
    private string $nameJp;
    private string $nameEng;
    private string $description;
    private int $hanClosed;
    private int $hanOpened;
    private bool $isYakuman;

    public function __construct(int $id, string $nameJp, string $nameEng, string $description, int $hanClosed, int $hanOpened, bool $isYakuman)
    {
        $this->id = $id;
        $this->nameJp = $nameJp;
        $this->nameEng = $nameEng;
        $this->description = $description;
        $this->hanClosed = $hanClosed;
        $this->hanOpened = $hanOpened;
        $this->isYakuman = $isYakuman;
    }

    //Getter

    public function getId(): int
    {
        return $this->id;
    }
    public function getNameJp(): string
    {
        return $this->nameJp;
    }
    public function getNameEng(): string
    {
        return $this->nameEng;
    }
    public function getHanClosed(): int
    {
        return $this->hanClosed;
    }
    public function getHanOpened(): int
    {
        return $this->hanOpened;
    }
    public function getisYakuman(): bool
    {
        return $this->isYakuman;
    }


}