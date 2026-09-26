<?php

namespace App\Entity;

use App\Enum\StatutRamassage;
use App\Repository\RamassageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** Rendez-vous de ramassage chez un client (ancien « programmes »). */
#[ORM\Entity(repositoryClass: RamassageRepository::class)]
#[ORM\Index(columns: ['date_prevue'])]
class Ramassage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Choisissez un client.')]
    private ?Client $client = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $datePrevue = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $adresse = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $commentaire = null;

    #[ORM\Column(length: 20, enumType: StatutRamassage::class)]
    private StatutRamassage $statut = StatutRamassage::EnAttente;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $agent = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** Date d'envoi du rappel de la veille (null = pas encore envoyé). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $rappelVeilleEnvoyeLe = null;

    /** Date d'envoi du rappel « 1 h avant » (null = pas encore envoyé). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $rappelProcheEnvoyeLe = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getDatePrevue(): ?\DateTimeImmutable
    {
        return $this->datePrevue;
    }

    public function setDatePrevue(?\DateTimeImmutable $datePrevue): static
    {
        // Rendez-vous déplacé : les rappels seront renvoyés pour la nouvelle date.
        if ($this->datePrevue != $datePrevue) {
            $this->rappelVeilleEnvoyeLe = null;
            $this->rappelProcheEnvoyeLe = null;
        }
        $this->datePrevue = $datePrevue;

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

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function setCommentaire(?string $commentaire): static
    {
        $this->commentaire = $commentaire;

        return $this;
    }

    public function getStatut(): StatutRamassage
    {
        return $this->statut;
    }

    public function setStatut(?StatutRamassage $statut): static
    {
        $this->statut = $statut ?? StatutRamassage::EnAttente;

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getRappelVeilleEnvoyeLe(): ?\DateTimeImmutable
    {
        return $this->rappelVeilleEnvoyeLe;
    }

    public function setRappelVeilleEnvoyeLe(?\DateTimeImmutable $date): static
    {
        $this->rappelVeilleEnvoyeLe = $date;

        return $this;
    }

    public function getRappelProcheEnvoyeLe(): ?\DateTimeImmutable
    {
        return $this->rappelProcheEnvoyeLe;
    }

    public function setRappelProcheEnvoyeLe(?\DateTimeImmutable $date): static
    {
        $this->rappelProcheEnvoyeLe = $date;

        return $this;
    }
}
