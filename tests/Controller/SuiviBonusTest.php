<?php

namespace App\Tests\Controller;

use App\Repository\ClientRepository;
use App\Repository\MouvementBonusRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SuiviBonusTest extends WebTestCase
{
    private function connecte(string $login): KernelBrowser
    {
        $client = static::createClient();
        $client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => $login]));

        return $client;
    }

    public function testPageEtTotaux(): void
    {
        $client = $this->connecte('admin');
        $client->request('GET', '/admin/bonus');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Bonus distribués');
        $this->assertSelectorExists('a[href="/admin/bonus"]'); // lien du menu

        $repo = static::getContainer()->get(MouvementBonusRepository::class);
        $totaux = $repo->totaux($repo->filtreQb());
        $this->assertGreaterThan(0, $totaux['gagne'], 'Les colis de démonstration rapportent du bonus.');

        // Cohérence : somme de tous les mouvements = somme des soldes clients.
        $somme = (int) $repo->createQueryBuilder('m')->select('SUM(m.montant)')->getQuery()->getSingleScalarResult();
        $this->assertSame(static::getContainer()->get(ClientRepository::class)->soldeBonusGlobal()['solde'], $somme);
        $this->assertSame($somme, $totaux['gagne'] - $totaux['utilise'] + $totaux['credits'] - $totaux['debits']);
    }

    public function testFiltreParTypeEtExport(): void
    {
        $client = $this->connecte('admin');
        $crawler = $client->request('GET', '/admin/bonus', ['type' => 'gain']);
        $this->assertResponseIsSuccessful();
        $types = $crawler->filter('tbody .badge')->each(fn ($n) => $n->text());
        $this->assertNotEmpty($types);
        $this->assertSame(['Gagné sur un colis'], array_values(array_unique($types)));

        $client->request('GET', '/admin/bonus', ['export' => 'csv']);
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('text/csv', (string) $client->getResponse()->headers->get('Content-Type'));
    }

    public function testReserveAuxAdministrateurs(): void
    {
        foreach (['ramassage', 'bamako'] as $login) {
            $this->connecte($login)->request('GET', '/admin/bonus');
            $this->assertResponseStatusCodeSame(403);
            self::ensureKernelShutdown();
        }
    }
}
