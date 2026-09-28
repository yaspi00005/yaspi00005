<?php

namespace App\Service;

use App\Entity\Colis;
use App\Entity\MouvementBonus;
use App\Entity\PalierBonus;
use App\Repository\MouvementBonusRepository;
use App\Repository\PalierBonusRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Bonus fidélité gagné par colis (reprise du trigger « bases_sahel » de l'ancienne application).
 *
 * Le montant dépend du total des articles du colis. Il est recalculé quand les articles changent
 * et retiré quand le colis est supprimé. Le bonus gagné par un colis ne peut pas payer ce même colis.
 */
final class BonusFidelite
{
    /**
     * Barème d'origine en euros : [total minimum, bonus]. Il sert à remplir le barème en base
     * (PalierBonus), que l'administrateur modifie ensuite dans Administration → Barème des bonus.
     */
    public const BAREME_INITIAL = [
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
        private readonly PalierBonusRepository $paliers,
    ) {
    }

    /** @var list<array{int, int}>|null barème en centimes, lu une fois par requête */
    private ?array $bareme = null;

    /** Bonus (centimes) pour un total d'articles (centimes) : le plus haut seuil atteint s'applique. */
    public function bonusPour(int $totalCentimes): int
    {
        $this->bareme ??= array_map(fn (PalierBonus $p) => [$p->getSeuil(), $p->getMontant()], $this->paliers->bareme());
        foreach ($this->bareme as [$seuil, $bonus]) {
            if ($totalCentimes >= $seuil) {
                return $bonus;
            }
        }

        return 0;
    }

    /** À appeler après une modification du barème. */
    public function oublierBareme(): void
    {
        $this->bareme = null;
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
