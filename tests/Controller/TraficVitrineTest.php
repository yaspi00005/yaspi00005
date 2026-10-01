<?php

namespace App\Tests\Controller;

use App\Repository\UserRepository;
use App\Vitrine\CompteurTrafic;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Fréquentation anonyme de sotrama.net, visible seulement par l'administrateur dans ses statistiques. */
final class TraficVitrineTest extends WebTestCase
{
    private const MOBILE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1';

    public function testVisitesCompteesAnonymementEtAfficheesALAdministrateur(): void
    {
        $client = static::createClient();
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement('DELETE FROM trafic_vitrine');

        $vitrine = ['HTTP_HOST' => 'sotrama.net', 'HTTP_USER_AGENT' => self::MOBILE, 'REMOTE_ADDR' => '203.0.113.7'];
        $client->request('GET', '/', server: $vitrine + ['HTTP_REFERER' => 'https://www.google.fr/']);
        $client->request('GET', '/suivi', server: $vitrine);
        $client->request('GET', '/', server: $vitrine);
        // Un autre visiteur, sur ordinateur, venu de Facebook.
        $client->request('GET', '/', server: ['HTTP_HOST' => 'sotrama.net', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/130', 'REMOTE_ADDR' => '198.51.100.4', 'HTTP_REFERER' => 'https://m.facebook.com/']);
        // Non comptés : robot, page introuvable, plan du site.
        $client->request('GET', '/', server: ['HTTP_HOST' => 'sotrama.net', 'HTTP_USER_AGENT' => 'Googlebot/2.1']);
        $client->request('GET', '/suivi/'.str_repeat('a', 40), server: $vitrine);
        $client->request('GET', '/sitemap.xml', server: $vitrine);

        $compteurs = [];
        foreach ($db->fetchAllAssociative('SELECT type, cle, nombre FROM trafic_vitrine') as $l) {
            $compteurs[$l['type'].':'.$l['cle']] = (int) $l['nombre'];
        }
        $this->assertSame([
            'appareil:Mobile' => 1, 'appareil:Ordinateur' => 1,
            'page:vitrine_accueil' => 3, 'page:vitrine_suivi' => 1,
            'source:Facebook' => 1, 'source:Google' => 1,
            'visiteurs:total' => 2,
        ], (ksort($compteurs) ? $compteurs : []));
        // Aucune donnée personnelle en base : ni IP ni navigateur.
        $this->assertStringNotContainsString('203.0.113.7', json_encode($db->fetchAllAssociative('SELECT * FROM trafic_vitrine')));

        static::getContainer()->get(CompteurTrafic::class)->action('demande_ramassage');

        // Administrateur : bloc « Fréquentation du site » dans les statistiques.
        $client->getCookieJar()->clear();
        $client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'admin']));
        $client->request('GET', 'http://localhost/');
        $this->assertSelectorTextContains('#trafic-site', 'Fréquentation du site');
        $this->assertSelectorTextContains('#trafic-site', 'Accueil');
        $this->assertSelectorTextContains('#trafic-site', 'Google');
        $this->assertSelectorExists('#chart-trafic');

        // Les autres rôles ne le voient pas.
        $client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'ramassage']));
        $client->request('GET', 'http://localhost/');
        $this->assertSelectorNotExists('#trafic-site');
    }

    public function testAppareils(): void
    {
        $this->assertSame('Mobile', CompteurTrafic::appareil(self::MOBILE));
        $this->assertSame('Mobile', CompteurTrafic::appareil('Mozilla/5.0 (Linux; Android 14; SM-A546B) Chrome/130 Mobile Safari/537.36'));
        $this->assertSame('Tablette', CompteurTrafic::appareil('Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X)'));
        $this->assertSame('Ordinateur', CompteurTrafic::appareil('Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) Safari/605'));
    }
}
