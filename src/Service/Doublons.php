<?php

namespace App\Service;

use App\Entity\Client;
use App\Entity\Colis;
use Doctrine\ORM\EntityManagerInterface;

use function Symfony\Component\String\u;

/**
 * Détection des doublons avant l'enregistrement (l'utilisateur confirme ou utilise l'existant) :
 *  - client : même téléphone (quelle que soit l'écriture : 06…, +33 6…, espaces) ou mêmes nom et prénom ;
 *  - colis : même expéditeur et même téléphone de destinataire, enregistré ces 3 derniers jours.
 */
final class Doublons
{
    public const JOURS_COLIS = 3;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return list<Client> */
    public function clientsSemblables(Client $client, int $max = 5): array
    {
        $tel = self::finTelephone($client->getTelephone());
        $nom = self::nom($client->getPrenom().' '.$client->getNom());
        $ids = [];
        foreach ($this->em->createQuery('SELECT c.id, c.telephone, c.prenom, c.nom FROM App\Entity\Client c')->getArrayResult() as $r) {
            if ($client->getId() === $r['id']) {
                continue;
            }
            if (($tel && self::finTelephone($r['telephone']) === $tel) || ('' !== $nom && self::nom($r['prenom'].' '.$r['nom']) === $nom)) {
                $ids[] = $r['id'];
                if (\count($ids) >= $max) {
                    break;
                }
            }
        }

        return $ids ? $this->em->getRepository(Client::class)->findBy(['id' => $ids]) : [];
    }

    /** Client déjà enregistré avec ce téléphone (même écrit autrement), le plus ancien en priorité. */
    public function clientParTelephone(?string $telephone): ?Client
    {
        if (!$tel = self::finTelephone($telephone)) {
            return null;
        }
        foreach ($this->em->createQuery('SELECT c.id, c.telephone FROM App\Entity\Client c ORDER BY c.id ASC')->getArrayResult() as $r) {
            if (self::finTelephone($r['telephone']) === $tel) {
                return $this->em->getRepository(Client::class)->find($r['id']);
            }
        }

        return null;
    }

    /** @return list<Colis> */
    public function colisSemblables(?Client $client, ?string $telephoneDestinataire, ?int $saufId = null): array
    {
        $tel = self::finTelephone($telephoneDestinataire);
        if (!$client?->getId() || !$tel) {
            return [];
        }
        $recents = $this->em->getRepository(Colis::class)->createQueryBuilder('c')
            ->andWhere('c.client = :client AND c.dateReception >= :depuis')
            ->setParameter('client', $client)
            ->setParameter('depuis', new \DateTimeImmutable('-'.self::JOURS_COLIS.' days'))
            ->orderBy('c.dateReception', 'DESC')
            ->getQuery()->getResult();

        return array_values(array_filter($recents, fn (Colis $c) => $c->getId() !== $saufId && self::finTelephone($c->getDestinataireTelephone()) === $tel));
    }

    /** 8 derniers chiffres : « 06 12 34 56 78 », « +33 6 12 34 56 78 », « 0033612345678 » → « 12345678 ». */
    public static function finTelephone(?string $telephone): ?string
    {
        $chiffres = preg_replace('/\D/', '', (string) $telephone);

        return \strlen($chiffres) >= 8 ? substr($chiffres, -8) : null;
    }

    /** « Awa  DIALLO », « awa diallo » et « Diallo Awa » sont identiques ; un seul mot ne suffit pas (''). */
    private static function nom(string $nom): string
    {
        $mots = array_filter(explode(' ', u($nom)->ascii()->lower()->collapseWhitespace()->trim()->toString()));
        sort($mots);

        return \count($mots) >= 2 ? implode(' ', $mots) : '';
    }
}
