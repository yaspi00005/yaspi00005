<?php

namespace App\Entity;

use App\Enum\StatutColis;
use App\Enum\StatutPaiement;
use App\Enum\TypeExpedition;
use App\Repository\ColisRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ColisRepository::class)]
#[ORM\Index(columns: ['statut'])]
#[ORM\Index(columns: ['statut_paiement'])]
#[ORM\Index(columns: ['date_reception'])]
#[ORM\HasLifecycleCallbacks]
class Colis
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30, unique: true)]
    private ?string $numeroSuivi = null;

    /** Code remis au client pour retirer son colis à l'agence. */
    #[ORM\Column(length: 12, unique: true)]
    private ?string $codeRetrait = null;

    /** Jeton non devinable utilisé dans le lien public de suivi et le QR code. */
    #[ORM\Column(length: 64, unique: true)]
    private ?string $token = null;

    /** Client expéditeur (en France). */
    #[ORM\ManyToOne(inversedBy: 'colis')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'Choisissez un client.')]
    private ?Client $client = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Indiquez le nom du destinataire.')]
    private ?string $destinataireNom = null;

    #[ORM\Column(length: 30)]
    #[Assert\NotBlank(message: 'Indiquez le téléphone du destinataire.')]
    #[Assert\Regex('/^\+?[0-9 .\-]{6,20}$/', message: 'Numéro de téléphone invalide.')]
    private ?string $destinataireTelephone = null;

    #[ORM\ManyToOne(inversedBy: 'colis')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Expedition $expedition = null;

    /** Agent qui a enregistré le colis. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $agent = null;

    #[ORM\Column(length: 20, enumType: TypeExpedition::class)]
    private TypeExpedition $typeExpedition = TypeExpedition::Standard;

    /** Montant brut en centimes (somme des articles, modifiable par un admin). */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $frais = 0;

    /** Remise totale accordée en centimes (remises manuelles + bonus). */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $remise = 0;

    /** Total encaissé en centimes (maintenu par PaiementService). */
    #[ORM\Column]
    private int $montantPaye = 0;

    #[ORM\Column(length: 20, enumType: StatutColis::class)]
    private StatutColis $statut = StatutColis::EnPreparation;

    #[ORM\Column(length: 20, enumType: StatutPaiement::class)]
    private StatutPaiement $statutPaiement = StatutPaiement::NonPaye;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $dateReception;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateRecuperation = null;

    /** Nom de la personne qui a retiré le colis à l'agence. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $recupererPar = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $observations = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, Article> */
    #[ORM\OneToMany(targetEntity: Article::class, mappedBy: 'colis', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    #[Assert\Count(min: 1, minMessage: 'Ajoutez au moins un article.')]
    #[Assert\Valid]
    private Collection $articles;

    /** @var Collection<int, Paiement> */
    #[ORM\OneToMany(targetEntity: Paiement::class, mappedBy: 'colis', cascade: ['remove'])]
    #[ORM\OrderBy(['date' => 'ASC'])]
    private Collection $paiements;

    /** @var Collection<int, HistoriqueStatut> */
    #[ORM\OneToMany(targetEntity: HistoriqueStatut::class, mappedBy: 'colis', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['date' => 'ASC'])]
    private Collection $historique;

    public function __construct()
    {
        $this->dateReception = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->articles = new ArrayCollection();
        $this->paiements = new ArrayCollection();
        $this->historique = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumeroSuivi(): ?string
    {
        return $this->numeroSuivi;
    }

    public function setNumeroSuivi(string $numeroSuivi): static
    {
        $this->numeroSuivi = $numeroSuivi;

        return $this;
    }

    public function getCodeRetrait(): ?string
    {
        return $this->codeRetrait;
    }

    public function setCodeRetrait(string $codeRetrait): static
    {
        $this->codeRetrait = $codeRetrait;

        return $this;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function setToken(string $token): static
    {
        $this->token = $token;

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

    public function getDestinataireNom(): ?string
    {
        return $this->destinataireNom;
    }

    public function setDestinataireNom(?string $destinataireNom): static
    {
        $this->destinataireNom = null === $destinataireNom ? null : trim($destinataireNom);

        return $this;
    }

    public function getDestinataireTelephone(): ?string
    {
        return $this->destinataireTelephone;
    }

    public function setDestinataireTelephone(?string $destinataireTelephone): static
    {
        $this->destinataireTelephone = null === $destinataireTelephone ? null : trim($destinataireTelephone);

        return $this;
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

    public function getAgent(): ?User
    {
        return $this->agent;
    }

    public function setAgent(?User $agent): static
    {
        $this->agent = $agent;

        return $this;
    }

    public function getTypeExpedition(): TypeExpedition
    {
        return $this->typeExpedition;
    }

    public function setTypeExpedition(TypeExpedition $typeExpedition): static
    {
        $this->typeExpedition = $typeExpedition;

        return $this;
    }

    public function getFrais(): int
    {
        return $this->frais;
    }

    public function setFrais(int $frais): static
    {
        $this->frais = $frais;

        return $this;
    }

    public function getRemise(): int
    {
        return $this->remise;
    }

    public function setRemise(int $remise): static
    {
        $this->remise = $remise;

        return $this;
    }

    public function getMontantPaye(): int
    {
        return $this->montantPaye;
    }

    public function setMontantPaye(int $montantPaye): static
    {
        $this->montantPaye = $montantPaye;

        return $this;
    }

    /** Montant dû après remise. */
    public function getMontantNet(): int
    {
        return max(0, $this->frais - $this->remise);
    }

    public function getMontantRestant(): int
    {
        return max(0, $this->getMontantNet() - $this->montantPaye);
    }

    public function isSolde(): bool
    {
        return 0 === $this->getMontantRestant();
    }

    public function getStatut(): StatutColis
    {
        return $this->statut;
    }

    public function setStatut(StatutColis $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    public function getStatutPaiement(): StatutPaiement
    {
        return $this->statutPaiement;
    }

    public function setStatutPaiement(StatutPaiement $statutPaiement): static
    {
        $this->statutPaiement = $statutPaiement;

        return $this;
    }

    /** Recalcule le statut de paiement à partir des montants. */
    public function rafraichirStatutPaiement(): static
    {
        $this->statutPaiement = match (true) {
            $this->isSolde() && ($this->montantPaye > 0 || $this->remise > 0 || 0 === $this->frais) => StatutPaiement::Paye,
            $this->montantPaye > 0 || $this->remise > 0 => StatutPaiement::Partiel,
            default => StatutPaiement::NonPaye,
        };

        return $this;
    }

    public function getDateReception(): \DateTimeImmutable
    {
        return $this->dateReception;
    }

    public function setDateReception(\DateTimeImmutable $dateReception): static
    {
        $this->dateReception = $dateReception;

        return $this;
    }

    public function getDateRecuperation(): ?\DateTimeImmutable
    {
        return $this->dateRecuperation;
    }

    public function setDateRecuperation(?\DateTimeImmutable $dateRecuperation): static
    {
        $this->dateRecuperation = $dateRecuperation;

        return $this;
    }

    public function getRecupererPar(): ?string
    {
        return $this->recupererPar;
    }

    public function setRecupererPar(?string $recupererPar): static
    {
        $this->recupererPar = $recupererPar;

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

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return Collection<int, Article> */
    public function getArticles(): Collection
    {
        return $this->articles;
    }

    public function addArticle(Article $article): static
    {
        if (!$this->articles->contains($article)) {
            $this->articles->add($article);
            $article->setColis($this);
        }

        return $this;
    }

    public function removeArticle(Article $article): static
    {
        if ($this->articles->removeElement($article) && $article->getColis() === $this) {
            $article->setColis(null);
        }

        return $this;
    }

    public function getNombreArticles(): int
    {
        return $this->articles->count();
    }

    public function getPoidsTotal(): float
    {
        return round(array_sum($this->articles->map(fn (Article $a) => (float) $a->getPoidsVolume())->toArray()), 2);
    }

    public function getTotalArticles(): int
    {
        return array_sum($this->articles->map(fn (Article $a) => $a->getPrix())->toArray());
    }

    /** @return Collection<int, Paiement> */
    public function getPaiements(): Collection
    {
        return $this->paiements;
    }

    /** @return Collection<int, HistoriqueStatut> */
    public function getHistorique(): Collection
    {
        return $this->historique;
    }

    public function addHistorique(HistoriqueStatut $historique): static
    {
        if (!$this->historique->contains($historique)) {
            $this->historique->add($historique);
            $historique->setColis($this);
        }

        return $this;
    }

    /** Seuls les colis encore en préparation et sans paiement peuvent être supprimés. */
    public function isSupprimable(): bool
    {
        return StatutColis::EnPreparation === $this->statut && 0 === $this->montantPaye;
    }

    public function isRecuperable(): bool
    {
        return StatutColis::Arrive === $this->statut && $this->isSolde();
    }

    public function __toString(): string
    {
        return (string) $this->numeroSuivi;
    }
}
