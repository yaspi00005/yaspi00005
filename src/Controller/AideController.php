<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** « Besoin d'aide ? » : guide illustré, adapté au rôle de la personne connectée. */
class AideController extends AbstractController
{
    /** Page de l'application → section de l'aide ouverte par le bouton « ? » de l'en-tête. */
    public const SECTIONS = [
        'colis_new' => 'ajouter-colis', 'colis_edit' => 'ajouter-colis', 'colis_show' => 'fiche-colis', 'colis_index' => 'liste-colis',
        'client_' => 'clients', 'ramassage_' => 'ramassages', 'corbeille_' => 'corbeille', 'agence_' => 'guichet',
        'encaissement_' => 'encaissements', 'expedition_rentabilite' => 'rentabilite', 'expedition_' => 'expeditions',
        'paiement_' => 'paiements', 'admin_bonus' => 'bonus', 'admin_user' => 'utilisateurs', 'admin_journal' => 'journal',
        'app_accueil' => 'tableau-de-bord', 'app_application' => 'application-mobile', 'app_profil' => 'profil',
    ];

    #[Route('/aide', name: 'app_aide', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('aide/index.html.twig');
    }

    /** Le représentant n'a pas les sections des agents : ses équivalents. */
    private const SECTIONS_REPRESENTANT = ['colis_index' => 'recherche', 'colis_show' => 'encaisser'];

    public static function section(?string $route, bool $representant = false): string
    {
        foreach (($representant ? self::SECTIONS_REPRESENTANT : []) + self::SECTIONS as $prefixe => $ancre) {
            if ($route && str_starts_with($route, $prefixe)) {
                return $ancre;
            }
        }

        return '';
    }
}
