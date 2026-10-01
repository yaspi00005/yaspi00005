<?php

namespace App\Vitrine;

use App\Entity\TraficVitrine;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/**
 * Mesure d'audience du site vitrine, anonyme et sans cookie (exemptée de consentement, recommandations CNIL) :
 * seuls des compteurs par jour sont enregistrés. Un visiteur est reconnu pour la journée par une empreinte
 * (IP + navigateur + date + clé secrète) gardée en cache jusqu'au lendemain, jamais en base.
 * Le comptage a lieu après l'envoi de la page : il ne la ralentit pas et ne peut pas la faire échouer.
 */
final class CompteurTrafic
{
    /** Pages comptées : route → libellé affiché dans les statistiques. */
    public const PAGES = [
        'vitrine_accueil' => 'Accueil',
        'vitrine_suivi' => 'Suivi de colis',
        'vitrine_suivi_colis' => 'Fiche de suivi d\'un colis',
        'vitrine_ramassage' => 'Demande de ramassage',
        'vitrine_ramassage_merci' => 'Demande envoyée',
        'vitrine_mentions' => 'Mentions légales',
        'vitrine_confidentialite' => 'Confidentialité',
        'vitrine_conditions' => 'Conditions d\'utilisation',
    ];

    public const ACTIONS = [
        'demande_ramassage' => 'Demandes de ramassage',
        'colis_suivi' => 'Colis retrouvés par le suivi',
    ];

    private const ROBOTS = '/bot|crawl|spider|slurp|scan|preview|facebookexternalhit|whatsapp|telegram|curl|wget|python|java\/|go-http|headless|lighthouse|monitor|uptime|httpclient|axios|feed/i';

    /** Domaine du site d'origine (fragment) → source affichée. */
    private const SOURCES = [
        'google.' => 'Google', 'bing.' => 'Bing', 'yahoo.' => 'Yahoo', 'duckduckgo.' => 'DuckDuckGo', 'qwant.' => 'Qwant', 'ecosia.' => 'Ecosia',
        'facebook.' => 'Facebook', 'fb.' => 'Facebook', 'instagram.' => 'Instagram', 'tiktok.' => 'TikTok',
        'whatsapp.' => 'WhatsApp', 'wa.me' => 'WhatsApp', 'youtube.' => 'YouTube', 'snapchat.' => 'Snapchat',
        't.co' => 'X (Twitter)', 'twitter.' => 'X (Twitter)', 'x.com' => 'X (Twitter)', 'linkedin.' => 'LinkedIn',
    ];

    public function __construct(
        private readonly Connection $db,
        #[Autowire(service: 'cache.visiteurs_vitrine')] private readonly CacheItemPoolInterface $cache,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsEventListener]
    public function onTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route');
        if (!isset(self::PAGES[$route]) || !$request->isMethod('GET') || 200 !== $event->getResponse()->getStatusCode() || self::estRobot($request)) {
            return;
        }

        try {
            $this->incrementer(TraficVitrine::PAGE, $route);
            if ($this->nouveauVisiteur($request)) {
                $this->incrementer(TraficVitrine::VISITEURS, 'total');
                $this->incrementer(TraficVitrine::SOURCE, $this->source($request));
                $this->incrementer(TraficVitrine::APPAREIL, self::appareil((string) $request->headers->get('User-Agent')));
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Statistiques du site non enregistrées : '.$e->getMessage());
        }
    }

    /** Action réussie d'un visiteur (demande de ramassage envoyée, colis retrouvé). */
    public function action(string $action): void
    {
        try {
            $this->incrementer(TraficVitrine::ACTION, $action);
        } catch (\Throwable $e) {
            $this->logger->warning('Statistiques du site non enregistrées : '.$e->getMessage());
        }
    }

    public static function estRobot(Request $request): bool
    {
        $ua = (string) $request->headers->get('User-Agent');

        // Préchargement par le navigateur : la page n'est pas forcément vue.
        return '' === $ua || preg_match(self::ROBOTS, $ua) || str_contains((string) $request->headers->get('Sec-Purpose', $request->headers->get('Purpose', '')), 'prefetch');
    }

    public static function appareil(string $ua): string
    {
        return match (true) {
            (bool) preg_match('/iPad|Tablet|Android(?!.*Mobile)/i', $ua) => 'Tablette',
            (bool) preg_match('/Mobi|iPhone|Android/i', $ua) => 'Mobile',
            default => 'Ordinateur',
        };
    }

    private function source(Request $request): string
    {
        $hote = strtolower((string) parse_url((string) $request->headers->get('Referer'), \PHP_URL_HOST));
        if ('' === $hote) {
            return 'Accès direct';
        }
        if ($hote === strtolower($request->getHost()) || str_ends_with($hote, '.'.preg_replace('/^www\./', '', strtolower($request->getHost())))) {
            return 'Accès direct'; // navigation interne : la vraie provenance est inconnue
        }
        foreach (self::SOURCES as $fragment => $nom) {
            if (str_contains($hote, $fragment)) {
                return $nom;
            }
        }

        return 'Autres sites';
    }

    private function nouveauVisiteur(Request $request): bool
    {
        $jour = date('Y-m-d');
        $empreinte = hash_hmac('sha256', $jour.'|'.$request->getClientIp().'|'.$request->headers->get('User-Agent'), $this->secret);
        $element = $this->cache->getItem('visiteur_vitrine_'.substr($empreinte, 0, 40));
        if ($element->isHit()) {
            return false;
        }
        $this->cache->save($element->set(1)->expiresAt(new \DateTimeImmutable('tomorrow')));

        return true;
    }

    private function incrementer(string $type, string $cle): void
    {
        $jour = date('Y-m-d');
        $cle = mb_substr($cle, 0, 60);
        $maj = fn () => $this->db->executeStatement('UPDATE trafic_vitrine SET nombre = nombre + 1 WHERE jour = ? AND type = ? AND cle = ?', [$jour, $type, $cle]);
        if ($maj() > 0) {
            return;
        }
        try {
            $this->db->insert('trafic_vitrine', ['jour' => $jour, 'type' => $type, 'cle' => $cle, 'nombre' => 1]);
        } catch (UniqueConstraintViolationException) {
            $maj(); // une autre visite a créé la ligne au même instant
        }
    }
}
