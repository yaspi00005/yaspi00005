<?php

namespace App\Util;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\HttpFoundation\Request;

/**
 * @template T
 * @implements \IteratorAggregate<int, T>
 */
final class Pagination implements \IteratorAggregate, \Countable
{
    /** Nombres de lignes par page proposés (paramètre « par »). */
    public const CHOIX = [10, 25, 50, 100, 500];

    /** @var Paginator<T> */
    private Paginator $paginator;

    public function __construct(QueryBuilder $qb, public readonly int $page = 1, public readonly int $parPage = 25)
    {
        $qb->setFirstResult(($this->page - 1) * $this->parPage)->setMaxResults($this->parPage);
        $this->paginator = new Paginator($qb, true);
    }

    public static function depuis(QueryBuilder $qb, mixed $page, int $parPage = 25): self
    {
        return new self($qb, max(1, (int) $page), $parPage);
    }

    /**
     * Page et nombre de lignes lus dans la requête (?page=2&par=50). Le nombre choisi est retenu pour cette liste
     * pendant la session : il reste appliqué après un filtre ou en revenant sur la page.
     */
    public static function depuisRequete(QueryBuilder $qb, Request $request, int $defaut = 25): self
    {
        $cle = 'lignes_par_page.'.$request->attributes->getString('_route');
        $session = $request->hasSession() ? $request->getSession() : null;
        $par = $request->query->getInt('par');
        if (\in_array($par, self::CHOIX, true)) {
            $session?->set($cle, $par);
        } else {
            $memo = $session?->get($cle);
            $par = \in_array($memo, self::CHOIX, true) ? $memo : $defaut;
        }

        return self::depuis($qb, $request->query->get('page', 1), $par);
    }

    public function getIterator(): \Traversable
    {
        return $this->paginator->getIterator();
    }

    public function count(): int
    {
        return \count($this->paginator);
    }

    public function total(): int
    {
        return \count($this->paginator);
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total() / $this->parPage));
    }

    public function aPrecedente(): bool
    {
        return $this->page > 1;
    }

    public function aSuivante(): bool
    {
        return $this->page < $this->pages();
    }

    public function premier(): int
    {
        return $this->total() ? ($this->page - 1) * $this->parPage + 1 : 0;
    }

    public function dernier(): int
    {
        return min($this->total(), $this->page * $this->parPage);
    }
}
