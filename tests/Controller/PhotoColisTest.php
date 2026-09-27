<?php

namespace App\Tests\Controller;

use App\Enum\StatutExpedition;
use App\Repository\ClientRepository;
use App\Repository\ColisRepository;
use App\Repository\ExpeditionRepository;
use App\Repository\UserRepository;
use App\Security\IdChiffreur;
use App\Service\PhotoUploader;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class PhotoColisTest extends WebTestCase
{
    private function image(): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'photo').'.png';
        $img = imagecreatetruecolor(40, 30);
        imagefill($img, 0, 0, imagecolorallocate($img, 0, 161, 93));
        imagepng($img, $chemin);

        return new UploadedFile($chemin, 'colis.png', 'image/png', null, true);
    }

    public function testPhotoDuColisPriseALaSaisie(): void
    {
        $client = static::createClient();
        $client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'ramassage']));

        $crawler = $client->request('GET', '/colis/nouveau');
        $this->assertSelectorExists('.js-photo .js-photo-camera');
        $this->assertSelectorExists('input#colis_photoFile[accept="image/*"]');

        $valeurs = $crawler->selectButton('Enregistrer le colis')->form()->getPhpValues();
        $valeurs['colis']['client'] = static::getContainer()->get(ClientRepository::class)->findOneBy([])->getId();
        $valeurs['colis']['destinataireNom'] = 'Photo Test';
        $valeurs['colis']['destinataireTelephone'] = '76 00 00 01';
        $valeurs['colis']['expedition'] = static::getContainer()->get(ExpeditionRepository::class)->findOneBy(['statut' => StatutExpedition::EnPreparation])->getId();
        $valeurs['colis']['typeExpedition'] = 'standard';
        $valeurs['colis']['articles'] = [['typeMarchandise' => 'Divers', 'poidsVolume' => '1', 'prix' => '10']];

        $client->request('POST', '/colis/nouveau', $valeurs, ['colis' => ['photoFile' => $this->image(), 'articles' => [['photoFile' => $this->image()]]]]);
        $this->assertResponseRedirects();

        $colis = static::getContainer()->get(ColisRepository::class)->findOneBy(['destinataireNom' => 'Photo Test']);
        $this->assertNotNull($colis->getPhoto());
        $this->assertNotNull($colis->getArticles()->first()->getPhoto());
        $photos = static::getContainer()->get(PhotoUploader::class);
        $this->assertFileExists($photos->chemin($colis->getPhoto()));

        $id = static::getContainer()->get(IdChiffreur::class)->encode($colis->getId());
        $client->request('GET', "/colis/$id");
        $this->assertSelectorExists('img.photo-colis');
        $client->request('GET', "/colis/$id/photo");
        $this->assertResponseIsSuccessful();

        // Photo réservée aux utilisateurs connectés.
        $client->getCookieJar()->clear(); // déconnexion
        $client->request('GET', "/colis/$id/photo");
        $this->assertResponseRedirects();
        $this->assertStringContainsString('/connexion', (string) $client->getResponse()->headers->get('Location'));

        $photos->supprimer($colis->getPhoto());
        $photos->supprimer($colis->getArticles()->first()->getPhoto());
    }
}
