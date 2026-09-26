<?php

namespace App\Entity;

use App\Repository\PushAbonnementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Abonnement d'un navigateur aux notifications (Web Push). Un utilisateur peut en avoir plusieurs. */
#[ORM\Entity(repositoryClass: PushAbonnementRepository::class)]
class PushAbonnement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $utilisateur;

    #[ORM\Column(type: Types::TEXT)]
    private string $endpoint;

    /** Empreinte de l'endpoint, pour l'unicité (les endpoints sont trop longs pour un index). */
    #[ORM\Column(length: 64, unique: true)]
    private string $empreinte;

    #[ORM\Column(length: 255)]
    private string $cleP256dh;

    #[ORM\Column(length: 255)]
    private string $cleAuth;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $navigateur = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $utilisateur, string $endpoint, string $cleP256dh, string $cleAuth, ?string $navigateur = null)
    {
        $this->utilisateur = $utilisateur;
        $this->endpoint = $endpoint;
        $this->empreinte = self::empreinte($endpoint);
        $this->cleP256dh = $cleP256dh;
        $this->cleAuth = $cleAuth;
        $this->navigateur = null === $navigateur ? null : mb_substr($navigateur, 0, 255);
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function empreinte(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUtilisateur(): User
    {
        return $this->utilisateur;
    }

    public function setUtilisateur(User $utilisateur): static
    {
        $this->utilisateur = $utilisateur;

        return $this;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function getCleP256dh(): string
    {
        return $this->cleP256dh;
    }

    public function setCles(string $cleP256dh, string $cleAuth): static
    {
        $this->cleP256dh = $cleP256dh;
        $this->cleAuth = $cleAuth;

        return $this;
    }

    public function getCleAuth(): string
    {
        return $this->cleAuth;
    }

    public function getNavigateur(): ?string
    {
        return $this->navigateur;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
