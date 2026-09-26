<?php

namespace App\Controller;

use App\Service\DocumentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Application Android : page de téléchargement et fichier de vérification Digital Asset Links. */
class ApplicationController extends AbstractController
{
    public function __construct(
        #[Autowire('%app.apk_url%')] private readonly string $apkUrl,
        /** APK hébergé par le site (dossier apk/ du projet), prioritaire sur APP_APK_URL. */
        #[Autowire('%app.apk_fichier%')] private readonly string $apkFichier,
        #[Autowire('%app.android_package%')] private readonly string $package,
        #[Autowire('%app.android_sha256%')] private readonly string $empreintes,
    ) {
    }

    #[Route('/application', name: 'app_application', methods: ['GET'])]
    public function telechargement(DocumentService $documents): Response
    {
        $page = $this->generateUrl('app_application', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $local = is_file($this->apkFichier);

        return $this->render('application/index.html.twig', [
            // ?v= : le téléphone ne réutilise pas une ancienne version en cache après une mise à jour.
            'apk' => $local ? $this->generateUrl('app_apk', ['v' => filemtime($this->apkFichier)]) : $this->apkUrl,
            'apkTaille' => $local ? filesize($this->apkFichier) : null,
            'apkDate' => $local ? (new \DateTimeImmutable())->setTimestamp(filemtime($this->apkFichier)) : null,
            'qr' => $documents->qrCodeDataUri($page, 260),
            'page' => $page,
        ]);
    }

    /** Fichier apk/sotrama.apk du projet, avec le type attendu par Android. */
    #[Route('/application/sotrama.apk', name: 'app_apk', methods: ['GET'])]
    public function apk(): BinaryFileResponse
    {
        if (!is_file($this->apkFichier)) {
            throw $this->createNotFoundException('Application pas encore disponible.');
        }
        $reponse = new BinaryFileResponse($this->apkFichier, headers: ['Content-Type' => 'application/vnd.android.package-archive'], autoEtag: true, autoLastModified: true);
        $reponse->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'sotrama.apk');
        $reponse->setPublic();
        $reponse->setMaxAge(300);

        return $reponse;
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
