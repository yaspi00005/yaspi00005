<?php

namespace App\Tests\Controller;

use App\Enum\Destination;
use App\Repository\ColisRepository;
use App\Repository\UserRepository;
use App\Security\IdChiffreur;
use App\Util\Code128;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Étiquettes des colis : impression, QR code (fiche ou suivi public) et scanner. */
final class EtiquettesTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testImpressionDansTousLesFormats(): void
    {
        $this->connecter('admin');
        $colis = static::getContainer()->get(ColisRepository::class)->findOneBy([]);
        $hid = $this->hid($colis->getId());

        $this->client->request('GET', '/colis/'.$hid);
        $this->assertSelectorExists('#modal-etiquettes select[name="format"] option[value="a6"]');

        foreach (['thermique', 'a6', 'a4', 'petite', 'inconnu'] as $format) {
            $this->client->request('GET', '/colis/'.$hid.'/etiquettes.pdf', ['format' => $format, 'nombre' => 3, 'debut' => 2]);
            $this->assertResponseIsSuccessful();
            $this->assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
            $this->assertStringStartsWith('%PDF', (string) $this->client->getResponse()->getContent());
        }

        // Toutes les étiquettes d'une expédition : administrateur seulement.
        $expedition = $colis->getExpedition() ?? static::getContainer()->get(ColisRepository::class)->createQueryBuilder('c')->andWhere('c.expedition IS NOT NULL')->setMaxResults(1)->getQuery()->getSingleResult()->getExpedition();
        $this->client->request('GET', '/expeditions/'.$this->hid($expedition->getId()).'/etiquettes.pdf', ['format' => 'a6']);
        $this->assertResponseIsSuccessful();
        $this->connecter('ramassage');
        $this->client->request('GET', '/expeditions/'.$this->hid($expedition->getId()).'/etiquettes.pdf');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testQrCodeOuvreLaFicheOuLeSuiviPublic(): void
    {
        $colis = static::getContainer()->get(ColisRepository::class)->findOneBy([]);

        // Personne connectée : suivi public (sans données sensibles).
        $this->client->request('GET', '/c/'.$colis->getToken());
        $this->assertResponseRedirects('/suivi/'.$colis->getToken());

        // Équipe : fiche du colis.
        $this->connecter('admin');
        $this->client->request('GET', '/c/'.$colis->getToken());
        $this->assertResponseRedirects('/colis/'.$this->hid($colis->getId()));

        $this->client->request('GET', '/c/'.str_repeat('0', 40));
        $this->assertResponseStatusCodeSame(404);
    }

    public function testScanner(): void
    {
        $this->connecter('admin');
        $colis = static::getContainer()->get(ColisRepository::class)->findOneBy([]);
        $fiche = '/colis/'.$this->hid($colis->getId());

        $this->client->request('GET', '/scanner');
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', '/scanner', ['code' => strtolower($colis->getNumeroSuivi())]);
        $this->assertResponseRedirects($fiche, null, 'Code-barres ou numéro tapé');
        $this->client->request('GET', '/scanner', ['code' => 'https://app.sotrama.net/c/'.$colis->getToken()]);
        $this->assertResponseRedirects($fiche, null, 'QR code de l\'étiquette');
        $this->client->request('GET', '/scanner', ['code' => 'https://app.sotrama.net/suivi/'.$colis->getToken()]);
        $this->assertResponseRedirects($fiche, null, 'QR code du reçu');
        $this->client->request('GET', '/scanner', ['code' => 'ZZ-INCONNU']);
        $this->assertSelectorTextContains('.alert-danger, .toast, body', 'Aucun colis trouvé');

        // Le représentant de Bamako ne voit pas un colis destiné au Sénégal.
        $senegal = static::getContainer()->get(ColisRepository::class)->createQueryBuilder('c')->join('c.expedition', 'e')
            ->andWhere('e.destination = :d')->setParameter('d', Destination::Senegal)->setMaxResults(1)->getQuery()->getSingleResult();
        $this->connecter('bamako');
        $this->client->request('GET', '/scanner', ['code' => $senegal->getNumeroSuivi()]);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'ne fait pas partie de vos colis');
    }

    public function testCode128(): void
    {
        $modules = Code128::modules('ST2610-0001');
        // Zones blanches (2 × 10) + départ, 11 caractères, contrôle (11 modules chacun) + arrêt (13).
        $this->assertSame(20 + 11 * 13 + 13, array_sum($modules));
        $this->assertStringStartsWith('data:image/png;base64,', Code128::pngDataUri('ST2610-0001'));
    }

    private function connecter(string $login): void
    {
        $this->client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => $login]));
    }

    private function hid(int $id): string
    {
        return static::getContainer()->get(IdChiffreur::class)->encode($id);
    }
}
