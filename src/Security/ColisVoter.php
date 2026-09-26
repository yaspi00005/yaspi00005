<?php

namespace App\Security;

use App\Entity\Colis;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Règles d'accès aux colis :
 *  - VOIR      : admin, agent de ramassage, ou représentant du pays de destination ;
 *  - MODIFIER  : admin, ou agent de ramassage tant que le colis est en préparation ;
 *  - ENCAISSER : toute personne pouvant voir le colis ;
 *  - LIVRER    : admin ou représentant du pays de destination.
 *
 * @extends Voter<string, Colis>
 */
final class ColisVoter extends Voter
{
    public const VOIR = 'COLIS_VOIR';
    public const MODIFIER = 'COLIS_MODIFIER';
    public const ENCAISSER = 'COLIS_ENCAISSER';
    public const LIVRER = 'COLIS_LIVRER';

    public function __construct(private readonly Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Colis && \in_array($attribute, [self::VOIR, self::MODIFIER, self::ENCAISSER, self::LIVRER], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }
        if ($this->security->isGranted(User::ROLE_ADMIN)) {
            return true;
        }

        $ramassage = $this->security->isGranted(User::ROLE_RAMASSAGE);
        $representantDuPays = $this->security->isGranted(User::ROLE_REPRESENTANT)
            && null !== $user->getPays()
            && $subject->getExpedition()?->getDestination() === $user->getPays();

        return match ($attribute) {
            self::VOIR, self::ENCAISSER => $ramassage || $representantDuPays,
            self::MODIFIER => $ramassage && $subject->isSupprimable(),
            self::LIVRER => $representantDuPays,
            default => false,
        };
    }
}
