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

        $client->request('GET', '/colis', ['export' => 'csv']);
        $this->assertStringContainsString($numero, (string) $client->getInternalResponse()->getContent());
    }
}
