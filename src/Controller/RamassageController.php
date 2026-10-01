<?php

namespace App\Controller;

use App\Entity\Ramassage;
use App\Entity\User;
use App\Enum\StatutRamassage;
use App\Form\RamassageType;
use App\Repository\AppelRepository;
use App\Repository\CamionRepository;
use App\Repository\ClientRepository;
use App\Repository\RamassageRepository;
use App\Security\IdChiffreur;
use App\Service\DocumentService;
use App\Service\RegistreAppels;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Planning des ramassages chez les clients. */
#[Route('/ramassages')]
#[IsGranted(User::ROLE_RAMASSAGE)]
class RamassageController extends AbstractController
{
    /** Identifiant chiffré (voir App\\Security\\IdChiffreur). */
    private const ID = '[A-Za-z0-9_-]{22}';

    #[Route('', name: 'ramassage_index', methods: ['GET'])]
    public function index(RamassageRepository $ramassages, AppelRepository $appels, CamionRepository $camions): Response
    {
        return $this->render('ramassage/index.html.twig', [
            'demandes' => $ramassages->demandesEnLigne(),
            // Appels confirmés du registre sans date de ramassage.
            'aPlanifier' => $appels->aPlanifier(),
            'camions' => $camions->actifs(),
            'aVenir' => $ramassages->aVenir(10),
            'statuts' => StatutRamassage::cases(),
        ]);
    }

