<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\PushAbonnement;
use App\Entity\User;
use App\Repository\PushAbonnementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Notifications du navigateur (Web Push, clés VAPID).
 * Les abonnements expirés (navigateur désinstallé, autorisation retirée) sont supprimés automatiquement.
 */
final class NotificationPush
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PushAbonnementRepository $abonnements,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(VAPID_PUBLIC_KEY)%')] private readonly string $clePublique,
        #[Autowire('%env(VAPID_PRIVATE_KEY)%')] private readonly string $clePrivee,
        #[Autowire('%env(VAPID_SUBJECT)%')] private readonly string $sujet,
    ) {
    }

    public function estConfiguree(): bool
    {
        return '' !== $this->clePublique && '' !== $this->clePrivee;
    }

    public function getClePublique(): string
    {
        return $this->clePublique;
    }

    /**
     * Sans les extensions PHP gmp ou bcmath, la bibliothèque émet un simple avertissement de performance
     * (E_USER_NOTICE) que Symfony transformerait en erreur en mode debug : on l'ignore, l'envoi fonctionne.
     */
    private function client(): WebPush
    {
        set_error_handler(fn (int $niveau, string $message) => \E_USER_NOTICE === $niveau && str_contains($message, 'GMP or BCMath'), \E_USER_NOTICE);
        try {
            return new WebPush(['VAPID' => [
                'subject' => $this->sujet ?: 'mailto:admin@example.com',
                'publicKey' => $this->clePublique,
                'privateKey' => $this->clePrivee,
            ]], ['TTL' => 3600 * 12]);
        } finally {
            restore_error_handler();
        }
    }

    public function abonner(User $user, string $endpoint, string $p256dh, string $auth, ?string $navigateur): PushAbonnement
    {
        $abonnement = $this->abonnements->findOneBy(['empreinte' => PushAbonnement::empreinte($endpoint)]);
        if ($abonnement) {
            // Même navigateur : on le rattache à l'utilisateur connecté (poste partagé).
            $abonnement->setUtilisateur($user)->setCles($p256dh, $auth);
        } else {
            $abonnement = new PushAbonnement($user, $endpoint, $p256dh, $auth, $navigateur);
            $this->em->persist($abonnement);
        }
        $this->em->flush();

        return $abonnement;
    }

    public function desabonner(User $user, string $endpoint): void
    {
        $abonnement = $this->abonnements->findOneBy(['empreinte' => PushAbonnement::empreinte($endpoint), 'utilisateur' => $user]);
        if ($abonnement) {
            $this->em->remove($abonnement);
            $this->em->flush();
        }
    }

    public function nombreAbonnements(User $user): int
    {
        return $this->abonnements->count(['utilisateur' => $user]);
    }

    /**
     * Envoie une notification à tous les navigateurs de l'utilisateur.
     *
     * @return int nombre de navigateurs atteints
     */
    public function envoyer(User $user, string $titre, string $corps, string $url, ?string $tag = null): int
    {
        $liste = $this->abonnements->findBy(['utilisateur' => $user]);
        if (!$liste || !$this->estConfiguree()) {
            return 0;
        }

        $webPush = $this->client();

        $charge = json_encode(['titre' => $titre, 'corps' => $corps, 'url' => $url, 'tag' => $tag], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $parEndpoint = [];
        foreach ($liste as $a) {
            $parEndpoint[$a->getEndpoint()] = $a;
            $webPush->queueNotification(Subscription::create([
                'endpoint' => $a->getEndpoint(),
                'publicKey' => $a->getCleP256dh(),
                'authToken' => $a->getCleAuth(),
                'contentEncoding' => 'aes128gcm', // format standard (RFC 8291) des navigateurs actuels
            ]), $charge);
        }

        $atteints = 0;
        foreach ($webPush->flush() as $rapport) {
            $log = new Notification(Notification::CANAL_PUSH, $user->getNomComplet(), $titre.' — '.$corps);
            if ($rapport->isSuccess()) {
                ++$atteints;
                $log->setSucces(true);
            } else {
                $log->setErreur($rapport->getReason());
                $this->logger->warning('Échec notification navigateur : '.$rapport->getReason());
                if ($rapport->isSubscriptionExpired() && isset($parEndpoint[$rapport->getEndpoint()])) {
                    $this->em->remove($parEndpoint[$rapport->getEndpoint()]);
                }
            }
            $this->em->persist($log);
        }
        $this->em->flush();

        return $atteints;
    }
}
