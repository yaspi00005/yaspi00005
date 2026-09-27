<?php

namespace App\Security;

use App\Entity\Colis;
use App\Entity\User;
use App\Enum\StatutColis;
use Doctrine\ORM\QueryBuilder;

/**
 * Colis visibles par chaque rôle (listes, statistiques, paiements et fiches) :
 *  - administrateur : tous les colis ;
 *  - agent de ramassage : les colis qu'il a enregistrés ;
 *  - représentant : les colis arrivés (ou déjà remis) dans son pays.
 */
final class Perimetre
{
    /** Statuts visibles par un représentant. */
    public const STATUTS_REPRESENTANT = [StatutColis::Arrive, StatutColis::Recupere];

    public static function estAdmin(?User $user): bool
    {
        return $user && \in_array(User::ROLE_ADMIN, $user->getRoles(), true);
    }

    public static function estRepresentant(?User $user): bool
    {
        return $user && !self::estAdmin($user) && \in_array(User::ROLE_REPRESENTANT, $user->getRoles(), true);
    }

    public static function estRamassage(?User $user): bool
    {
        return $user && !self::estAdmin($user) && \in_array(User::ROLE_RAMASSAGE, $user->getRoles(), true);
    }

    /**
     * Restreint une requête sur les colis (alias $c) au périmètre de l'utilisateur.
     * $user null : aucune restriction (tâches automatiques, rapports).
     */
    public static function appliquer(QueryBuilder $qb, ?User $user, string $c = 'c'): void
    {
        if (!$user || self::estAdmin($user)) {
            return;
        }
        if (self::estRepresentant($user) && $user->getPays()) {
            $e = 'pe_'.$c;
            $qb->leftJoin($c.'.expedition', $e)
                ->andWhere("$e.destination = :perimetrePays AND $c.statut IN (:perimetreStatuts)")
                ->setParameter('perimetrePays', $user->getPays())
                ->setParameter('perimetreStatuts', self::STATUTS_REPRESENTANT);

            return;
        }
        if (self::estRamassage($user)) {
            $qb->andWhere("$c.agent = :perimetreAgent")->setParameter('perimetreAgent', $user);

            return;
        }
        $qb->andWhere('1 = 0');
    }

    public static function peutVoir(?User $user, Colis $colis): bool
    {
        return match (true) {
            !$user => false,
            self::estAdmin($user) => true,
            self::estRepresentant($user) => null !== $user->getPays()
                && $colis->getExpedition()?->getDestination() === $user->getPays()
                && \in_array($colis->getStatut(), self::STATUTS_REPRESENTANT, true),
            self::estRamassage($user) => $colis->getAgent()?->getId() === $user->getId(),
            default => false,
        };
    }
}