    #[Route('/export.pdf', name: 'ramassage_export', methods: ['GET'])]
    public function export(Request $request, RamassageRepository $ramassages, DocumentService $documents, CamionRepository $camions, IdChiffreur $chiffreur): Response
    {
        $statut = StatutRamassage::tryFrom($request->query->getString('statut'));
        $camion = ($id = $chiffreur->decodeOptionnel($request->query->get('camion'))) ? $camions->find($id) : null;
        $du = $this->date($request->query->getString('du'));
        $au = $this->date($request->query->getString('au'));
        if ($du && $au && $au < $du) {
            [$du, $au] = [$au, $du];
        }
        $liste = $ramassages->pourExport($statut, $du, $au, $camion);

        $parStatut = [];
        foreach (StatutRamassage::cases() as $s) {
            $parStatut[$s->label()] = \count(array_filter($liste, fn (Ramassage $r) => $r->getStatut() === $s));
        }

        $pdf = $documents->pdf('pdf/ramassages.html.twig', [
            'ramassages' => $liste,
            'statut' => $statut,
            'du' => $du,
            'au' => $au,
            'parStatut' => $parStatut,
            'camion' => $camion,
            'genereLe' => new \DateTimeImmutable(),
        ], 'landscape');

        $nom = 'ramassages'.($camion ? '-'.strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $camion->getNom())) : '').($statut ? '-'.$statut->value : '').($du ? '-du-'.$du->format('Y-m-d') : '').($au ? '-au-'.$au->format('Y-m-d') : '').'.pdf';

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$nom.'"',
        ]);
    }

    #[Route('/evenements.json', name: 'ramassage_events', methods: ['GET'])]
    public function events(Request $request, RamassageRepository $ramassages, IdChiffreur $chiffreur): JsonResponse
    {
        $camion = $chiffreur->decodeOptionnel($request->query->get('camion'));
        try {
            $debut = new \DateTimeImmutable($request->query->getString('start', 'first day of this month'));
            $fin = new \DateTimeImmutable($request->query->getString('end', 'last day of next month'));
        } catch (\Exception) {
            return $this->json(['error' => 'Dates invalides'], 400);
        }

        return $this->json(array_map(fn (Ramassage $r) => [
            'title' => ($r->getCamion() ? '🚚 '.$r->getCamion()->getNom().' · ' : '').$r->getClient()->getNomComplet(),
            // Heures « locales » sans fuseau : affichées telles que saisies, quel que soit le pays de l'agent.
            'start' => $r->getDatePrevue()->format('Y-m-d\TH:i:s'),
            'end' => $r->getDatePrevue()->modify('+1 hour')->format('Y-m-d\TH:i:s'),
            'url' => $this->generateUrl('ramassage_edit', ['id' => $r->getId()]),
            'className' => 'bg-'.$r->getStatut()->badge(),
            // Bordure à la couleur du camion : on voit d'un coup d'œil la tournée de chacun.
            'borderColor' => $r->getCamion()?->getCouleur(),
            'extendedProps' => ['deplacer' => $this->generateUrl('ramassage_deplacer', ['id' => $r->getId()]), 'statut' => $r->getStatut()->label(), 'telephone' => $r->getClient()->getTelephone()],
        ], array_values(array_filter($ramassages->entre($debut, $fin), fn (Ramassage $r) => !$camion || $r->getCamion()?->getId() === $camion))));
    }

    #[Route('/nouveau', name: 'ramassage_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, ClientRepository $clients, IdChiffreur $chiffreur): Response
    {
        $ramassage = (new Ramassage())->setAgent($this->getUser());
        if ($date = $request->query->getString('date')) {
            try {
                $ramassage->setDatePrevue(new \DateTimeImmutable($date.(\strlen($date) <= 10 ? ' 09:00' : '')));
            } catch (\Exception) {
            }
        }
        if ($clientId = $chiffreur->decodeOptionnel($request->query->get('client'))) {
            $ramassage->setClient($clients->find($clientId));
        }

        return $this->formulaire($request, $ramassage, $em);
    }

    #[Route('/{id}', name: 'ramassage_edit', requirements: ['id' => self::ID], methods: ['GET', 'POST'])]
    public function edit(Request $request, Ramassage $ramassage, EntityManagerInterface $em): Response
    {
        return $this->formulaire($request, $ramassage, $em);
    }

    #[Route('/{id}/deplacer', name: 'ramassage_deplacer', requirements: ['id' => self::ID], methods: ['POST'])]
    public function deplacer(Request $request, Ramassage $ramassage, EntityManagerInterface $em): JsonResponse
    {
        if (!$this->isCsrfTokenValid('ramassage', $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Jeton invalide'], 403);
        }
        try {
            // Le calendrier travaille en heure « flottante » : on ignore tout suffixe de fuseau.
            $debut = substr($request->getPayload()->getString('start'), 0, 19);
            $date = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $debut) ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', substr($debut, 0, 16));
            if (!$date) {
                throw new \InvalidArgumentException();
            }
            $ramassage->setDatePrevue($date);
        } catch (\Exception) {
            return $this->json(['error' => 'Date invalide'], 400);
        }
        $em->flush();

        return $this->json(['ok' => true]);
    }

    #[Route('/{id}/confirmer', name: 'ramassage_confirmer', requirements: ['id' => self::ID], methods: ['POST'])]
    public function confirmer(Request $request, Ramassage $ramassage, EntityManagerInterface $em, RegistreAppels $registre): Response
    {
        if (!$this->isCsrfTokenValid('confirmer-ramassage-'.$ramassage->getId(), $request->getPayload()->getString('_token'))) {
            return $this->redirectToRoute('ramassage_edit', ['id' => $ramassage->getId()], Response::HTTP_SEE_OTHER);
        }
        if (StatutRamassage::Demande === $ramassage->getStatut()) {
            // Demande du site vitrine validée après appel au client : elle entre dans le planning et les rappels.
            $ramassage->setStatut(StatutRamassage::Confirme)->setAgent($ramassage->getAgent() ?? $this->getUser());
            $registre->ramassageConfirme($ramassage);
            $em->flush();
            $this->addFlash('success', 'Demande de '.$ramassage->getClient()->getNomComplet().' validée : le ramassage est planifié.');
        } elseif (StatutRamassage::EnAttente === $ramassage->getStatut()) {
            $ramassage->setStatut(StatutRamassage::Confirme);
            $registre->ramassageConfirme($ramassage);
            $em->flush();
            $this->addFlash('success', 'Ramassage confirmé par '.$ramassage->getClient()->getNomComplet().'.');
        }

        return $this->redirectToRoute('ramassage_edit', ['id' => $ramassage->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/supprimer', name: 'ramassage_delete', requirements: ['id' => self::ID], methods: ['POST'])]
    public function delete(Request $request, Ramassage $ramassage, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete-ramassage-'.$ramassage->getId(), $request->getPayload()->getString('_token'))) {
            $em->remove($ramassage);
            $em->flush();
            $this->addFlash('success', 'Ramassage supprimé.');
        }

        return $this->redirectToRoute('ramassage_index', [], Response::HTTP_SEE_OTHER);
    }

    private function date(string $valeur): ?\DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur)) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('!Y-m-d', $valeur) ?: null;
    }

    private function formulaire(Request $request, Ramassage $ramassage, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(RamassageType::class, $ramassage);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Le registre des appels suit le camion choisi dans le planning.
            if ($appel = $em->getRepository(\App\Entity\Appel::class)->findOneBy(['ramassage' => $ramassage])) {
                $appel->setCamion($ramassage->getCamion());
            }
            $em->persist($ramassage);
            $em->flush();
            $this->addFlash('success', 'Ramassage enregistré.');

            return $this->redirectToRoute('ramassage_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('ramassage/form.html.twig', ['form' => $form, 'ramassage' => $ramassage]);
    }
}
