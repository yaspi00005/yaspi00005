<?php

namespace App\Controller\Admin;

use App\Entity\Journal;
use App\Entity\User;
use App\Repository\JournalRepository;
use App\Repository\UserRepository;
use App\Util\Pagination;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Journal d'activité : qui a ajouté, modifié, supprimé quoi, et quand. */
#[Route('/admin/journal')]
#[IsGranted(User::ROLE_ADMIN)]
class JournalController extends AbstractController
{
    #[Route('', name: 'admin_journal_index', methods: ['GET'])]
    public function index(Request $request, JournalRepository $journal, UserRepository $utilisateurs): Response
    {
        $q = $request->query;
        $date = fn (string $cle) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $q->getString($cle)) ? new \DateTimeImmutable($q->getString($cle)) : null;
        $filtres = [
            'utilisateur' => $q->getInt('utilisateur') ?: null,
            'action' => \array_key_exists($q->getString('action'), Journal::ACTIONS) ? $q->getString('action') : null,
            'entite' => $q->getString('entite') ?: null,
            'q' => trim($q->getString('q')) ?: null,
            'du' => $date('du'),
            'au' => $date('au'),
        ];

        return $this->render('admin/journal/index.html.twig', [
            'entrees' => Pagination::depuisRequete($journal->rechercheQb($filtres), $request, 50),
            'filtres' => $filtres,
            'utilisateurs' => $utilisateurs->findBy([], ['nom' => 'ASC', 'prenom' => 'ASC']),
            'entites' => $journal->entites(),
            'actions' => Journal::ACTIONS,
        ]);
    }
}
