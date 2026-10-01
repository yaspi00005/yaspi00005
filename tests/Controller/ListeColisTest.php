<?php

namespace App\Tests\Controller;

use App\Enum\StatutColis;
use App\Repository\ColisRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Liste des colis allégée : les colis récupérés n'apparaissent qu'en recherche. */
final class ListeColisTest extends WebTestCase
{
    public function testColisRecuperesMasquesSaufRecherche(): void
    {
        $client = static::createClient();
        $client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'admin']));
        $recupere = static::getContainer()->get(ColisRepository::class)->findOneBy(['statut' => StatutColis::Recupere]);
        $this->assertNotNull($recupere, 'Les données de démonstration contiennent un colis récupéré.');
        $numero = $recupere->getNumeroSuivi();

        $client->request('GET', '/colis');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextNotContains('table', $numero);
        $this->assertSelectorTextContains('body', 'déjà récupéré');
        $this->assertSelectorTextContains('thead', 'Ajouté par');
        $this->assertSelectorTextContains('tbody', 'Moussa TRAORÉ');

        $client->request('GET', '/colis', ['q' => $numero]);
        $this->assertSelectorTextContains('table', $numero);

        $client->request('GET', '/colis', ['statut' => StatutColis::Recupere->value]);
        $this->assertSelectorTextContains('table', $numero);

        $client->request('GET', '/colis', ['tous' => 1]);
        $this->assertSelectorTextContains('body', 'Tous les colis sont affichés');

        // L'export Excel reste complet.
        $client->request('GET', '/colis', ['export' => 'csv']);
        $fichier = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($fichier, (string) $client->getInternalResponse()->getContent());
        $cellules = \PhpOffice\PhpSpreadsheet\IOFactory::load($fichier)->getActiveSheet()->toArray();
        unlink($fichier);
        $this->assertContains($numero, array_column($cellules, 0));
    }
}
