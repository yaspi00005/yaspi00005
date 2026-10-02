<?php

namespace App\Entity;

use App\Repository\NouveauteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** Annonce affichée sur sotrama.net : nouvelle destination à venir, nouveau service, information… */
#[ORM\Entity(repositoryClass: NouveauteRepository::class)]
class Nouveaute
{
    public const ETIQUETTES = ['Bientôt' => 'bientot', 'Nouveau' => 'nouveau', 'Information' => 'info', 'Promotion' => 'promo'];
    public const ICONES = [
        'Destination (repère)' => 'fa-map-marker-alt', 'Avion' => 'fa-plane', 'Bateau / conteneur' => 'fa-ship', 'Camion' => 'fa-truck',
        'Boutique' => 'fa-store', 'Étoile' => 'fa-star', 'Annonce' => 'fa-bullhorn', 'Cadeau' => 'fa-gift', 'Globe' => 'fa-globe-africa',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Indiquez un titre (ex. : Kayes, bientôt desservie).')]
    #[Assert\Length(max: 120)]
    private ?string $titre = null;

    #[ORM\Column(length: 600, nullable: true)]
    #[Assert\Length(max: 600)]
    private ?string $texte = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::ETIQUETTES)]
    private string $etiquette = 'bientot';

    #[ORM\Column(length: 40)]
    #[Assert\Choice(choices: self::ICONES)]
    private string $icone = 'fa-map-marker-alt';

    /** Lien facultatif (page du site ou adresse externe). */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    #[Assert\Regex('#^(https?://|/)#', message: 'Le lien doit commencer par https:// ou /.')]
    private ?string $lien = null;

    /** Après cette date, l'annonce n'est plus affichée. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateFin = null;

    #[ORM\Column]
    private int $ordre = 0;

    #[ORM\Column]
    private bool $publiee = true;

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

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(?string $titre): static
    {
        $this->titre = $titre ? trim($titre) : null;

        return $this;
    }

    public function getTexte(): ?string
    {
        return $this->texte;
    }

    public function setTexte(?string $texte): static
    {
        $this->texte = $texte ? trim($texte) : null;

        return $this;
    }

    public function getEtiquette(): string
    {
        return $this->etiquette;
    }

    public function setEtiquette(?string $etiquette): static
    {
        $this->etiquette = $etiquette ?: 'bientot';

        return $this;
    }

    public function getLibelleEtiquette(): string
    {
        return array_search($this->etiquette, self::ETIQUETTES, true) ?: '';
    }

    public function getIcone(): string
    {
        return $this->icone;
    }

    public function setIcone(?string $icone): static
    {
        $this->icone = $icone ?: 'fa-map-marker-alt';

        return $this;
    }

    public function getLien(): ?string
    {
        return $this->lien;
    }

    public function setLien(?string $lien): static
    {
        $this->lien = $lien ? trim($lien) : null;

        return $this;
    }

    public function getDateFin(): ?\DateTimeImmutable
    {
        return $this->dateFin;
    }

    public function setDateFin(?\DateTimeImmutable $dateFin): static
    {
        $this->dateFin = $dateFin;

        return $this;
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

    public function isPubliee(): bool
    {
        return $this->publiee;
    }

    public function setPubliee(bool $publiee): static
    {
        $this->publiee = $publiee;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Visible sur le site : publiée et date de fin non dépassée. */
    public function isVisible(): bool
    {
        return $this->publiee && (!$this->dateFin || $this->dateFin >= new \DateTimeImmutable('today'));
    }
}
