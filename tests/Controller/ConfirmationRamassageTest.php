<?php

namespace App\Tests\Controller;

use App\Enum\StatutRamassage;
use App\Repository\RamassageRepository;
use App\Repository\UserRepository;
use App\Security\IdChiffreur;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ConfirmationRamassageTest extends WebTestCase
{
    public function testLeClientConfirmeLeRendezVous(): void
    {
        $client = static::createClient();
        $client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'ramassage']));
        $ramassage = static::getContainer()->get(RamassageRepository::class)->findOneBy(['statut' => StatutRamassage::EnAttente]);
        $id = static::getContainer()->get(IdChiffreur::class)->encode($ramassage->getId());

        $crawler = $client->request('GET', '/ramassages/'.$id);
        $this->assertSelectorTextContains('.alert-warning', 'pas encore confirmé');
        $client->submit($crawler->selectButton('Le client a confirmé')->form());
        $client->followRedirect();

        $this->assertSelectorTextContains('.alert-success', 'Ramassage confirmé');
        $this->assertSelectorTextContains('.alert-info', 'confirmé par le client');
        $this->assertSame(StatutRamassage::Confirme, static::getContainer()->get(RamassageRepository::class)->find($ramassage->getId())->getStatut());

        // Toujours dans « À venir » et dans la légende.
        $client->request('GET', '/ramassages');
        $this->assertSelectorExists('.fa-check-circle[title="Confirmé par le client"]');
        $this->assertSelectorTextContains('body', 'Confirmé par le client');
    }
}
