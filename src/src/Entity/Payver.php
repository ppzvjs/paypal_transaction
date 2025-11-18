<?php

namespace App\Entity;

use App\Repository\PayverRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PayverRepository::class)]
class Payver
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $subscription_id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $satz_nr = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubscriptionId(): ?string
    {
        return $this->subscription_id;
    }

    public function setSubscriptionId(?string $subscription_id): static
    {
        $this->subscription_id = $subscription_id;

        return $this;
    }

    public function getSatzNr(): ?string
    {
        return $this->satz_nr;
    }

    public function setSatzNr(?string $satz_nr): static
    {
        $this->satz_nr = $satz_nr;

        return $this;
    }
}
