<?php

namespace App\Tests\Controller;

use App\Entity\Client;
use App\Repository\ClientRepository;
use App\Repository\UserRepository;
use App\Security\IdChiffreur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Seul l'administrateur supprime un client, après une alerte « irréversible », et seulement s'il n'a pas de colis. */
final class SuppressionClientTest extends WebTestCase
{
    public function testSuppressionAvecAlerteEtProtectionDesClientsAvecColis(): void
    {
        $client = static::createClient();
        $client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'admin']));
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hid = static::getContainer()->get(IdChiffreur::class);

        $vide = (new Client())->setPrenom('Fiche')->setNom('Erreur')->setTelephone('07 11 22 33 44');
        $em->persist($vide);
        $em->flush();
        $id = $vide->getId();

        $crawler = $client->request('GET', '/clients/'.$hid->encode($id));
        $form = $crawler->filter('form[action$="/supprimer"]');
        $this->assertStringContainsString('IRRÉVERSIBLE', (string) $form->attr('data-confirm'));
        $client->submit($form->form());
        $this->assertResponseRedirects('/clients');
        $em->clear();
        $this->assertNull(static::getContainer()->get(ClientRepository::class)->find($id));

        // Client avec des colis : pas de bouton, et refus côté serveur.
        $avecColis = null;
        foreach (static::getContainer()->get(ClientRepository::class)->findAll() as $c) {
            if (!$c->getColis()->isEmpty()) {
                $avecColis = $c;
                break;
            }
        }
        $client->request('GET', '/clients/'.$hid->encode($avecColis->getId()));
        $this->assertSelectorNotExists('form[action$="/supprimer"]');
        $this->assertSelectorTextContains('body', 'Suppression impossible');

        $client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'ramassage']));
        $client->request('GET', '/clients/'.$hid->encode($avecColis->getId()));
        $this->assertSelectorNotExists('form[action$="/supprimer"]');
    }
}
