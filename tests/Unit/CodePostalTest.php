<?php

namespace App\Tests\Unit;

use App\Service\CodePostal;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CodePostalTest extends TestCase
{
    /** @param list<string> $appels */
    private function service(callable $reponses, array &$appels = []): CodePostal
    {
        $client = new MockHttpClient(function (string $methode, string $url) use ($reponses, &$appels) {
            $appels[] = $url;

            return $reponses($url);
        });

        return new CodePostal($client, new ArrayAdapter(), new NullLogger());
    }

    public function testFranceBaseOfficielleEtPlusieursCommunes(): void
    {
        $appels = [];
        $service = $this->service(fn () => new MockResponse(json_encode([['nom' => 'Saint-Denis'], ['nom' => 'Aubervilliers'], ['nom' => 'Saint-Denis']])), $appels);

        $this->assertSame(['Aubervilliers', 'Saint-Denis'], $service->villes('France', '93 200'));
        $this->assertStringStartsWith('https://geo.api.gouv.fr/communes?', $appels[0]);
        $this->assertStringContainsString('codePostal=93200', $appels[0]);

        // Deuxième appel : servi par le cache.
        $service->villes('France', '93200');
        $this->assertCount(1, $appels);
    }

    public function testAutresPaysEuropeensViaZippopotam(): void
    {
        $appels = [];
        $service = $this->service(fn () => new MockResponse(json_encode(['places' => [['place name' => 'Bruxelles'], ['place name' => 'Brussel']]])), $appels);

        $this->assertSame(['Brussel', 'Bruxelles'], $service->villes('Belgique', 'B-1000'));
        $this->assertSame('https://api.zippopotam.us/be/1000', $appels[0]);

        $service->villes('Portugal', '1000-001');
        $this->assertSame('https://api.zippopotam.us/pt/1000-001', $appels[1]);

        $service->villes('Pays-Bas', '1012 AB');
        $this->assertSame('https://api.zippopotam.us/nl/1012', $appels[2]);
    }

    public function testTriAlphabetiqueFrancais(): void
    {
        $service = $this->service(fn () => new MockResponse(json_encode([['nom' => 'Villabé'], ['nom' => 'Évry-Courcouronnes'], ['nom' => 'Bondoufle']])));
        $this->assertSame(['Bondoufle', 'Évry-Courcouronnes', 'Villabé'], $service->villes('France', '91000'));
    }

    public function testCodeInvalideOuPaysHorsEuropeSansAppel(): void
    {
        $appels = [];
        $service = $this->service(fn () => new MockResponse('[]'), $appels);

        $this->assertSame([], $service->villes('France', '9310'));
        $this->assertSame([], $service->villes('Mali', '12345'));
        $this->assertSame([], $service->villes('Belgique', 'abcd'));
        $this->assertSame([], $appels);
    }

    public function testCodeInconnuEtServiceEnPanne(): void
    {
        $this->assertSame([], $this->service(fn () => new MockResponse('{}', ['http_code' => 404]))->villes('Espagne', '99999'));
        $this->assertSame([], $this->service(fn () => new MockResponse('', ['error' => 'délai dépassé']))->villes('France', '75018'));
    }
}
