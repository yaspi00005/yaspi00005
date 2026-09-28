<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Dernier numéro attribué par préfixe (ex. « ST2609- » → 42) : un numéro de suivi n'est jamais
 * réattribué, même après la suppression définitive d'un colis.
 */
#[ORM\Entity]
class CompteurNumero
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 20)]
        private string $prefixe,
        #[ORM\Column]
        private int $dernier = 0,
    ) {
    }

    public function getPrefixe(): string
    {
        return $this->prefixe;
    }

    public function getDernier(): int
    {
        return $this->dernier;
    }

    public function setDernier(int $dernier): static
    {
        $this->dernier = $dernier;

        return $this;
    }
}
