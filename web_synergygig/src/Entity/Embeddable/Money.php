<?php

namespace App\Entity\Embeddable;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
class Money
{
    #[ORM\Column(type: 'integer')]
    private int $amount = 0;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency = 'USD';

    public function __construct(int $amount = 0, string $currency = 'USD')
    {
        $this->amount = $amount;
        $this->currency = strtoupper($currency);
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getAmountInDollars(): float
    {
        return $this->amount / 100;
    }
}
