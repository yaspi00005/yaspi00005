<?php

namespace App\Tests\Controller;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Page « Besoin d'aide ? » : contenu adapté au rôle, captures présentes, bouton « ? » contextuel. */
final class AideTest extends WebTestCase
{
    public function testAideSelonLeRole(): void
    {
        $client = static::createClient();
        $users = static::getContainer()->get(UserRepository::class);
        $dossier = static::getContainer()->getParameter('kernel.project_dir').'/public';

        foreach ([
            'admin' => ['#expeditions', '#encaisser', '#ajouter-colis'],
            'ramassage' => ['#ajouter-colis', '#corbeille'],
            'bamako' => ['#guichet', '#encaissements'],
        ] as $login => $sections) {
            $client->loginUser($users->findOneBy(['username' => $login]));
            $crawler = $client->request('GET', '/aide');
            $this->assertResponseIsSuccessful();
            foreach ($sections as $s) {
                $this->assertSelectorExists($s, "$login voit $s");
            }
            $crawler->filter('.capture-aide img')->each(function ($img) use ($dossier) {
                $this->assertFileExists($dossier.parse_url((string) $img->attr('src'), \PHP_URL_PATH));
            });
        }

        // Le représentant ne voit pas les sections des agents ni de l'administration.
        $this->assertSelectorNotExists('#ajouter-colis');
        $this->assertSelectorNotExists('#rentabilite');
        // Le bouton « ? » de l'en-tête ouvre la section de la page en cours.
        $client->request('GET', '/encaissements');
        $this->assertSelectorExists('a[href="/aide#encaissements"]');
    }
}
