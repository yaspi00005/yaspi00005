<?php

namespace App\Entity;

use App\Repository\ClientRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ClientRepository::class)]
#[ORM\Index(columns: ['telephone'])]
#[ORM\Index(columns: ['nom', 'prenom'])]
#[ORM\HasLifecycleCallbacks]
class Client
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank(message: "Indiquez le nom du client.")]
    private ?string $nom = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank(message: "Indiquez le prénom du client.")]
    private ?string $prenom = null;

    #[ORM\Column(length: 30)]
    #[Assert\NotBlank(message: "Indiquez le téléphone du client.")]
    #[Assert\Regex('/^\+?[0-9 .\-]{6,20}$/', message: 'Numéro de téléphone invalide.')]
    private ?string $telephone = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    private ?string $email = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $adresse = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $ville = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $codePostal = null;

    #[ORM\Column(length: 60)]
    private string $pays = 'France';

    /** Solde de bonus fidélité (en centimes), alimenté/consommé via MouvementBonus. */
    #[ORM\Column]
    private int $soldeBonus = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    /** Position GPS du domicile (degrés décimaux, WGS84). */
    #[ORM\Column(nullable: true)]
    #[Assert\Range(notInRangeMessage: 'Latitude invalide.', min: -90, max: 90)]
    private ?float $latitude = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(notInRangeMessage: 'Longitude invalide.', min: -180, max: 180)]
    private ?float $longitude = null;

    /** Origine de la position : « gps » (relevée sur place) ou « adresse » (déduite de l'adresse). */
    #[ORM\Column(length: 10, nullable: true)]
    #[Assert\Choice(choices: [self::POSITION_GPS, self::POSITION_ADRESSE], message: 'Origine de la position invalide.')]
    private ?string $positionSource = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $positionMajLe = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, Colis> */
    #[ORM\OneToMany(targetEntity: Colis::class, mappedBy: 'client')]
    #[ORM\OrderBy(['dateReception' => 'DESC'])]
    private Collection $colis;

    /** @var Collection<int, MouvementBonus> */
    #[ORM\OneToMany(targetEntity: MouvementBonus::class, mappedBy: 'client', orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $mouvementsBonus;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->colis = new ArrayCollection();
        $this->mouvementsBonus = new ArrayCollection();
    }

    public const POSITION_GPS = 'gps';
    public const POSITION_ADRESSE = 'adresse';

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(?string $nom): static
    {
        $this->nom = null === $nom ? null : mb_strtoupper(trim($nom), 'UTF-8');

        return $this;
    }

    public function getPrenom(): ?string
    {
        return $this->prenom;
    }

    public function setPrenom(?string $prenom): static
    {
        $this->prenom = null === $prenom ? null : mb_convert_case(trim($prenom), MB_CASE_TITLE, 'UTF-8');

        return $this;
    }

    public function getNomComplet(): string
    {
        return trim($this->prenom.' '.$this->nom);
    }

    public function getInitiales(): string
    {
        return mb_strtoupper(mb_substr((string) $this->prenom, 0, 1).mb_substr((string) $this->nom, 0, 1));
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): static
    {
        $this->telephone = null === $telephone ? null : trim($telephone);

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email ?: null;

        return $this;
    }

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function setAdresse(?string $adresse): static
    {
        $this->adresse = $adresse;

        return $this;
    }

    public function getVille(): ?string
    {
        return $this->ville;
    }

    public function setVille(?string $ville): static
    {
        $this->ville = $ville;

        return $this;
    }

    public function getCodePostal(): ?string
    {
        return $this->codePostal;
    }

    public function setCodePostal(?string $codePostal): static
    {
        $this->codePostal = $codePostal;

        return $this;
    }

    public function getPays(): string
    {
        return $this->pays;
    }

    public function setPays(?string $pays): static
    {
        $this->pays = $pays ?: 'France';

        return $this;
    }

    public function getSoldeBonus(): int
    {
        return $this->soldeBonus;
    }

    public function setSoldeBonus(int $soldeBonus): static
    {
        $this->soldeBonus = max(0, $soldeBonus);

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return Collection<int, Colis> */
    public function getColis(): Collection
    {
        return $this->colis;
    }

    /** @return Collection<int, MouvementBonus> */
    public function getMouvementsBonus(): Collection
    {
        return $this->mouvementsBonus;
    }

    public function addMouvementBonus(MouvementBonus $mouvement): static
    {
        if (!$this->mouvementsBonus->contains($mouvement)) {
            $this->mouvementsBonus->add($mouvement);
            $mouvement->setClient($this);
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->getNomComplet().' ('.$this->telephone.')';
    }

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function setLatitude(?float $latitude): static
    {
        if ($latitude !== $this->latitude) {
            $this->latitude = null === $latitude ? null : round($latitude, 7);
            $this->positionMajLe = null === $latitude ? null : new \DateTimeImmutable();
        }

        return $this;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function setLongitude(?float $longitude): static
    {
        if ($longitude !== $this->longitude) {
            $this->longitude = null === $longitude ? null : round($longitude, 7);
            $this->positionMajLe = null === $longitude ? null : new \DateTimeImmutable();
        }

        return $this;
    }

    public function getPositionSource(): ?string
    {
        return $this->positionSource;
    }

    public function setPositionSource(?string $positionSource): static
    {
        $this->positionSource = $positionSource ?: null;

        return $this;
    }

    public function getPositionMajLe(): ?\DateTimeImmutable
    {
        return $this->positionMajLe;
    }

    public function hasPosition(): bool
    {
        return null !== $this->latitude && null !== $this->longitude;
    }

    /** Enregistre une position (null, null pour l'effacer). */
    public function positionner(?float $latitude, ?float $longitude, ?string $source): static
    {
        $this->setLatitude($latitude)->setLongitude($longitude);
        $this->positionSource = null === $latitude || null === $longitude ? null : $source;

        return $this;
    }

    /** Adresse postale sur une ligne (pour la navigation quand il n'y a pas de position). */
    public function getAdresseComplete(): string
    {
        return trim(preg_replace('/\s+/', ' ', implode(', ', array_filter([$this->adresse, trim($this->codePostal.' '.$this->ville), $this->pays]))));
    }
}
