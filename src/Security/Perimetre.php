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
 *  - représentant : les colis arrivés (ou déjà remis) dans son pays ; un colis de son pays pas encore
 *    arrivé ne s'affiche que s'il le recherche par son numéro de suivi exact (consultation seule).
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
     * $numeroRecherche : numéro de suivi tapé dans la recherche (le représentant voit alors ce colis
     * même s'il n'est pas encore arrivé).
     */
    public static function appliquer(QueryBuilder $qb, ?User $user, string $c = 'c', ?string $numeroRecherche = null): void
    {
        if (!$user || self::estAdmin($user)) {
            return;
        }
        if (self::estRepresentant($user) && $user->getPays()) {
            $e = 'pe_'.$c;
            $qb->leftJoin($c.'.expedition', $e)->andWhere("$e.destination = :perimetrePays")->setParameter('perimetrePays', $user->getPays());
            $numero = mb_strtoupper(trim((string) $numeroRecherche));
            if ('' !== $numero) {
                $qb->andWhere("$c.statut IN (:perimetreStatuts) OR UPPER($c.numeroSuivi) = :perimetreNumero")->setParameter('perimetreNumero', $numero);
            } else {
                $qb->andWhere("$c.statut IN (:perimetreStatuts)");
            }
            $qb->setParameter('perimetreStatuts', self::STATUTS_REPRESENTANT);

            return;
        }
        if (self::estRamassage($user)) {
            $qb->andWhere("$c.agent = :perimetreAgent")->setParameter('perimetreAgent', $user);

            return;
        }
        $qb->andWhere('1 = 0');
    }

    /** Fiche du colis : le représentant voit tous les colis de son pays (retrouvés par leur numéro). */
    public static function peutVoir(?User $user, Colis $colis): bool
    {
        return match (true) {
            !$user => false,
            self::estAdmin($user) => true,
            self::estRepresentant($user) => null !== $user->getPays()
                && $colis->getExpedition()?->getDestination() === $user->getPays(),
            self::estRamassage($user) => $colis->getAgent()?->getId() === $user->getId(),
            default => false,
        };
    }

    /** Le représentant n'encaisse et ne remet un colis de son pays qu'une fois arrivé. */
    public static function peutTraiter(?User $user, Colis $colis): bool
    {
        return self::estAdmin($user)
            || (self::estRepresentant($user) && self::peutVoir($user, $colis) && \in_array($colis->getStatut(), self::STATUTS_REPRESENTANT, true));
    }
}
