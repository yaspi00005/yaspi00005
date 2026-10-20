<?php

namespace App\Entity;

use App\Repository\AppelRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Registre des appels : un client appelle pour un envoi. L'appel est rattaché à un conteneur (expédition)
 * et à un camion ; avec une date de ramassage, il devient un ramassage du planning (voir App\Service\RegistreAppels).
 */
#[ORM\Entity(repositoryClass: AppelRepository::class)]
#[ORM\Index(columns: ['date_appel'])]
class Appel
{
    public const SOURCE_TELEPHONE = 'telephone';
    public const SOURCE_SITE = 'site';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Personne de l'équipe qui a pris l'appel. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $utilisateur = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: "Indiquez la date de l'appel.")]
    private ?\DateTimeImmutable $dateAppel = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Client $client = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Indiquez le nom du client.')]
    #[Assert\Length(max: 100)]
    private ?string $nom = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $prenom = null;

    #[ORM\Column(length: 30)]
    #[Assert\NotBlank(message: 'Indiquez le téléphone.')]
    #[Assert\Regex('/^\+?[0-9 .\-]{6,20}$/', message: 'Numéro de téléphone invalide.')]
    private ?string $telephone = null;

    #[ORM\Column(length: 10)]
    #[Assert\NotBlank(message: 'Indiquez le code postal.')]
    #[Assert\Length(max: 10)]
    private ?string $codePostal = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Indiquez la ville.')]
    #[Assert\Length(max: 100)]
    private ?string $ville = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $adresse = null;

    /** Conteneur prévu : l'expédition (sa date de départ). Ancienne date libre gardée pour l'historique. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Expedition $expedition = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateConteneur = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Camion $camion = null;

    /** Estimation du prix annoncée au client, en centimes. */
    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero(message: "L'estimation ne peut pas être négative.")]
    private ?int $estimation = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000)]
    private ?string $commentaire = null;

    #[ORM\Column]
    private bool $confirme = false;

    #[ORM\Column(length: 20, options: ['default' => self::SOURCE_TELEPHONE])]
    private string $source = self::SOURCE_TELEPHONE;

    /** Ramassage du planning créé à partir de l'appel (date de ramassage renseignée). */
    #[ORM\OneToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Ramassage $ramassage = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->dateAppel = new \DateTimeImmutable('today');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUtilisateur(): ?User
    {
        return $this->utilisateur;
    }

    public function setUtilisateur(?User $utilisateur): static
    {
        $this->utilisateur = $utilisateur;

        return $this;
    }

    public function getDateAppel(): ?\DateTimeImmutable
    {
        return $this->dateAppel;
    }

    public function setDateAppel(?\DateTimeImmutable $dateAppel): static
    {
        $this->dateAppel = $dateAppel;

        return $this;
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(?Client $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(?string $nom): static
    {
        // Comme la fiche client : nom en majuscules, prénom avec une capitale.
        $this->nom = null === $nom ? null : mb_strtoupper(trim(preg_replace('/\s+/', ' ', $nom)), 'UTF-8');

        return $this;
    }

    public function getPrenom(): ?string
    {
        return $this->prenom;
    }

    public function setPrenom(?string $prenom): static
    {
        $this->prenom = $prenom ? mb_convert_case(trim(preg_replace('/\s+/', ' ', $prenom)), \MB_CASE_TITLE, 'UTF-8') : null;

        return $this;
    }

    public function getNomComplet(): string
    {
        return trim($this->prenom.' '.$this->nom);
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

    public function getCodePostal(): ?string
    {
        return $this->codePostal;
    }

    public function setCodePostal(?string $codePostal): static
    {
        $this->codePostal = null === $codePostal ? null : trim($codePostal);

        return $this;
    }

    public function getVille(): ?string
    {
        return $this->ville;
    }

    public function setVille(?string $ville): static
    {
        $this->ville = null === $ville ? null : trim($ville);

        return $this;
    }

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function setAdresse(?string $adresse): static
    {
        $this->adresse = $adresse ? trim($adresse) : null;

        return $this;
    }

    /** « 10 rue de la Paix, 75002 Paris » */
    public function getAdresseComplete(): string
    {
        return trim(($this->adresse ? $this->adresse.', ' : '').$this->codePostal.' '.$this->ville);
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

    public function getDateConteneur(): ?\DateTimeImmutable
    {
        return $this->dateConteneur;
    }

    public function setDateConteneur(?\DateTimeImmutable $dateConteneur): static
    {
        $this->dateConteneur = $dateConteneur;

        return $this;
    }

    /** Date du conteneur : départ de l'expédition choisie (date libre des anciens appels sinon). */
    public function getDateConteneurPrevue(): ?\DateTimeImmutable
    {
        return $this->expedition?->getDateDepart() ?? $this->dateConteneur;
    }

    public function getCamion(): ?Camion
    {
        return $this->camion;
    }

    public function setCamion(?Camion $camion): static
    {
        $this->camion = $camion;

        return $this;
    }

    public function getEstimation(): ?int
    {
        return $this->estimation;
    }

    public function setEstimation(?int $estimation): static
    {
        $this->estimation = $estimation;

        return $this;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function setCommentaire(?string $commentaire): static
    {
        $this->commentaire = $commentaire ? trim($commentaire) : null;

        return $this;
    }

    public function isConfirme(): bool
    {
        return $this->confirme;
    }

    public function setConfirme(bool $confirme): static
    {
        $this->confirme = $confirme;

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getRamassage(): ?Ramassage
    {
        return $this->ramassage;
    }

    public function setRamassage(?Ramassage $ramassage): static
    {
        $this->ramassage = $ramassage;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** État affiché dans le registre : [libellé, couleur du badge]. */
    /** Archivé : ramassage effectué ou annulé, ou conteneur parti (même règle que AppelRepository::ARCHIVE). */
    public function isArchive(): bool
    {
        $r = $this->ramassage;
        if ($r && \in_array($r->getStatut(), [\App\Enum\StatutRamassage::Effectue, \App\Enum\StatutRamassage::Annule], true)) {
            return true;
        }
        if ($this->expedition) {
            return \App\Enum\StatutExpedition::EnPreparation !== $this->expedition->getStatut();
        }

        return null !== $this->dateConteneur && $this->dateConteneur < new \DateTimeImmutable('today');
    }

    public function getEtat(): array
    {
        $r = $this->ramassage;

        return match (true) {
            null !== $r && \App\Enum\StatutRamassage::Effectue === $r->getStatut() => ['Ramassé', 'success'],
            null !== $r && \App\Enum\StatutRamassage::Annule === $r->getStatut() => ['Ramassage annulé', 'danger'],
            null !== $r => ['Planifié le '.$r->getDatePrevue()->format('d/m'), 'info'],
            $this->confirme => ['À planifier', 'warning'],
            default => ['Non confirmé', 'light'],
        };
    }
}
