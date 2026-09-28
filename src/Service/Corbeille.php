<?php

namespace App\Service;

use App\Doctrine\CorbeilleFilter;
use App\Entity\Colis;
use App\Entity\Journal;
use App\Entity\User;
use App\Journal\Journaliseur;
use App\Security\Perimetre;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Corbeille des colis : un colis supprimé y reste 7 jours (restaurable), puis il est supprimé
 * définitivement avec ses photos. Pendant ce temps il n'apparaît nulle part ailleurs (CorbeilleFilter).
 */
final class Corbeille
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BonusFidelite $bonus,
        private readonly PhotoUploader $photos,
        private readonly Journaliseur $journal,
    ) {
    }

    /**
     * Exécute $fn en voyant aussi les colis de la corbeille.
     *
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T
     */
    public function avecCorbeille(callable $fn): mixed
    {
        $filtres = $this->em->getFilters();
        if (!$filtres->isEnabled(CorbeilleFilter::NOM)) {
            return $fn();
        }
        $filtres->disable(CorbeilleFilter::NOM);
        try {
            return $fn();
        } finally {
            $filtres->enable(CorbeilleFilter::NOM);
        }
    }

    public function jeter(Colis $colis, ?User $par): void
    {
        if (!$colis->isSupprimable()) {
            throw new \DomainException('Seuls les colis en préparation sans paiement peuvent être supprimés.');
        }
        // Le bonus que ce colis rapportait au client est retiré tout de suite (repris à la restauration).
        $this->bonus->annulerGain($colis);
        $colis->mettreALaCorbeille($par);
        $this->flushSansJournalAutomatique();
        $this->journal->ecrire($this->em, Journal::SUPPRESSION,
            \sprintf('Colis %s mis à la corbeille (restaurable %d jours)', $colis->getNumeroSuivi(), Colis::JOURS_CORBEILLE),
            'Colis', $colis->getId(), [
                'Destinataire' => [$colis->getDestinataireNom(), null],
                'Client' => [$colis->getClient()?->getNomComplet(), null],
                'Tarif' => [number_format($colis->getFrais() / 100, 2, ',', ' ').' €', null],
            ], $colis->getId(), $colis->getClient()?->getId());
    }

    private function flushSansJournalAutomatique(): void
    {
        $this->journal->suspendre();
        try {
            $this->em->flush();
        } finally {
            $this->journal->suspendre(false);
        }
    }

    public function restaurer(Colis $colis): void
    {
        $colis->restaurer();
        $this->flushSansJournalAutomatique();
        $this->bonus->synchroniser($colis);
        $this->em->flush();
        $this->journal->ecrire($this->em, Journal::MODIFICATION, 'Colis '.$colis->getNumeroSuivi().' restauré depuis la corbeille',
            'Colis', $colis->getId(), null, $colis->getId(), $colis->getClient()?->getId());
    }

    /** Colis de la corbeille visibles par l'utilisateur (l'agent : les siens ; l'admin : tous). */
    public function trouver(int $id, ?User $user): ?Colis
    {
        $colis = $this->avecCorbeille(fn () => $this->em->getRepository(Colis::class)->find($id));

        return $colis && $colis->isDansCorbeille() && $this->peutGerer($colis, $user) ? $colis : null;
    }

    public function peutGerer(Colis $colis, ?User $user): bool
    {
        return Perimetre::estAdmin($user) || (Perimetre::estRamassage($user) && $colis->getAgent()?->getId() === $user?->getId());
    }

    /** @return list<Colis> */
    public function contenu(?User $user): array
    {
        return $this->avecCorbeille(function () use ($user) {
            $qb = $this->em->getRepository(Colis::class)->createQueryBuilder('c')
                ->addSelect('cl')->join('c.client', 'cl')
                ->andWhere('c.supprimeLe IS NOT NULL')
                ->orderBy('c.supprimeLe', 'DESC');
            if (!Perimetre::estAdmin($user)) {
                $qb->andWhere('c.agent = :agent')->setParameter('agent', $user);
            }

            return $qb->getQuery()->getResult();
        });
    }

    /** Suppression définitive (avec les photos) d'un colis de la corbeille. */
    public function supprimerDefinitivement(Colis $colis): void
    {
        $fichiers = array_filter([$colis->getPhoto(), ...$colis->getArticles()->map(fn ($a) => $a->getPhoto())->toArray()]);
        $this->avecCorbeille(function () use ($colis) {
            $this->em->remove($colis);
            $this->em->flush();
        });
        array_walk($fichiers, fn ($f) => $this->photos->supprimer($f));
    }

    /** Supprime définitivement les colis restés plus de 7 jours dans la corbeille. Retourne leur nombre. */
    public function vider(): int
    {
        $limite = new \DateTimeImmutable('-'.Colis::JOURS_CORBEILLE.' days');
        $expires = $this->avecCorbeille(fn () => $this->em->getRepository(Colis::class)->createQueryBuilder('c')
            ->andWhere('c.supprimeLe IS NOT NULL AND c.supprimeLe <= :limite')->setParameter('limite', $limite)
            ->getQuery()->getResult());
        foreach ($expires as $colis) {
            $this->supprimerDefinitivement($colis);
        }

        return \count($expires);
    }
}
