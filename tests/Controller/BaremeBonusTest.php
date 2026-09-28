<?php

namespace App\Tests\Controller;

use App\Entity\PalierBonus;
use App\Repository\PalierBonusRepository;
use App\Repository\UserRepository;
use App\Service\BonusFidelite;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Le barème du bonus fidélité se modifie depuis l'administration, sans toucher au code. */
final class BaremeBonusTest extends WebTestCase
{
    /** Les autres tests utilisent le barème d'origine : il est remis en place. */
    protected function tearDown(): void
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        foreach ($em->getRepository(PalierBonus::class)->findAll() as $p) {
            $em->remove($p);
        }
        $em->flush();
        foreach (BonusFidelite::BAREME_INITIAL as [$seuil, $bonus]) {
            $em->persist(new PalierBonus($seuil * 100, $bonus * 100));
        }
        $em->flush();
        parent::tearDown();
    }

    public function testModificationDuBareme(): void
    {
        $client = static::createClient();
        $client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'admin']));
        $this->assertSame(15000, static::getContainer()->get(BonusFidelite::class)->bonusPour(160000), 'Barème d\'origine : 1 600 € → 150 €.');

        $crawler = $client->request('GET', '/admin/bonus/bareme');
        $this->assertResponseIsSuccessful();
        $this->assertCount(14, $crawler->filter('#paliers tr'));

        $token = $crawler->filter('input[name="_token"]')->attr('value');
        // Seuils saisis à la française ; un doublon et une ligne vide.
        $client->request('POST', '/admin/bonus/bareme', ['_token' => $token, 'seuil' => ['100', '100'], 'montant' => ['5', '6']]);
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.alert-danger', 'deux fois');

        $client->request('POST', '/admin/bonus/bareme', ['_token' => $token, 'seuil' => ['1 000,00', '100', ''], 'montant' => ['75,50', '5', '']]);
        $this->assertResponseRedirects('/admin/bonus/bareme');
        $bareme = static::getContainer()->get(PalierBonusRepository::class)->bareme();
        $this->assertCount(2, $bareme);
        $bonus = static::getContainer()->get(BonusFidelite::class);
        $this->assertSame(7550, $bonus->bonusPour(160000));
        $this->assertSame(500, $bonus->bonusPour(15000));
        $this->assertSame(0, $bonus->bonusPour(9999));

        $client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'ramassage']));
        $client->request('GET', '/admin/bonus/bareme');
        $this->assertResponseStatusCodeSame(403);
    }
}
