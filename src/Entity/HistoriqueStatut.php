<?php

namespace App\Entity;

use App\Enum\StatutColis;
use App\Repository\HistoriqueStatutRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HistoriqueStatutRepository::class)]
class HistoriqueStatut
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'historique')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Colis $colis = null;

    #[ORM\Column(length: 20, enumType: StatutColis::class)]
    private StatutColis $statut;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $auteur = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $commentaire = null;

    public function __construct(StatutColis $statut, ?User $auteur = null, ?string $commentaire = null)
    {
        $this->statut = $statut;
        $this->auteur = $auteur;
        $this->commentaire = $commentaire;
        $this->date = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getColis(): ?Colis
    {
        return $this->colis;
    }

    public function setColis(?Colis $colis): static
    {
        $this->colis = $colis;

        return $this;
    }

    public function getStatut(): StatutColis
    {
        return $this->statut;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getAuteur(): ?User
    {
        return $this->auteur;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }
}
