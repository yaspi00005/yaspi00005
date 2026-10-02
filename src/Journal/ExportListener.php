<?php

namespace App\Journal;

use App\Entity\Journal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Journalise les exports (Excel, PDF) : c'est par là que des données peuvent sortir en masse. */
final class ExportListener
{
    private const EXPORTS = [
        'colis_index' => 'Export Excel des colis',
        'client_index' => 'Export Excel des clients',
        'appel_index' => 'Export Excel du registre des appels',
        'paiement_index' => 'Export Excel des paiements',
        'expedition_show' => "Export des colis d'une expédition (manifeste)",
        'ramassage_export' => 'Export PDF du planning des ramassages',
    ];

    public function __construct(
        private readonly Journaliseur $journal,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route');
        if (!$event->isMainRequest() || !isset(self::EXPORTS[$route]) || !$event->getResponse()->isSuccessful()) {
            return;
        }
        if ('ramassage_export' !== $route && !\in_array($request->query->get('export'), ['csv', 'pdf'], true)) {
            return;
        }
        $filtres = array_filter($request->query->all(), fn ($v, $k) => 'export' !== $k && '' !== $v && \is_scalar($v), \ARRAY_FILTER_USE_BOTH);
        $this->journal->ecrire(
            $this->em, Journal::EXPORT, self::EXPORTS[$route].($filtres ? '' : ' (tout)'), 'Export', null,
            $filtres ? array_map(fn ($v) => [null, (string) $v], $filtres) : null,
        );
    }
}
