<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\Ramassage;
use App\Entity\User;
use App\Enum\StatutRamassage;
use App\Repository\RamassageRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Rappels des ramassages à faire (« En attente » ou « Confirmé par le client ») : la veille, puis environ 1 h avant.
 * Destinataires : l'agent assigné, sinon tous les agents de ramassage actifs.
 * Canaux : notification du navigateur et e-mail. Chaque rappel n'est envoyé qu'une fois
 * (réinitialisé si le rendez-vous est déplacé).
 */
final class RappelRamassages
{
    public const VEILLE = 'veille';
    public const PROCHE = 'proche';

    /** Délai du rappel « proche » avant le rendez-vous. */
    public const DELAI_PROCHE = '+1 hour';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RamassageRepository $ramassages,
        private readonly UserRepository $users,
        private readonly NotificationPush $push,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
        private readonly string $appNom,
        private readonly string $emailExpediteur,
    ) {
    }

    /**
     * Envoie les rappels dus à l'instant donné.
     *
     * @return array{veille: int, proche: int}
     */
    public function executer(?\DateTimeImmutable $maintenant = null): array
    {
        $maintenant ??= new \DateTimeImmutable();
        $compte = [self::VEILLE => 0, self::PROCHE => 0];

        $aVenir = $this->ramassages->createQueryBuilder('r')
            ->addSelect('c', 'a')->join('r.client', 'c')->leftJoin('r.agent', 'a')
            ->andWhere('r.statut IN (:s)')->setParameter('s', StatutRamassage::aFaire())
            ->andWhere('r.datePrevue > :debut AND r.datePrevue <= :fin')
            ->setParameter('debut', $maintenant->modify('-15 minutes'))
            ->setParameter('fin', $maintenant->modify('+1 day'))
            ->orderBy('r.datePrevue', 'ASC')
            ->getQuery()->getResult();

        foreach ($aVenir as $ramassage) {
            /** @var Ramassage $ramassage */
            $date = $ramassage->getDatePrevue();

            if (!$ramassage->getRappelProcheEnvoyeLe() && $date <= $maintenant->modify(self::DELAI_PROCHE)) {
                $this->envoyer($ramassage, self::PROCHE);
                $ramassage->setRappelProcheEnvoyeLe($maintenant);
                // Trop tard pour le rappel de la veille : inutile de l'envoyer ensuite.
                $ramassage->setRappelVeilleEnvoyeLe($ramassage->getRappelVeilleEnvoyeLe() ?? $maintenant);
                ++$compte[self::PROCHE];
            } elseif (!$ramassage->getRappelVeilleEnvoyeLe() && $date > $maintenant->modify(self::DELAI_PROCHE)) {
                $this->envoyer($ramassage, self::VEILLE);
                $ramassage->setRappelVeilleEnvoyeLe($maintenant);
                ++$compte[self::VEILLE];
            }
            $this->em->flush();
        }

        return $compte;
    }

    /** @return User[] */
    public function destinataires(Ramassage $ramassage): array
    {
        $agent = $ramassage->getAgent();
        if ($agent && $agent->isActif()) {
            return [$agent];
        }

        return array_values(array_filter(
            $this->users->findBy(['actif' => true]),
            fn (User $u) => User::ROLE_RAMASSAGE === $u->getRolePrincipal(),
        ));
    }

    public function envoyer(Ramassage $ramassage, string $type): void
    {
        $client = $ramassage->getClient();
        $quand = self::VEILLE === $type
            ? 'Demain à '.$ramassage->getDatePrevue()->format('H:i')
            : 'À '.$ramassage->getDatePrevue()->format('H:i');
        $titre = 'Ramassage : '.$client->getNomComplet();
        $adresse = trim($ramassage->getAdresse() ?: $client->getAdresse().' '.$client->getCodePostal().' '.$client->getVille());
        $corps = $quand.' — '.$adresse.' — '.$client->getTelephone()
            .(StatutRamassage::Confirme === $ramassage->getStatut() ? ' — ✔ confirmé par le client' : '');
        $url = $this->urls->generate('ramassage_edit', ['id' => $ramassage->getId()], UrlGeneratorInterface::ABSOLUTE_URL);

        foreach ($this->destinataires($ramassage) as $user) {
            $this->push->envoyer($user, $titre, $corps, $url, 'ramassage-'.$ramassage->getId().'-'.$type);

            if ($user->getEmail()) {
                $log = new Notification(Notification::CANAL_EMAIL, $user->getEmail(), $titre.' ('.$quand.')');
                try {
                    $this->mailer->send((new TemplatedEmail())
                        ->from(new Address($this->emailExpediteur, $this->appNom))
                        ->to($user->getEmail())
                        ->subject('Rappel — '.$titre.' — '.$quand)
                        ->htmlTemplate('email/rappel_ramassage.html.twig')
                        ->context(['ramassage' => $ramassage, 'agent' => $user, 'quand' => $quand, 'adresse' => $adresse, 'lien' => $url, 'app_nom' => $this->appNom]));
                    $log->setSucces(true);
                } catch (\Throwable $e) {
                    $log->setErreur($e->getMessage());
                    $this->logger->warning('Échec e-mail de rappel : '.$e->getMessage());
                }
                $this->em->persist($log);
            }
        }
        $this->em->flush();
    }
}
