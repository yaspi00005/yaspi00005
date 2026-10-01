<?php

namespace App\Tests\Controller;

use App\Repository\ClientRepository;
use App\Repository\UserRepository;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Liste des clients : adresse affichée et export Excel réservé à l'administrateur. */
final class ListeClientsTest extends WebTestCase
{
    public function testAdresseEtExportExcel(): void
    {
        $client = static::createClient();
        $users = static::getContainer()->get(UserRepository::class);
        $fiche = static::getContainer()->get(ClientRepository::class)->findOneBy([], ['nom' => 'ASC']);

        $client->loginUser($users->findOneBy(['username' => 'ramassage']));
        $client->request('GET', '/clients', ['q' => $fiche->getAdresse()]);
        $this->assertSelectorTextContains('table', $fiche->getAdresse(), 'Adresse affichée et recherchable');
        $this->assertSelectorNotExists('a[href*="export=csv"]');
        $client->request('GET', '/clients', ['export' => 'csv']);
        $this->assertResponseStatusCodeSame(403);

        $client->loginUser($users->findOneBy(['username' => 'admin']));
        $client->request('GET', '/clients');
        $this->assertSelectorExists('a[href*="export=csv"]');
        $client->request('GET', '/clients', ['export' => 'csv']);
        $this->assertResponseIsSuccessful();
        $fichier = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($fichier, (string) $client->getInternalResponse()->getContent());
        $lignes = IOFactory::load($fichier)->getActiveSheet()->toArray();
        unlink($fichier);
        $this->assertSame(['Nom', 'Prénom', 'Téléphone', 'E-mail', 'Adresse'], \array_slice($lignes[5], 0, 5));
        $this->assertContains($fiche->getAdresse(), array_column($lignes, 4));
    }
}
