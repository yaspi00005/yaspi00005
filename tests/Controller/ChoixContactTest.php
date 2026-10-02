<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Site vitrine : les boutons WhatsApp et « Appeler » proposent les trois contacts de l'équipe. */
final class ChoixContactTest extends WebTestCase
{
    public function testTroisContacts(): void
    {
        $client = static::createClient([], ['HTTP_HOST' => 'sotrama.net']);
        $crawler = $client->request('GET', '/');
        $equipe = static::getContainer()->getParameter('app.vitrine')['equipe'];

        $this->assertCount(\count($equipe), $crawler->filter('#choix-contact [data-choix-whatsapp]'));
        foreach ($equipe as $p) {
            $this->assertSelectorExists('#choix-contact [data-choix-whatsapp="'.$p['whatsapp'].'"]');
        }
        // Les liens vers un membre précis (section équipe) ouvrent directement son WhatsApp.
        $this->assertGreaterThanOrEqual(\count($equipe), $crawler->filter('.membre a[href^="https://wa.me/"][data-direct]')->count());
        $this->assertSelectorTextContains('.etapes', 'Yacouba');
    }
}
