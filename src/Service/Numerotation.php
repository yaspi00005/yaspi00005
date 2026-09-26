<?php

namespace App\Service;

use App\Repository\ColisRepository;
use App\Repository\ExpeditionRepository;

/** Génère les identifiants lisibles et les jetons des colis et expéditions. */
final class Numerotation
{
    /** Alphabet sans caractères ambigus (0/O, 1/I/L). */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly ColisRepository $colisRepository,
        private readonly ExpeditionRepository $expeditionRepository,
    ) {
    }

    /** Ex. : ST2609-0042 (année 26, mois 09, 42e colis du mois). */
    public function numeroSuivi(\DateTimeInterface $date): string
    {
        $prefixe = 'ST'.$date->format('ym').'-';
        $n = $this->colisRepository->compterPourPrefixe($prefixe) + 1;
        do {
            $numero = $prefixe.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            ++$n;
        } while ($this->colisRepository->findOneBy(['numeroSuivi' => $numero]));

        return $numero;
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
        } while ($this->colisRepository->findOneBy(['codeRetrait' => $code]));

        return $code;
    }

    public function token(): string
    {
        return bin2hex(random_bytes(20));
    }
}
