<?php

namespace App\Entity;

use App\Repository\TemoignageClientRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** Témoignage d'un client affiché sur sotrama.net : vidéo (facultative) et/ou phrase. */
#[ORM\Entity(repositoryClass: TemoignageClientRepository::class)]
class TemoignageClient
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Indiquez le nom du client (ex. : Awa, Montreuil).')]
    #[Assert\Length(max: 100)]
    private ?string $nom = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $texte = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $video = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $apercu = null;

    #[ORM\Column]
    private int $ordre = 0;

    #[ORM\Column]
    private bool $publie = true;

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

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(?string $nom): static
    {
        $this->nom = $nom ? trim($nom) : null;

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

    public function getVideo(): ?string
    {
        return $this->video;
    }

    public function setVideo(?string $video): static
    {
        $this->video = $video;

        return $this;
    }

    public function getApercu(): ?string
    {
        return $this->apercu;
    }

    public function setApercu(?string $apercu): static
    {
        $this->apercu = $apercu;

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

    public function isPublie(): bool
    {
        return $this->publie;
    }

    public function setPublie(bool $publie): static
    {
        $this->publie = $publie;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Type MIME de la vidéo pour la balise <source>. */
    public function getTypeVideo(): string
    {
        return match (strtolower(pathinfo((string) $this->video, \PATHINFO_EXTENSION))) {
            'webm' => 'video/webm', 'mov' => 'video/quicktime', default => 'video/mp4',
        };
    }
}
