<?php

namespace App\Entity;

use App\Repository\PhotoSiteRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** Photo de la galerie « En images » de sotrama.net : chargements, départs et arrivées. */
#[ORM\Entity(repositoryClass: PhotoSiteRepository::class)]
class PhotoSite
{
    public const CATEGORIES = ['chargement' => 'Chargement', 'depart' => 'Départ', 'arrivee' => 'Arrivée', 'autre' => 'Autre'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $photo = null;

    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\Length(max: 150)]
    private ?string $legende = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: ['chargement', 'depart', 'arrivee', 'autre'])]
    private string $categorie = 'chargement';

    #[ORM\Column]
    private int $ordre = 0;

    #[ORM\Column]
    private bool $publie = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPhoto(): ?string
    {
        return $this->photo;
    }

    public function setPhoto(?string $photo): static
    {
        $this->photo = $photo;

        return $this;
    }

    public function getLegende(): ?string
    {
        return $this->legende;
    }

    public function setLegende(?string $legende): static
    {
        $this->legende = $legende ? trim($legende) : null;

        return $this;
    }

    public function getCategorie(): string
    {
        return $this->categorie;
    }

    public function setCategorie(?string $categorie): static
    {
        $this->categorie = $categorie ?: 'autre';

        return $this;
    }

    public function getLibelleCategorie(): string
    {
        return self::CATEGORIES[$this->categorie] ?? 'Autre';
    }

    public function getOrdre(): int
    {
        return $this->ordre;
    }

    public function setOrdre(?int $ordre): static
    {
        $this->ordre = (int) $ordre;

        return $this;
    }

    public function isPublie(): bool
    {
        return $this->publie;
    }

    public function setPublie(bool $publie): static
    {
        $this->publie = $publie;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
