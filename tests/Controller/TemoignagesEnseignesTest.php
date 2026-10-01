<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

/** Témoignages vidéo et logos des enseignes : affichés dès que les fichiers sont déposés. */
final class TemoignagesEnseignesTest extends WebTestCase
{
    private const DOSSIER = __DIR__.'/../../public/images/vitrine/';
    private const FICHIERS = ['temoignages/98-awa-diallo.mp4', 'temoignages/98-awa-diallo.jpg', 'temoignages/99-test-client.mp4', 'temoignages/97-moussa.mp4', 'temoignages/96-fanta.mp4', 'enseignes/zz-boutique-test.png'];

    protected function tearDown(): void
    {
        (new Filesystem())->remove(array_map(fn ($f) => self::DOSSIER.$f, self::FICHIERS));
        parent::tearDown();
    }

    public function testSansFichierRienNEstAffiche(): void
    {
        $client = static::createClient([], ['HTTP_HOST' => 'sotrama.net']);
        $client->request('GET', '/');
        $this->assertSelectorNotExists('#temoignages');
        $this->assertSelectorNotExists('#enseignes');
        $this->assertSelectorExists('nav a[href="/temoignages"]');
        $client->request('GET', '/temoignages');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.alerte-info', 'arrivent bientôt');
    }

    public function testVideosEtLogosDeposes(): void
    {
        foreach (self::FICHIERS as $f) {
            file_put_contents(self::DOSSIER.$f, 'x');
        }
        $client = static::createClient([], ['HTTP_HOST' => 'sotrama.net']);

        $client->request('GET', '/');
        $this->assertCount(3, $client->getCrawler()->filter('#temoignages .temoignage'), 'Trois vidéos sur l\'accueil');
        $this->assertSelectorTextContains('#temoignages', 'Voir les 4 témoignages');
        $this->assertSelectorExists('#enseignes img[alt="Zz Boutique Test"]');

        $client->request('GET', '/temoignages');
        $this->assertCount(4, $client->getCrawler()->filter('.temoignage video source[type="video/mp4"]'));
        $this->assertSelectorTextContains('.grille-temoignages', 'Awa Diallo');
        $this->assertSelectorExists('video[poster*="98-awa-diallo.jpg"]');

        $client->request('GET', '/sitemap.xml');
        $this->assertStringContainsString('/temoignages</loc>', (string) $client->getResponse()->getContent());
    }
}
