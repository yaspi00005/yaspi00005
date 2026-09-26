<?php

namespace App\Service;

use App\Entity\Colis;
use App\Entity\MouvementBonus;
use App\Repository\MouvementBonusRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Bonus fidélité gagné par colis (reprise du trigger « bases_sahel » de l'ancienne application).
 *
 * Le montant dépend du total des articles du colis. Il est recalculé quand les articles changent
 * et retiré quand le colis est supprimé. Le bonus gagné par un colis ne peut pas payer ce même colis.
 */
final class BonusFidelite
{
    /** Barème en euros : [total minimum, bonus]. Le premier seuil atteint (en partant du haut) s'applique. */
    public const BAREME = [
        [1500, 150],
        [1400, 120],
        [1300, 110],
        [1200, 100],
        [1100, 90],
        [1000, 80],
        [900, 70],
        [800, 60],
        [700, 50],
        [600, 40],
        [500, 30],
        [400, 20],
        [300, 15],
        [200, 10],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MouvementBonusRepository $mouvements,
    ) {
    }

    /** Bonus (centimes) pour un total d'articles (centimes). */
    public function bonusPour(int $totalCentimes): int
    {
        foreach (self::BAREME as [$seuil, $bonus]) {
            if ($totalCentimes >= $seuil * 100) {
                return $bonus * 100;
            }
        }

        return 0;
    }

    public function gainDuColis(Colis $colis): ?MouvementBonus
    {
        return $colis->getId() ? $this->mouvements->findOneBy(['colis' => $colis, 'gainColis' => true]) : null;
    }

    /** Bonus utilisable sur ce colis : le solde du client, hors bonus gagné par ce même colis. */
    public function disponiblePour(Colis $colis): int
    {
        $client = $colis->getClient();
        if (!$client) {
            return 0;
        }

        return max(0, $client->getSoldeBonus() - ($this->gainDuColis($colis)?->getMontant() ?? 0));
    }

    /** Crée, met à jour ou supprime le bonus gagné par le colis (à appeler après enregistrement des articles). */
    public function synchroniser(Colis $colis): void
    {
        $client = $colis->getClient();
        $cible = $this->bonusPour($colis->getTotalArticles());
        $gain = $this->gainDuColis($colis);
        $actuel = $gain?->getMontant() ?? 0;

        if ($cible === $actuel) {
            return;
        }
        // Le solde ne descend jamais sous zéro (bonus déjà dépensé).
        $client->setSoldeBonus($client->getSoldeBonus() + $cible - $actuel);

        if (0 === $cible) {
            $client->getMouvementsBonus()->removeElement($gain);
            $this->em->remove($gain);
        } elseif ($gain) {
            $gain->setMontant($cible)->setCreatedAt(new \DateTimeImmutable());
        } else {
            $gain = (new MouvementBonus())
                ->setMontant($cible)
                ->setGainColis(true)
                ->setColis($colis)
                ->setLibelle('Bonus fidélité — colis '.$colis->getNumeroSuivi());
            $client->addMouvementBonus($gain);
            $this->em->persist($gain);
        }
        $this->em->flush();
    }

    /** Retire le bonus gagné par un colis avant sa suppression. */
    public function annulerGain(Colis $colis): void
    {
        if ($gain = $this->gainDuColis($colis)) {
            $client = $colis->getClient();
            $client->setSoldeBonus($client->getSoldeBonus() - $gain->getMontant());
            $client->getMouvementsBonus()->removeElement($gain);
            $this->em->remove($gain);
            $this->em->flush();
        }
    }
}
