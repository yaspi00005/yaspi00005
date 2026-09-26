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
        $this->assertSelectorExists('a[href$="sotrama.apk"]');
        $this->assertSelectorTextContains('body', 'Installation');
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
