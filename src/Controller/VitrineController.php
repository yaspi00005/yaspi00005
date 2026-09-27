<?php

namespace App\Controller;

use App\Repository\ColisRepository;
use App\Service\SuiviColis;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Site vitrine public (sotrama.net) : présentation de SOTRAMA et suivi des colis par les clients.
 * Routes actives uniquement sur les domaines de VITRINE_DOMAINES (voir App\Vitrine\Vitrine).
 */
class VitrineController extends AbstractController
{
    private const CONDITION = "service('vitrine').estVitrine(request)";
    private const PHOTOS = '{jpg,jpeg,png,webp}';

    public function __construct(
        #[Autowire('%app.vitrine%')] private readonly array $infos,
        #[Autowire('%kernel.project_dir%/public')] private readonly string $public,
    ) {
    }

    #[Route('/', name: 'vitrine_accueil', condition: self::CONDITION, methods: ['GET'], priority: 10)]
    public function accueil(): Response
    {
        return $this->page('vitrine/accueil.html.twig', [
            'hero' => $this->photo('hero'),
            'galerie' => $this->galerie(),
        ]);
    }

    #[Route('/suivi', name: 'vitrine_suivi', condition: self::CONDITION, methods: ['GET', 'POST'], priority: 10)]
    public function suivi(Request $request, SuiviColis $suivi, RateLimiterFactory $suiviLimiter): Response
    {
        $numero = mb_strtoupper(trim($request->request->getString('numero', $request->query->getString('numero'))));
        $erreur = null;

        if ($request->isMethod('POST')) {
            if (!$suiviLimiter->create($request->getClientIp())->consume()->isAccepted()) {
                $erreur = 'Trop de tentatives. Réessayez dans quelques minutes.';
            } elseif (!$this->isCsrfTokenValid('suivi', $request->request->getString('_token'))) {
                $erreur = 'La page a expiré, réessayez.';
            } elseif ($colis = $suivi->trouver($numero, $request->request->getString('telephone'))) {
                return $this->redirectToRoute('vitrine_suivi_colis', ['token' => $colis->getToken()]);
            } else {
                $erreur = 'Aucun colis ne correspond à ce numéro et à ce téléphone. Vérifiez le numéro inscrit sur votre reçu.';
            }
        }

        return $this->page('vitrine/suivi.html.twig', ['numero' => $numero, 'erreur' => $erreur],
            new Response(null, $erreur ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/suivi/{token}', name: 'vitrine_suivi_colis', requirements: ['token' => '[a-f0-9]{40}'], condition: self::CONDITION, methods: ['GET'], priority: 10)]
    public function colis(string $token, ColisRepository $colisRepository): Response
    {
        $colis = $colisRepository->findOneBy(['token' => $token]) ?? throw $this->createNotFoundException('Colis introuvable.');

        $reponse = $this->page('vitrine/colis.html.twig', ['colis' => $colis]);
        $reponse->headers->set('X-Robots-Tag', 'noindex');

        return $reponse;
    }

    #[Route('/mentions-legales', name: 'vitrine_mentions', condition: self::CONDITION, methods: ['GET'], priority: 10)]
    public function mentions(): Response
    {
        return $this->page('vitrine/mentions.html.twig');
    }

    #[Route('/robots.txt', name: 'vitrine_robots', condition: self::CONDITION, methods: ['GET'], priority: 10)]
    public function robots(): Response
    {
        $sitemap = $this->generateUrl('vitrine_sitemap', [], UrlGeneratorInterface::ABSOLUTE_URL);

        return new Response("User-agent: *\nDisallow: /suivi/\nSitemap: $sitemap\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    #[Route('/sitemap.xml', name: 'vitrine_sitemap', condition: self::CONDITION, methods: ['GET'], priority: 10)]
    public function sitemap(): Response
    {
        $urls = array_map(fn ($r) => $this->generateUrl($r, [], UrlGeneratorInterface::ABSOLUTE_URL), ['vitrine_accueil', 'vitrine_suivi', 'vitrine_mentions']);
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .implode('', array_map(fn ($u) => '<url><loc>'.htmlspecialchars($u).'</loc></url>', $urls)).'</urlset>';

        return new Response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    private function page(string $vue, array $donnees = [], ?Response $reponse = null): Response
    {
        $reponse = $this->render($vue, ['infos' => $this->infos, 'logo' => $this->photo('logo') ?? 'images/logo-sotrama.png'] + $donnees, $reponse);
        $reponse->setVary(['Host'], false);

        return $reponse;
    }

    /** Photo facultative public/images/vitrine/<nom>.(jpg|png|webp), chemin relatif à public/. */
    private function photo(string $nom): ?string
    {
        $fichiers = glob($this->public.'/images/vitrine/'.$nom.'.'.self::PHOTOS, \GLOB_BRACE) ?: [];

        return $fichiers ? substr($fichiers[0], \strlen($this->public) + 1) : null;
    }

    /** @return list<string> photos de public/images/vitrine/galerie/, triées par nom */
    private function galerie(): array
    {
        $fichiers = glob($this->public.'/images/vitrine/galerie/*.'.self::PHOTOS, \GLOB_BRACE) ?: [];
        sort($fichiers);

        return array_map(fn ($f) => substr($f, \strlen($this->public) + 1), \array_slice($fichiers, 0, 12));
    }
}
