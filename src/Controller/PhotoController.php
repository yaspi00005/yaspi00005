<?php

namespace App\Controller;

use App\Entity\Article;
use App\Entity\Colis;
use App\Entity\PhotoColis;
use App\Security\ColisVoter;
use App\Service\PhotoUploader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Routing\Attribute\Route;

class PhotoController extends AbstractController
{
    /** Identifiant chiffré (voir App\\Security\\IdChiffreur). */
    private const ID = '[A-Za-z0-9_-]{22}';

    #[Route('/articles/{id}/photo', name: 'article_photo', requirements: ['id' => self::ID], methods: ['GET'])]
    public function photo(Article $article, PhotoUploader $photos): BinaryFileResponse
    {
        $this->denyAccessUnlessGranted(ColisVoter::VOIR, $article->getColis());
        if (!$article->getPhoto() || !is_file($chemin = $photos->chemin($article->getPhoto()))) {
            throw $this->createNotFoundException();
        }

        return $this->servir($chemin);
    }

    #[Route('/colis/{id}/photo', name: 'colis_photo', requirements: ['id' => self::ID], methods: ['GET'])]
    public function photoColis(Colis $colis, PhotoUploader $photos): BinaryFileResponse
    {
        $this->denyAccessUnlessGranted(ColisVoter::VOIR, $colis);
        if (!$colis->getPhoto() || !is_file($chemin = $photos->chemin($colis->getPhoto()))) {
            throw $this->createNotFoundException();
        }

        return $this->servir($chemin);
    }

    #[Route('/photos-colis/{id}', name: 'colis_photo_supplementaire', requirements: ['id' => self::ID], methods: ['GET'])]
    public function photoSupplementaire(PhotoColis $photo, PhotoUploader $photos): BinaryFileResponse
    {
        $this->denyAccessUnlessGranted(ColisVoter::VOIR, $photo->getColis());
        if (!is_file($chemin = $photos->chemin($photo->getFichier()))) {
            throw $this->createNotFoundException();
        }

        return $this->servir($chemin);
    }

    private function servir(string $chemin): BinaryFileResponse
    {
        $response = new BinaryFileResponse($chemin);
        $response->setPrivate();
        $response->setMaxAge(86400);

        return $response;
    }
}
