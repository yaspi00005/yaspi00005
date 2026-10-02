<?php

namespace App\Tests\Controller;

use App\Repository\ColisRepository;
use App\Repository\UserRepository;
use App\Security\IdChiffreur;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Colis d'une expédition : recherche, filtres et tri (liste et manifeste Excel). */
final class TriColisExpeditionTest extends WebTestCase
{
    public function testFiltrerEtTrier(): void
    {
        $client = static::createClient();
        $client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'admin']));
        $expedition = static::getContainer()->get(ColisRepository::class)->createQueryBuilder('c')
            ->andWhere('c.expedition IS NOT NULL')->setMaxResults(1)->getQuery()->getSingleResult()->getExpedition();
        $url = '/expeditions/'.static::getContainer()->get(IdChiffreur::class)->encode($expedition->getId());
        $numeros = fn () => $client->getCrawler()->filter('#colis + .card-body tbody td:first-child a')->each(fn ($n) => $n->text());

        $client->request('GET', $url);
        $tous = $numeros();
        $this->assertGreaterThan(1, \count($tous));
        $trie = $tous;
        sort($trie);
        $this->assertSame($trie, $tous, 'Par défaut : n° de suivi croissant');

        $client->request('GET', $url, ['tri' => 'numero', 'sens' => 'desc']);
        $this->assertSame(array_reverse($trie), $numeros());
        $this->assertSelectorExists('a.lien-tri.actif .fa-sort-down');

        // Tri par reste à payer, décroissant.
        $client->request('GET', $url, ['tri' => 'reste', 'sens' => 'desc']);
        $restes = $client->getCrawler()->filter('#colis + .card-body tbody td:nth-child(6)')->each(fn ($n) => (float) str_replace([' ', "\u{202f}", "\u{a0}", '€', ','], ['', '', '', '', '.'], $n->text()));
        $copie = $restes;
        rsort($copie);
        $this->assertSame($copie, $restes);

        // Recherche par n° de suivi et filtre de paiement.
        $client->request('GET', $url, ['q' => strtolower($tous[0])]);
        $this->assertSame([$tous[0]], $numeros());
        $this->assertSelectorTextContains('#colis', 'Effacer les filtres');
        $client->request('GET', $url, ['paiement' => 'solde']);
        $soldes = $numeros();
        $client->request('GET', $url, ['paiement' => 'reste']);
        $this->assertSame(\count($tous), \count($soldes) + \count($numeros()));

        // Le manifeste Excel suit la sélection.
        $client->request('GET', $url, ['q' => $tous[0], 'export' => 'csv']);
        $fichier = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($fichier, (string) $client->getInternalResponse()->getContent());
        $lignes = array_filter(IOFactory::load($fichier)->getActiveSheet()->toArray(), fn ($l) => str_starts_with((string) $l[0], 'ST'));
        unlink($fichier);
        $this->assertSame([$tous[0]], array_values(array_column($lignes, 0)));
    }
}
