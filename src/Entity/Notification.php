<?php

namespace App\Entity;

use App\Repository\NotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Journal des SMS / e-mails / WhatsApp envoyés aux clients. */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
class Notification
{
    public const CANAL_SMS = 'sms';
    public const CANAL_EMAIL = 'email';
    public const CANAL_WHATSAPP = 'whatsapp';
    public const CANAL_PUSH = 'push';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Colis $colis = null;

    #[ORM\Column(length: 20)]
    private string $canal;

    #[ORM\Column(length: 180)]
    private string $destinataire;

    #[ORM\Column(type: Types::TEXT)]
    private string $message;

    #[ORM\Column]
    private bool $succes = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $erreur = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $canal, string $destinataire, string $message, ?Colis $colis = null)
    {
        $this->canal = $canal;
        $this->destinataire = $destinataire;
        $this->message = $message;
        $this->colis = $colis;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getColis(): ?Colis
    {
        return $this->colis;
    }

    public function getCanal(): string
    {
        return $this->canal;
    }

    public function getDestinataire(): string
    {
        return $this->destinataire;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function isSucces(): bool
    {
        return $this->succes;
    }

    public function setSucces(bool $succes): static
    {
        $this->succes = $succes;

        return $this;
    }

    public function getErreur(): ?string
    {
        return $this->erreur;
    }

    public function setErreur(?string $erreur): static
    {
        $this->erreur = null === $erreur ? null : mb_substr($erreur, 0, 255);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
