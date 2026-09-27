<?php

namespace App\Entity;

use App\Repository\JournalRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Journal d'activité : qui a créé, modifié, supprimé quoi, et quand (voir App\Journal\Journaliseur). */
#[ORM\Entity(repositoryClass: JournalRepository::class)]
#[ORM\Index(columns: ['date'])]
#[ORM\Index(columns: ['entite', 'entite_id'])]
#[ORM\Index(columns: ['colis_id'])]
#[ORM\Index(columns: ['client_id'])]
class Journal
{
    public const CREATION = 'creation';
    public const MODIFICATION = 'modification';
    public const SUPPRESSION = 'suppression';
    public const CONNEXION = 'connexion';
    public const ECHEC_CONNEXION = 'echec_connexion';
    public const DECONNEXION = 'deconnexion';
    public const EXPORT = 'export';

    public const ACTIONS = [
        self::CREATION => ['Ajout', 'success', 'fa-plus'],
        self::MODIFICATION => ['Modification', 'info', 'fa-pen'],
        self::SUPPRESSION => ['Suppression', 'danger', 'fa-trash'],
        self::CONNEXION => ['Connexion', 'primary', 'fa-sign-in-alt'],
        self::ECHEC_CONNEXION => ['Connexion refusée', 'warning', 'fa-user-lock'],
        self::DECONNEXION => ['Déconnexion', 'light', 'fa-sign-out-alt'],
        self::EXPORT => ['Export', 'dark', 'fa-file-export'],
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $utilisateur = null;

    /** Nom au moment de l'action (conservé si le compte est supprimé) ; « Système » pour les tâches automatiques. */
    #[ORM\Column(length: 120)]
    private string $utilisateurNom;

    #[ORM\Column(length: 20)]
    private string $action;

    /** Élément concerné : Colis, Client, Paiement… */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $entite = null;

    #[ORM\Column(nullable: true)]
    private ?int $entiteId = null;

    #[ORM\Column(length: 255)]
    private string $libelle;

    /** @var array<string, array{0: ?string, 1: ?string}>|null champ => [avant, après] */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $changements = null;

    /** Colis et client liés (historique affiché sur leurs fiches). */
    #[ORM\Column(nullable: true)]
    private ?int $colisId = null;

    #[ORM\Column(nullable: true)]
    private ?int $clientId = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    public function __construct(string $action, string $libelle, ?User $utilisateur, string $utilisateurNom)
    {
        $this->date = new \DateTimeImmutable();
        $this->action = $action;
        $this->libelle = mb_substr($libelle, 0, 255);
        $this->utilisateur = $utilisateur;
        $this->utilisateurNom = mb_substr($utilisateurNom, 0, 120);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getUtilisateur(): ?User
    {
        return $this->utilisateur;
    }

    public function getUtilisateurNom(): string
    {
        return $this->utilisateurNom;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getActionLibelle(): string
    {
        return self::ACTIONS[$this->action][0] ?? $this->action;
    }

    public function getActionCouleur(): string
    {
        return self::ACTIONS[$this->action][1] ?? 'light';
    }

    public function getActionIcone(): string
    {
        return self::ACTIONS[$this->action][2] ?? 'fa-circle';
    }

    public function getEntite(): ?string
    {
        return $this->entite;
    }

    public function getEntiteId(): ?int
    {
        return $this->entiteId;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function getChangements(): ?array
    {
        return $this->changements;
    }

    public function getColisId(): ?int
    {
        return $this->colisId;
    }

    public function getClientId(): ?int
    {
        return $this->clientId;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function concerne(?string $entite, ?int $id): static
    {
        $this->entite = $entite;
        $this->entiteId = $id;

        return $this;
    }

    public function lier(?int $colisId, ?int $clientId): static
    {
        $this->colisId = $colisId;
        $this->clientId = $clientId;

        return $this;
    }

    public function setChangements(?array $changements): static
    {
        $this->changements = $changements ?: null;

        return $this;
    }

    public function setIp(?string $ip): static
    {
        $this->ip = $ip;

        return $this;
    }
}
