<?php

namespace App\Entity;

use App\Enum\Destination;
use App\Enum\ModeTransport;
use App\Enum\StatutExpedition;
use App\Repository\ExpeditionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ExpeditionRepository::class)]
#[ORM\Index(columns: ['statut'])]
class Expedition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30, unique: true)]
    private ?string $numero = null;

    #[ORM\Column(length: 20, enumType: ModeTransport::class)]
    #[Assert\NotNull]
    private ?ModeTransport $modeTransport = null;

    #[ORM\Column(length: 20, enumType: Destination::class)]
    #[Assert\NotNull]
    private ?Destination $destination = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $dateDepart = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    #[Assert\GreaterThanOrEqual(propertyPath: 'dateDepart', message: "L'arrivée prévue doit être après le départ.")]
    private ?\DateTimeImmutable $dateArriveePrevue = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateArrivee = null;

    #[ORM\Column(length: 20, enumType: StatutExpedition::class)]
    private StatutExpedition $statut = StatutExpedition::EnPreparation;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, Colis> */
    #[ORM\OneToMany(targetEntity: Colis::class, mappedBy: 'expedition')]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $colis;

    /** @var Collection<int, DepenseExpedition> */
    #[ORM\OneToMany(targetEntity: DepenseExpedition::class, mappedBy: 'expedition', cascade: ['remove'])]
    #[ORM\OrderBy(['date' => 'ASC', 'id' => 'ASC'])]
    private Collection $depenses;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->colis = new ArrayCollection();
        $this->depenses = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumero(): ?string
    {
        return $this->numero;
    }

    public function setNumero(string $numero): static
    {
        $this->numero = $numero;

        return $this;
    }

    public function getModeTransport(): ?ModeTransport
    {
        return $this->modeTransport;
    }

    public function setModeTransport(?ModeTransport $modeTransport): static
    {
        $this->modeTransport = $modeTransport;

        return $this;
    }

    public function getDestination(): ?Destination
    {
        return $this->destination;
    }

    public function setDestination(?Destination $destination): static
    {
        $this->destination = $destination;

        return $this;
    }

    public function getDateDepart(): ?\DateTimeImmutable
    {
        return $this->dateDepart;
    }

    public function setDateDepart(?\DateTimeImmutable $dateDepart): static
    {
        $this->dateDepart = $dateDepart;

        return $this;
    }

    public function getDateArriveePrevue(): ?\DateTimeImmutable
    {
        return $this->dateArriveePrevue;
    }

    public function setDateArriveePrevue(?\DateTimeImmutable $dateArriveePrevue): static
    {
        $this->dateArriveePrevue = $dateArriveePrevue;

        return $this;
    }

    public function getDateArrivee(): ?\DateTimeImmutable
    {
        return $this->dateArrivee;
    }

    public function setDateArrivee(?\DateTimeImmutable $dateArrivee): static
    {
        $this->dateArrivee = $dateArrivee;

        return $this;
    }

    public function getStatut(): StatutExpedition
    {
        return $this->statut;
    }

    public function setStatut(StatutExpedition $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): static
    {
        $this->reference = $reference;

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

    /** @return Collection<int, Colis> */
    public function getColis(): Collection
    {
        return $this->colis;
    }

    /** @return Collection<int, DepenseExpedition> */
    public function getDepenses(): Collection
    {
        return $this->depenses;
    }

    public function addDepense(DepenseExpedition $depense): static
    {
        if (!$this->depenses->contains($depense)) {
            $this->depenses->add($depense);
            $depense->setExpedition($this);
        }

        return $this;
    }

    /** Chiffre d'affaires : tarifs des colis moins les remises (centimes). */
    public function getChiffreAffaires(): int
    {
        return array_sum($this->colis->map(fn (Colis $c) => $c->getMontantNet())->toArray());
    }

    /** Déjà encaissé sur les colis de l'expédition (centimes). */
    public function getTotalEncaisse(): int
    {
        return array_sum($this->colis->map(fn (Colis $c) => $c->getMontantPaye())->toArray());
    }

    /** Total des charges (centimes). */
    public function getTotalDepenses(): int
    {
        return array_sum($this->depenses->map(fn (DepenseExpedition $d) => (int) $d->getMontant())->toArray());
    }

    /** Marge = chiffre d'affaires − charges (négative : perte). */
    public function getMarge(): int
    {
        return $this->getChiffreAffaires() - $this->getTotalDepenses();
    }

    /** Marge en % du chiffre d'affaires (null sans chiffre d'affaires). */
    public function getTauxMarge(): ?float
    {
        $ca = $this->getChiffreAffaires();

        return $ca > 0 ? round($this->getMarge() / $ca * 100, 1) : null;
    }

    public function getLibelle(): string
    {
        return \sprintf('%s · %s · %s',
            $this->numero,
            $this->destination?->label(),
            $this->dateDepart?->format('d/m/Y'),
        );
    }

    public function getMontantTotal(): int
    {
        return array_sum($this->colis->map(fn (Colis $c) => $c->getMontantNet())->toArray());
    }

    public function getMontantEncaisse(): int
    {
        return array_sum($this->colis->map(fn (Colis $c) => $c->getMontantPaye())->toArray());
    }

    public function getMontantRestant(): int
    {
        return max(0, $this->getMontantTotal() - $this->getMontantEncaisse());
    }

    public function getPoidsTotal(): float
    {
        return array_sum($this->colis->map(fn (Colis $c) => $c->getPoidsTotal())->toArray());
    }

    public function __toString(): string
    {
        return $this->getLibelle();
    }
}
