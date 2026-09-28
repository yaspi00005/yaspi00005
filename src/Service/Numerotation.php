<?php

namespace App\Service;

use App\Doctrine\CorbeilleFilter;
use App\Entity\CompteurNumero;
use App\Repository\ColisRepository;
use App\Repository\ExpeditionRepository;
use Doctrine\ORM\EntityManagerInterface;

/** Génère les identifiants lisibles et les jetons des colis et expéditions. */
final class Numerotation
{
    /** Alphabet sans caractères ambigus (0/O, 1/I/L). */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly ColisRepository $colisRepository,
        private readonly ExpeditionRepository $expeditionRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Ex. : ST2609-0042 (année 26, mois 09, 42e colis du mois). Un numéro n'est jamais réattribué :
     * colis de la corbeille compris, et dernier numéro mémorisé (CompteurNumero) même après une
     * suppression définitive.
     */
    public function numeroSuivi(\DateTimeInterface $date): string
    {
        $prefixe = 'ST'.$date->format('ym').'-';

        return $this->sansCorbeilleMasquee(function () use ($prefixe) {
            $compteur = $this->em->find(CompteurNumero::class, $prefixe) ?? new CompteurNumero($prefixe);
            $n = max($compteur->getDernier(), $this->colisRepository->compterPourPrefixe($prefixe)) + 1;
            do {
                $numero = $prefixe.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
                ++$n;
            } while ($this->colisRepository->findOneBy(['numeroSuivi' => $numero]));
            $this->em->persist($compteur->setDernier($n - 1));

            return $numero;
        });
    }

    /** Ex. : EXP2026-007. */
    public function numeroExpedition(\DateTimeInterface $date): string
    {
        $annee = (int) $date->format('Y');
        $n = $this->expeditionRepository->compterPourAnnee($annee) + 1;
        do {
            $numero = \sprintf('EXP%d-%03d', $annee, $n);
            ++$n;
        } while ($this->expeditionRepository->findOneBy(['numero' => $numero]));

        return $numero;
    }

    public function codeRetrait(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 6; ++$i) {
                $code .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
            }
        } while ($this->sansCorbeilleMasquee(fn () => $this->colisRepository->findOneBy(['codeRetrait' => $code])));

        return $code;
    }

    /** Les colis de la corbeille comptent aussi : leurs numéros et codes restent réservés. */
    private function sansCorbeilleMasquee(callable $fn): mixed
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

    public function token(): string
    {
        return bin2hex(random_bytes(20));
    }
}
