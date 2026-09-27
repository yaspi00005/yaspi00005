<?php

namespace App\Security;

use App\Entity\Colis;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Règles d'accès aux colis (périmètre de chaque rôle : voir Perimetre) :
 *  - VOIR      : admin ; agent de ramassage pour les colis qu'il a enregistrés ;
 *                représentant pour les colis arrivés dans son pays ;
 *  - MODIFIER  : admin, ou l'agent qui l'a enregistré tant que le colis est en préparation ;
 *  - ENCAISSER : admin, ou représentant pour les colis arrivés dans son pays ;
 *  - LIVRER    : admin, ou représentant pour les colis arrivés dans son pays.
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

        $visible = Perimetre::peutVoir($user, $subject);

        return match ($attribute) {
            self::VOIR => $visible,
            self::MODIFIER => $visible && Perimetre::estRamassage($user) && $subject->isSupprimable(),
            self::ENCAISSER, self::LIVRER => $visible && Perimetre::estRepresentant($user),
            default => false,
        };
    }
}
