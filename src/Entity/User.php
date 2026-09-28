<?php

namespace App\Entity;

use App\Enum\Destination;
use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'utilisateur')]
#[UniqueEntity(fields: ['username'], message: 'Cet identifiant est déjà utilisé.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    /** Mot de passe donné aux nouveaux comptes : l'utilisateur doit le changer à sa première connexion. */
    public const MOT_DE_PASSE_PROVISOIRE = 'Sotrama2025';

    public const ROLE_ADMIN = 'ROLE_ADMIN';
    public const ROLE_RAMASSAGE = 'ROLE_RAMASSAGE';
    public const ROLE_REPRESENTANT = 'ROLE_REPRESENTANT';

    public const ROLES = [
        'Administrateur' => self::ROLE_ADMIN,
        'Agent de ramassage (France)' => self::ROLE_RAMASSAGE,
        'Représentant pays (agence)' => self::ROLE_REPRESENTANT,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80, unique: true)]
    #[Assert\NotBlank(message: "Indiquez l'identifiant de connexion.")]
    #[Assert\Length(min: 3, max: 80, minMessage: "L'identifiant doit faire au moins 3 caractères.")]
    private ?string $username = null;

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank(message: "Indiquez le prénom.")]
    private ?string $prenom = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank(message: "Indiquez le nom.")]
    private ?string $nom = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    private ?string $email = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $telephone = null;

    /** Pays couvert par un représentant (null = tous pour un admin). */
    #[ORM\Column(length: 20, nullable: true, enumType: Destination::class)]
    private ?Destination $pays = null;

    #[ORM\Column]
    private bool $actif = true;

    /** Connecté avec le mot de passe provisoire (User::MOT_DE_PASSE_PROVISOIRE) : à changer avant tout. */
    #[ORM\Column(options: ['default' => false])]
    private bool $motDePasseProvisoire = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $derniereConnexion = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(?string $username): static
    {
        $this->username = null === $username ? null : mb_strtolower(trim($username));

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->username;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = array_values($roles);

        return $this;
    }

    /** Rôle principal, pour l'affichage et les formulaires. */
    public function getRolePrincipal(): ?string
    {
        foreach ([self::ROLE_ADMIN, self::ROLE_REPRESENTANT, self::ROLE_RAMASSAGE] as $role) {
            if (\in_array($role, $this->roles, true)) {
                return $role;
            }
        }

        return null;
    }

    public function setRolePrincipal(?string $role): static
    {
        $this->roles = $role ? [$role] : [];

        return $this;
    }

    public function getRoleLabel(): string
    {
        return array_search($this->getRolePrincipal(), self::ROLES, true) ?: 'Utilisateur';
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = hash('crc32c', (string) $this->password);

        return $data;
    }

    /**
     * Comparé à chaque requête avec la version en base : une session ouverte est fermée dès que le compte
     * est désactivé, que son rôle ou son pays change, ou que son mot de passe est modifié.
     * (Le mot de passe de la session est une empreinte, voir __serialize.)
     */
    public function isEqualTo(UserInterface $user): bool
    {
        if (!$user instanceof self || $user->getUserIdentifier() !== $this->getUserIdentifier() || !$user->isActif()) {
            return false;
        }
        $motDePasse = (string) $user->getPassword();
        if ($this->password !== $motDePasse && $this->password !== hash('crc32c', $motDePasse)) {
            return false;
        }
        $roles = $user->getRoles();
        sort($roles);
        $mesRoles = $this->getRoles();
        sort($mesRoles);

        return $roles === $mesRoles && $user->getPays() === $this->pays;
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
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

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(?string $nom): static
    {
        $this->nom = null === $nom ? null : mb_strtoupper(trim($nom), 'UTF-8');

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

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): static
    {
        $this->telephone = $telephone;

        return $this;
    }

    public function getPays(): ?Destination
    {
        return $this->pays;
    }

    public function setPays(?Destination $pays): static
    {
        $this->pays = $pays;

        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): static
    {
        $this->actif = $actif;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDerniereConnexion(): ?\DateTimeImmutable
    {
        return $this->derniereConnexion;
    }

    public function setDerniereConnexion(?\DateTimeImmutable $derniereConnexion): static
    {
        $this->derniereConnexion = $derniereConnexion;

        return $this;
    }

    public function __toString(): string
    {
        return $this->getNomComplet();
    }

    public function isMotDePasseProvisoire(): bool
    {
        return $this->motDePasseProvisoire;
    }

    public function setMotDePasseProvisoire(bool $provisoire): static
    {
        $this->motDePasseProvisoire = $provisoire;

        return $this;
    }

    /** Ce qu'il reste à faire avant d'utiliser l'application : changer le mot de passe provisoire, donner un e-mail. */
    public function isProfilAComplete(): bool
    {
        return $this->motDePasseProvisoire || null === $this->email || '' === trim($this->email);
    }
}
