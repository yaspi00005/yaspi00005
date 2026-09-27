<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** Refuse la connexion des comptes désactivés. */
final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
    }

    /**
     * Après la vérification du mot de passe : sinon le message « compte désactivé » révélerait
     * l'existence d'un identifiant à quelqu'un qui ne connaît pas le mot de passe.
     */
    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if ($user instanceof User && !$user->isActif()) {
            throw new CustomUserMessageAccountStatusException('Ce compte est désactivé. Contactez un administrateur.');
        }
    }
}
