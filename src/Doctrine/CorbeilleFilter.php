<?php

namespace App\Doctrine;

use App\Entity\Colis;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Les colis mis à la corbeille disparaissent de toute l'application (listes, recherche, statistiques,
 * rapports, expéditions, suivi public). Désactivé seulement pour la page Corbeille et la numérotation.
 */
final class CorbeilleFilter extends SQLFilter
{
    public const NOM = 'corbeille';

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        return Colis::class === $targetEntity->getName() ? $targetTableAlias.'.supprime_le IS NULL' : '';
    }
}
