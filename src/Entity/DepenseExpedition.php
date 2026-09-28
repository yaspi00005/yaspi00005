<?php

namespace App\Entity;

use App\Enum\CategorieDepense;
use App\Repository\DepenseExpeditionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** Charge d'une expédition (fret, douane, transport…), pour calculer sa marge. Montant en centimes. */
#[ORM\Entity(repositoryClass: DepenseExpeditionRepository::class)]
class DepenseExpedition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'depenses')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Expedition $expedition = null;

    #[ORM\Column(length: 20, enumType: CategorieDepense::class)]
    #[Assert\NotNull(message: 'Choisissez le type de dépense.')]
    private ?CategorieDepense $categorie = CategorieDepense::Fret;

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $libelle = null;

    #[ORM\Column]
    #[Assert\NotNull(message: 'Indiquez le montant.')]
    #[Assert\Positive(message: 'Le montant doit être supérieur à zéro.')]
    private ?int $montant = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'Indiquez la date.')]
    private ?\DateTimeImmutable $date = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $auteur = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->date = new \DateTimeImmutable('today');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getExpedition(): ?Expedition
    {
        return $this->expedition;
    }

    public function setExpedition(?Expedition $expedition): static
    {
        $this->expedition = $expedition;

        return $this;
    }

    public function getCategorie(): ?CategorieDepense
    {
        return $this->categorie;
    }

    public function setCategorie(?CategorieDepense $categorie): static
    {
        $this->categorie = $categorie;

        return $this;
    }

    public function getLibelle(): ?string
    {
        return $this->libelle;
    }

    public function setLibelle(?string $libelle): static
    {
        $this->libelle = null !== $libelle && '' !== trim($libelle) ? trim($libelle) : null;

        return $this;
    }

    public function getMontant(): ?int
    {
        return $this->montant;
    }

    public function setMontant(?int $montant): static
    {
        $this->montant = $montant;

        return $this;
    }

    public function getDate(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(?\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getAuteur(): ?User
    {
        return $this->auteur;
    }

    public function setAuteur(?User $auteur): static
    {
        $this->auteur = $auteur;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
