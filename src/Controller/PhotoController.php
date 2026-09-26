<?php

namespace App\Controller;

use App\Entity\Article;
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
        $response = new BinaryFileResponse($chemin);
        $response->setPrivate();
        $response->setMaxAge(86400);

        return $response;
    }
}
