<?php

namespace App\Tests\Controller;

use App\Repository\UserRepository;
use App\Service\CodePostal;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class VillesCodePostalTest extends WebTestCase
{
    public function testSuggestionsEtEnregistrementDuClient(): void
    {
        $client = static::createClient();
        static::getContainer()->set(CodePostal::class, new CodePostal(
            new MockHttpClient(new MockResponse(json_encode([['nom' => 'Paris']]))),
            new ArrayAdapter(),
            new NullLogger(),
        ));
        $client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'ramassage']));

        $client->request('GET', '/clients/villes.json', ['pays' => 'France', 'cp' => '75018']);
        $this->assertResponseIsSuccessful();
        $this->assertSame(['villes' => ['Paris']], json_decode((string) $client->getResponse()->getContent(), true));

        // La fiche client propose les pays européens et enregistre un client belge.
        $crawler = $client->request('GET', '/clients/nouveau');
        $this->assertSelectorExists('select#client_pays option[value="Belgique"]');
        $this->assertSelectorExists('input#client_ville[list="villes-cp"]');
        $client->submit($crawler->selectButton('Enregistrer')->form([
            'client[prenom]' => 'Lucie', 'client[nom]' => 'Dupont', 'client[telephone]' => '+32 470 12 34 56',
            'client[pays]' => 'Belgique', 'client[codePostal]' => '1000', 'client[ville]' => 'Bruxelles',
        ]));
        $this->assertResponseRedirects();
    }

    public function testReserveAuxAgents(): void
    {
        $client = static::createClient();
        $client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'bamako']));
        $client->request('GET', '/clients/villes.json', ['pays' => 'France', 'cp' => '75018']);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testCodePostalDeduitDeLaVille(): void
    {
        $client = static::createClient();
        static::getContainer()->set(CodePostal::class, new CodePostal(
            new MockHttpClient(new MockResponse(json_encode([['nom' => 'Émerainville', 'codesPostaux' => ['77184']], ['nom' => 'Paris', 'codesPostaux' => ['75001', '75002']]]))),
            new ArrayAdapter(),
            new NullLogger(),
        ));
        $client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'ramassage']));

        $client->request('GET', '/appels/codes-postaux.json', ['ville' => 'emerainville']);
        $this->assertResponseIsSuccessful();
        $this->assertSame(['ville' => 'Émerainville', 'cp' => '77184'], json_decode((string) $client->getResponse()->getContent(), true)['communes'][0]);
        $this->assertCount(3, json_decode((string) $client->getResponse()->getContent(), true)['communes']);
    }
}
