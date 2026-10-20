<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\Ramassage;
use App\Entity\User;
use App\Enum\StatutColis;
use App\Enum\StatutRamassage;
use App\Repository\AppelRepository;
use App\Repository\ColisRepository;
use App\Security\Perimetre;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Ce qui attend l'utilisateur connecté : cloche de l'en-tête et récapitulatif affiché à la connexion.
 * Calculé à la demande (rien à marquer comme lu) : une ligne disparaît dès que le travail est fait.
 */
final class ATraiter
{
    /** @var array<int, list<array{libelle: string, nombre: int, url: string, icone: string, couleur: string}>> */
    private array $cache = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AppelRepository $appels,
        private readonly ColisRepository $colis,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** @return list<array{libelle: string, nombre: int, url: string, icone: string, couleur: string}> lignes non vides */
    public function pour(?User $user): array
    {
        if (!$user) {
            return [];
        }

        return $this->cache[$user->getId() ?? 0] ??= array_values(array_filter($this->calculer($user), fn ($l) => $l['nombre'] > 0));
    }

    public function total(?User $user): int
    {
        return array_sum(array_column($this->pour($user), 'nombre'));
    }

    /** @return list<array{libelle: string, nombre: int, url: string, icone: string, couleur: string}> */
    private function calculer(User $user): array
    {
        $lignes = [];
        if (Perimetre::estAdmin($user) || Perimetre::estRamassage($user)) {
            $lignes[] = $this->ligne('Demande(s) de ramassage en ligne à valider', $this->compter($this->em->getRepository(Ramassage::class)->createQueryBuilder('r')
                ->andWhere('r.statut = :demande')->setParameter('demande', StatutRamassage::Demande), 'r'),
                $this->urls->generate('appel_index', ['source' => 'site']), 'fa-globe', 'primary');
            $lignes[] = $this->ligne('Ramassage(s) prévu(s) aujourd\'hui', $this->compter($this->em->getRepository(Ramassage::class)->createQueryBuilder('r')
                ->andWhere('r.statut IN (:aFaire) AND r.datePrevue >= :debut AND r.datePrevue < :fin')
                ->setParameter('aFaire', StatutRamassage::aFaire())
                ->setParameter('debut', new \DateTimeImmutable('today'))->setParameter('fin', new \DateTimeImmutable('tomorrow')), 'r'),
                $this->urls->generate('ramassage_index'), 'fa-calendar-day', 'info');
            $lignes[] = $this->ligne('Appel(s) non confirmé(s)', $this->compter($this->appels->filtreQb(['vue' => 'non_confirmes']), 'a'),
                $this->urls->generate('appel_index', ['vue' => 'non_confirmes']), 'fa-phone-slash', 'danger');
            $lignes[] = $this->ligne('Appel(s) confirmé(s) à planifier', $this->compter($this->appels->filtreQb(['vue' => 'a_planifier']), 'a'),
                $this->urls->generate('appel_index', ['vue' => 'a_planifier']), 'fa-calendar-plus', 'warning');
            $lignes[] = $this->ligne('Client(s) sans camion', $this->compter($this->appels->filtreQb(['vue' => 'tous']), 'a'),
                $this->urls->generate('appel_index'), 'fa-truck', 'warning');
        }
        if (Perimetre::estAdmin($user) || Perimetre::estRepresentant($user)) {
            $lignes[] = $this->ligne('Colis arrivé(s) à remettre', $this->compter($this->colis->filtreQb($user, ['statut' => StatutColis::Arrive->value]), 'c'),
                $this->urls->generate('colis_index', ['statut' => StatutColis::Arrive->value]), 'fa-hand-holding', 'success');
        }
        if (Perimetre::estAdmin($user)) {
            $lignes[] = $this->ligne('E-mail(s) ou SMS en échec (7 derniers jours)', $this->compter($this->em->getRepository(Notification::class)->createQueryBuilder('n')
                ->andWhere('n.succes = false AND n.createdAt >= :depuis')->setParameter('depuis', new \DateTimeImmutable('-7 days')), 'n'),
                $this->urls->generate('admin_notification_index'), 'fa-envelope-open-text', 'danger');
        }

        return $lignes;
    }

    private function compter(QueryBuilder $qb, string $alias): int
    {
        return (int) (clone $qb)->select('COUNT(DISTINCT '.$alias.'.id)')->resetDQLPart('orderBy')
            ->setFirstResult(0)->setMaxResults(null)->getQuery()->getSingleScalarResult();
    }

    /** @return array{libelle: string, nombre: int, url: string, icone: string, couleur: string} */
    private function ligne(string $libelle, int $nombre, string $url, string $icone, string $couleur): array
    {
        return ['libelle' => $libelle, 'nombre' => $nombre, 'url' => $url, 'icone' => $icone, 'couleur' => $couleur];
    }
}
