<?php

namespace App\Entity;

use App\Repository\ArticleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ArticleRepository::class)]
class Article
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'articles')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Colis $colis = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Choisissez le type de marchandise.')]
    private ?string $typeMarchandise = null;

    /** Poids (kg) ou volume (m³) selon le mode de transport ; facultatif (pesée faite plus tard, ou prix au forfait). */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Assert\Positive(message: 'Le poids doit être supérieur à 0.')]
    private ?string $poidsVolume = null;

    /** Nombre de colis (cartons, valises…) de cette ligne : « Vêtements — 3 colis ». */
    #[ORM\Column(options: ['default' => 1])]
    #[Assert\NotNull(message: 'Indiquez le nombre de colis.')]
    #[Assert\Range(notInRangeMessage: 'Le nombre de colis doit être entre {{ min }} et {{ max }}.', min: 1, max: 999)]
    private ?int $quantite = 1;

    /** Prix de l'article en centimes. */
    #[ORM\Column]
    #[Assert\NotNull(message: "Indiquez le prix de l'article.")]
    #[Assert\PositiveOrZero(message: 'Le prix ne peut pas être négatif.')]
    private ?int $prix = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $observations = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo = null;

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

    public function getTypeMarchandise(): ?string
    {
        return $this->typeMarchandise;
    }

    public function setTypeMarchandise(?string $typeMarchandise): static
    {
        $this->typeMarchandise = $typeMarchandise;

        return $this;
    }

    public function getQuantite(): int
    {
        return $this->quantite ?? 1;
    }

    public function setQuantite(?int $quantite): static
    {
        $this->quantite = $quantite;

        return $this;
    }

    public function getPoidsVolume(): ?string
    {
        return $this->poidsVolume;
    }

    public function setPoidsVolume(string|float|null $poidsVolume): static
    {
        $this->poidsVolume = null === $poidsVolume ? null : (string) $poidsVolume;

        return $this;
    }

    public function getPrix(): int
    {
        return $this->prix ?? 0;
    }

    /** Valeur brute du formulaire (null si le champ est vide). */
    public function getPrixSaisi(): ?int
    {
        return $this->prix;
    }

    public function setPrix(?int $prix): static
    {
        $this->prix = $prix;

        return $this;
    }

    public function getObservations(): ?string
    {
        return $this->observations;
    }

    public function setObservations(?string $observations): static
    {
        $this->observations = $observations;

        return $this;
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
}
