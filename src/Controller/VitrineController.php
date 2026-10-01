<?php

namespace App\Controller;

use App\Entity\Expedition;
use App\Enum\Destination;
use App\Repository\ColisRepository;
use App\Repository\ExpeditionRepository;
use App\Security\IdChiffreur;
use App\Service\SuiviColis;
use App\Vitrine\CompteurTrafic;
use App\Vitrine\DemandeRamassage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function Symfony\Component\String\u;

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
        private readonly ExpeditionRepository $expeditions,
    ) {
    }

    #[Route('/', name: 'vitrine_accueil', condition: self::CONDITION, methods: ['GET'], priority: 10)]
    public function accueil(ExpeditionRepository $expeditions): Response
    {
        return $this->page('vitrine/accueil.html.twig', [
            // Départs annoncés par l'administrateur (bouton « Publier sur le site » de l'expédition).
            'departs' => $expeditions->annoncesPubliees(),
            'hero' => $this->photo('hero'),
            // Illustrations par défaut, remplacées par bamako.jpg / dakar.jpg si déposées.
            'bamako' => $this->photo('bamako') ?? 'images/vitrine/illustrations/bamako.svg',
            'dakar' => $this->photo('dakar') ?? 'images/vitrine/illustrations/dakar.svg',
            'galerie' => $this->galerie(),
            'photosEquipe' => $this->photosEquipe(),
            'tailles' => array_map($this->taille(...), ['bamako' => $this->photo('bamako'), 'dakar' => $this->photo('dakar')]),
        ]);
    }

    #[Route('/suivi', name: 'vitrine_suivi', condition: self::CONDITION, methods: ['GET', 'POST'], priority: 10)]
    public function suivi(Request $request, SuiviColis $suivi, RateLimiterFactory $suiviLimiter, CompteurTrafic $trafic): Response
    {
        $numero = mb_strtoupper(trim($request->request->getString('numero', $request->query->getString('numero'))));
        $erreur = null;

        if ($request->isMethod('POST')) {
            if (!$suiviLimiter->create($request->getClientIp())->consume()->isAccepted()) {
                $erreur = 'Trop de tentatives. Réessayez dans quelques minutes.';
            } elseif (!$this->isCsrfTokenValid('suivi', $request->request->getString('_token'))) {
                $erreur = 'La page a expiré, réessayez.';
            } elseif ($colis = $suivi->trouver($numero, $request->request->getString('telephone'))) {
                $trafic->action('colis_suivi');
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

    #[Route('/ramassage', name: 'vitrine_ramassage', condition: self::CONDITION, methods: ['GET', 'POST'], priority: 10)]
    public function ramassage(Request $request, DemandeRamassage $demandes, ExpeditionRepository $expeditions, IdChiffreur $chiffreur, RateLimiterFactory $demandeRamassageLimiter, CompteurTrafic $trafic): Response
    {
        // Départ choisi depuis « Prochains départs » : uniquement s'il est encore annoncé sur le site.
        $departId = $chiffreur->decodeOptionnel($request->request->get('depart', $request->query->get('depart')));
        $depart = $departId ? $expeditions->find($departId) : null;
        if (!$depart instanceof Expedition || !$depart->isAnnonceVisible()) {
            $depart = null;
        }

        $donnees = DemandeRamassage::lire($request->request->all());
        $donnees['destination'] = $donnees['destination'] ?: ($depart?->getDestination()->value ?? '');
        $erreurs = [];

        if ($request->isMethod('POST')) {
            if ('' !== $request->request->getString('site_web')) {
                // Champ piège invisible rempli : robot. On fait comme si tout s'était bien passé.
                return $this->redirectToRoute('vitrine_ramassage_merci', [], Response::HTTP_SEE_OTHER);
            }
            if (!$this->isCsrfTokenValid('demande-ramassage', $request->request->getString('_token'))) {
                $erreurs['general'] = 'La page a expiré, vérifiez puis renvoyez le formulaire.';
            } elseif (!($erreurs = $demandes->valider($donnees, $depart))) {
                if (!$demandeRamassageLimiter->create($request->getClientIp())->consume()->isAccepted()) {
                    $erreurs['general'] = 'Trop de demandes envoyées depuis votre connexion. Réessayez plus tard ou écrivez-nous sur WhatsApp.';
                } else {
                    $ramassage = $demandes->enregistrer($donnees, $depart);
                    $trafic->action('demande_ramassage');
                    $request->getSession()->set('demande_ramassage', [
                        'prenom' => $donnees['prenom'],
                        'date' => $ramassage->getDatePrevue()->format('Y-m-d'),
                        'creneau' => DemandeRamassage::CRENEAUX[$donnees['creneau']][0],
                        'adresse' => $ramassage->getAdresse(),
                        'destination' => Destination::from($donnees['destination'])->ville(),
                    ]);

                    return $this->redirectToRoute('vitrine_ramassage_merci', [], Response::HTTP_SEE_OTHER);
                }
            }
        }

        return $this->page('vitrine/ramassage.html.twig', [
            'donnees' => $donnees,
            'erreurs' => $erreurs,
            'depart' => $depart,
            'creneaux' => DemandeRamassage::CRENEAUX,
            'destinations' => Destination::cases(),
            'premierJour' => $demandes->premierJour(),
            'dernierJour' => $demandes->dernierJour($depart),
        ], new Response(null, $erreurs ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/ramassage/merci', name: 'vitrine_ramassage_merci', condition: self::CONDITION, methods: ['GET'], priority: 10)]
    public function ramassageMerci(Request $request): Response
    {
        $reponse = $this->page('vitrine/ramassage_merci.html.twig', ['demande' => $request->getSession()->get('demande_ramassage')]);
        $reponse->headers->set('X-Robots-Tag', 'noindex');

        return $reponse;
    }

    #[Route('/mentions-legales', name: 'vitrine_mentions', condition: self::CONDITION, methods: ['GET'], priority: 10)]
    public function mentions(): Response
    {
        return $this->page('vitrine/mentions.html.twig');
    }

    #[Route('/confidentialite', name: 'vitrine_confidentialite', condition: self::CONDITION, methods: ['GET'], priority: 10)]
    public function confidentialite(): Response
    {
        return $this->page('vitrine/confidentialite.html.twig');
    }

    #[Route('/conditions-utilisation', name: 'vitrine_conditions', condition: self::CONDITION, methods: ['GET'], priority: 10)]
    public function conditions(): Response
    {
        return $this->page('vitrine/conditions.html.twig');
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
        $urls = array_map(fn ($r) => $this->generateUrl($r, [], UrlGeneratorInterface::ABSOLUTE_URL), ['vitrine_accueil', 'vitrine_suivi', 'vitrine_ramassage', 'vitrine_mentions', 'vitrine_confidentialite', 'vitrine_conditions']);
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .implode('', array_map(fn ($u) => '<url><loc>'.htmlspecialchars($u).'</loc></url>', $urls)).'</urlset>';

        return new Response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    private function page(string $vue, array $donnees = [], ?Response $reponse = null): Response
    {
        $reponse = $this->render($vue, ['infos' => $this->infos, 'logo' => $this->photo('logo') ?? 'images/logo-sotrama.png', 'compteur' => $this->compteur()] + $donnees, $reponse);
        $reponse->setVary(['Host'], false);

        return $reponse;
    }

    /**
     * Compte à rebours du bandeau en haut des pages : prochain départ publié, jusqu'à la fin de la date limite
     * de dépôt (sinon jusqu'au jour du départ), heure de Paris.
     *
     * @return array{depart: \App\Entity\Expedition, fin: \DateTimeImmutable, depot: bool}|null
     */
    private function compteur(): ?array
    {
        $depart = $this->expeditions->annoncesPubliees(1)[0] ?? null;
        if (!$depart) {
            return null;
        }
        $paris = new \DateTimeZone('Europe/Paris');
        $limite = $depart->getDateLimiteDepot();
        $fin = $limite
            ? new \DateTimeImmutable($limite->format('Y-m-d').' 23:59:59', $paris)
            : new \DateTimeImmutable($depart->getDateDepart()->format('Y-m-d').' 00:00:00', $paris);

        // Date limite de dépôt passée : on compte jusqu'au départ.
        if ($limite && $fin < new \DateTimeImmutable('now', $paris)) {
            $fin = new \DateTimeImmutable($depart->getDateDepart()->format('Y-m-d').' 00:00:00', $paris);
            $limite = null;
        }

        return $fin > new \DateTimeImmutable('now', $paris) ? ['depart' => $depart, 'fin' => $fin, 'depot' => null !== $limite] : null;
    }

    /**
     * Largeur et hauteur réelles d'une photo déposée : indiquées au navigateur pour qu'il réserve la bonne
     * place (sinon Safari garde les proportions des dessins par défaut et ajoute des bandes blanches).
     *
     * @return array{0: int, 1: int}
     */
    private function taille(?string $photo): array
    {
        $dimensions = $photo ? @getimagesize($this->public.'/'.$photo) : false;

        return $dimensions ? [$dimensions[0], $dimensions[1]] : [600, 220];
    }

    /** Photo facultative public/images/vitrine/<nom>.(jpg|png|webp), chemin relatif à public/. */
    private function photo(string $nom): ?string
    {
        $fichiers = glob($this->public.'/images/vitrine/'.$nom.'.'.self::PHOTOS, \GLOB_BRACE) ?: [];

        return $fichiers ? substr($fichiers[0], \strlen($this->public) + 1) : null;
    }

    /**
     * Photo de chaque membre de l'équipe : equipe/<prénom>.jpg (« Yacouba Traoré » → equipe/yacouba.jpg).
     *
     * @return array<string, string> nom → chemin
     */
    private function photosEquipe(): array
    {
        $photos = [];
        foreach ($this->infos['equipe'] ?? [] as $membre) {
            $prenom = u(explode(' ', trim($membre['nom']))[0])->ascii()->lower()->toString();
            if ($photo = $this->photo('equipe/'.$prenom)) {
                $photos[$membre['nom']] = $photo;
            }
        }

        return $photos;
    }

    /** @return list<string> photos de public/images/vitrine/galerie/, triées par nom */
    private function galerie(): array
    {
        $fichiers = glob($this->public.'/images/vitrine/galerie/*.'.self::PHOTOS, \GLOB_BRACE) ?: [];
        sort($fichiers);

        return array_map(fn ($f) => substr($f, \strlen($this->public) + 1), \array_slice($fichiers, 0, 12));
    }
}
