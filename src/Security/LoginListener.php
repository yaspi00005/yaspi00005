<?php

namespace App\Security;

use App\Entity\Journal;
use App\Entity\User;
use App\Journal\Journaliseur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/** Dernière connexion de l'utilisateur, et journal des connexions (réussies, refusées) et déconnexions. */
final class LoginListener
{
    public const RECAPITULATIF = 'recapitulatif_a_traiter';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Journaliseur $journal,
    ) {
    }

    #[AsEventListener]
    public function connexion(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if ($user instanceof User) {
            // Connexion par le formulaire avec le mot de passe provisoire : il faudra le changer (ProfilObligatoireListener).
            $motDePasse = $event->getRequest()->request->get('_password');
            if (\is_string($motDePasse) && hash_equals(User::MOT_DE_PASSE_PROVISOIRE, $motDePasse)) {
                $user->setMotDePasseProvisoire(true);
            }
            $user->setDerniereConnexion(new \DateTimeImmutable());
            $this->em->flush();
            $this->journal->ecrire($this->em, Journal::CONNEXION, 'Connexion', 'Utilisateur', $user->getId(), utilisateur: $user);
            // Première page après la connexion : récapitulatif de ce qui est à traiter (voir base.html.twig).
            if ($event->getRequest()->hasSession()) {
                $event->getRequest()->getSession()->set(self::RECAPITULATIF, true);
            }
        }
    }

    #[AsEventListener]
    public function echec(LoginFailureEvent $event): void
    {
        $identifiant = mb_substr(trim((string) $event->getRequest()->request->get('_username')), 0, 60);
        $this->journal->ecrire($this->em, Journal::ECHEC_CONNEXION, 'Connexion refusée pour « '.$identifiant.' » : '.$event->getException()->getMessageKey(), nom: $identifiant ?: 'inconnu');
    }

    #[AsEventListener]
    public function deconnexion(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();
        if ($user instanceof User) {
            $this->journal->ecrire($this->em, Journal::DECONNEXION, 'Déconnexion', 'Utilisateur', $user->getId(), utilisateur: $user);
        }
    }
}
