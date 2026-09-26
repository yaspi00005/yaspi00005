<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApplicationTest extends WebTestCase
{
    public function testPageDeTelechargementPublique(): void
    {
        $client = static::createClient();
        $client->request('GET', '/application');
        $this->assertResponseIsSuccessful();
        // Sans apk/sotrama.apk ni APP_APK_URL : bouton désactivé.
        $this->assertSelectorExists('button[disabled]:contains("Bientôt disponible")');
        $this->assertSelectorTextContains('body', 'Installation');
    }

    public function testApkHebergeParLeSite(): void
    {
        $client = static::createClient();
        $fichier = static::getContainer()->getParameter('app.apk_fichier');
        $this->assertFileDoesNotExist($fichier, 'Retirez apk/sotrama.apk avant de lancer les tests.');

        $client->request('GET', '/application/sotrama.apk');
        $this->assertResponseStatusCodeSame(404);

        file_put_contents($fichier, 'PK-faux-apk');
        try {
            $crawler = $client->request('GET', '/application');
            $lien = $crawler->filter('a[download]')->attr('href');
            $this->assertStringStartsWith('/application/sotrama.apk?v=', $lien);
            $this->assertSelectorTextContains('body', 'version du');

            $client->request('GET', $lien);
            $this->assertResponseIsSuccessful();
            $this->assertResponseHeaderSame('Content-Type', 'application/vnd.android.package-archive');
            $this->assertStringContainsString('attachment; filename=sotrama.apk', (string) $client->getResponse()->headers->get('Content-Disposition'));
        } finally {
            unlink($fichier);
        }
    }

    public function testManifesteEtLienDepuisLaConnexion(): void
    {
        $client = static::createClient();
        $client->request('GET', '/connexion');
        $this->assertSelectorExists('link[rel="manifest"][href="/manifest.webmanifest"]');
        $this->assertSelectorExists('a[href="/application"]');

        $manifeste = json_decode((string) file_get_contents(__DIR__.'/../../public/manifest.webmanifest'), true);
        $this->assertSame('SOTRAMA', $manifeste['short_name']);
        $this->assertSame('standalone', $manifeste['display']);
        foreach ($manifeste['icons'] as $icone) {
            $this->assertFileExists(__DIR__.'/../../public'.$icone['src']);
        }
    }

    public function testAssetLinks(): void
    {
        $client = static::createClient();
        $client->request('GET', '/.well-known/assetlinks.json');
        $this->assertResponseIsSuccessful();
        $json = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('com.sotrama.app', $json[0]['target']['package_name']);
        $this->assertSame(['AA:BB:CC'], $json[0]['target']['sha256_cert_fingerprints']);
    }
}
