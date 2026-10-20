<?php

namespace App\Service;

use App\Entity\Colis;
use App\Entity\Notification;
use App\Enum\StatutColis;
use App\Service\Sms\SmsSenderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Prévient le client (e-mail) et le destinataire (SMS) des étapes de son colis.
 * Chaque envoi est journalisé ; un échec d'envoi ne bloque jamais l'opération métier.
 */
final class NotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface $mailer,
        private readonly SmsSenderInterface $sms,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
        private readonly string $appNom,
        private readonly string $emailExpediteur,
    ) {
    }

    public function colisEnregistre(Colis $colis): void
    {
        $this->emailClient($colis, 'Votre colis '.$colis->getNumeroSuivi().' est enregistré');
    }

    public function statutChange(Colis $colis): void
    {
        $lien = $this->lienSuivi($colis);
        $message = match ($colis->getStatut()) {
            StatutColis::Arrive => \sprintf(
                "%s : votre colis %s est arrivé à l'agence. Code de retrait : %s. Reste à payer : %s. Suivi : %s",
                $this->appNom, $colis->getNumeroSuivi(), $colis->getCodeRetrait(),
                PaiementService::format($colis->getMontantRestant()), $lien,
            ),
            StatutColis::ArriveDakar => \sprintf(
                '%s : le colis %s pour vous est arrivé au port de Dakar ; il part ensuite par la route vers Bamako. Suivi : %s',
                $this->appNom, $colis->getNumeroSuivi(), $lien,
            ),
            StatutColis::TransitBamako => \sprintf(
                '%s : le colis %s pour vous est en route de Dakar vers Bamako. Suivi : %s',
                $this->appNom, $colis->getNumeroSuivi(), $lien,
            ),
            StatutColis::Expedie, StatutColis::EnTransit => \sprintf(
                '%s : le colis %s pour vous est %s. Suivi : %s',
                $this->appNom, $colis->getNumeroSuivi(), mb_strtolower($colis->getStatut()->label()), $lien,
            ),
            default => null,
        };

        if ($message) {
            $this->envoyerSms($colis, $colis->getDestinataireTelephone(), $message);
        }
        $this->emailClient($colis, 'Colis '.$colis->getNumeroSuivi().' : '.$colis->getStatut()->label());
    }

    public function lienSuivi(Colis $colis): string
    {
        return $this->urls->generate('suivi_colis', ['token' => $colis->getToken()], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function envoyerSms(Colis $colis, ?string $telephone, string $message): void
    {
        if (!$telephone) {
            return;
        }
        $telephone = $this->normaliserTelephone($telephone, $colis);
        $log = new Notification(Notification::CANAL_SMS, $telephone, $message, $colis);
        try {
            $this->sms->send($telephone, $message);
            $log->setSucces(true);
        } catch (\Throwable $e) {
            $log->setErreur($e->getMessage());
            $this->logger->warning('Échec SMS : '.$e->getMessage());
        }
        $this->em->persist($log);
        $this->em->flush();
    }

    private function emailClient(Colis $colis, string $sujet): void
    {
        $email = $colis->getClient()?->getEmail();
        if (!$email) {
            return;
        }
        $log = new Notification(Notification::CANAL_EMAIL, $email, $sujet, $colis);
        try {
            $this->mailer->send((new TemplatedEmail())
                ->from(new Address($this->emailExpediteur, $this->appNom))
                ->to($email)
                ->subject($sujet)
                ->htmlTemplate('email/statut_colis.html.twig')
                ->context(['colis' => $colis, 'lien' => $this->lienSuivi($colis), 'app_nom' => $this->appNom]));
            $log->setSucces(true);
        } catch (\Throwable $e) {
            $log->setErreur($e->getMessage());
            $this->logger->warning('Échec e-mail : '.$e->getMessage());
        }
        $this->em->persist($log);
        $this->em->flush();
    }

    /** Ajoute l'indicatif du pays de destination aux numéros locaux. */
    private function normaliserTelephone(string $telephone, Colis $colis): string
    {
        $tel = preg_replace('/[^0-9+]/', '', $telephone);
        if (str_starts_with($tel, '00')) {
            return '+'.substr($tel, 2);
        }
        if (!str_starts_with($tel, '+') && $dest = $colis->getExpedition()?->getDestination()) {
            return $dest->indicatif().ltrim($tel, '0');
        }

        return $tel;
    }
}
