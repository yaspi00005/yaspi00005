<?php

namespace App\Controller;

use App\Service\DocumentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Application Android : page de téléchargement et fichier de vérification Digital Asset Links. */
class ApplicationController extends AbstractController
{
    public function __construct(
        #[Autowire('%app.apk_url%')] private readonly string $apkUrl,
        #[Autowire('%app.android_package%')] private readonly string $package,
        #[Autowire('%app.android_sha256%')] private readonly string $empreintes,
    ) {
    }

    #[Route('/application', name: 'app_application', methods: ['GET'])]
    public function telechargement(DocumentService $documents): Response
    {
        $page = $this->generateUrl('app_application', [], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->render('application/index.html.twig', [
            'apk' => $this->apkUrl,
            'qr' => $documents->qrCodeDataUri($page, 260),
            'page' => $page,
        ]);
    }

    /**
     * Prouve à Android que l'application et le site appartiennent au même propriétaire :
     * l'application s'ouvre alors en plein écran, sans barre d'adresse.
     */
    #[Route('/.well-known/assetlinks.json', name: 'app_assetlinks', methods: ['GET'])]
    public function assetlinks(): JsonResponse
    {
        $empreintes = array_values(array_filter(array_map('trim', explode(',', $this->empreintes))));
        $contenu = $empreintes ? [[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => ['namespace' => 'android_app', 'package_name' => $this->package, 'sha256_cert_fingerprints' => $empreintes],
        ]] : [];

        $reponse = new JsonResponse($contenu);
        if ($contenu) {
            $reponse->setPublic();
            $reponse->setMaxAge(3600);
        } else {
            // Pas encore configuré : ne pas laisser un « [] » en cache.
            $reponse->headers->set('Cache-Control', 'no-store');
        }

        return $reponse;
    }
}
