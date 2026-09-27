<?php

namespace App\Service;

use App\Entity\Colis;
use App\Repository\ColisRepository;

/** Recherche publique d'un colis : numéro de suivi + téléphone de l'expéditeur ou du destinataire. */
final class SuiviColis
{
    public function __construct(private readonly ColisRepository $colis)
    {
    }

    public function trouver(string $numero, string $telephone): ?Colis
    {
        $numero = mb_strtoupper(trim($numero));
        $telephone = preg_replace('/\D/', '', $telephone);
        if ('' === $numero || \strlen($telephone) < 8) {
            return null;
        }
        $colis = $this->colis->findOneBy(['numeroSuivi' => $numero]);
        if (!$colis) {
            return null;
        }
        // Les 8 derniers chiffres suffisent à absorber les variantes d'indicatif (+223, 00223, 06…).
        foreach ([$colis->getDestinataireTelephone(), $colis->getClient()?->getTelephone()] as $t) {
            $t = preg_replace('/\D/', '', (string) $t);
            if (\strlen($t) >= 8 && str_ends_with($t, substr($telephone, -8))) {
                return $colis;
            }
        }

        return null;
    }
}
