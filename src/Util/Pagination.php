<?php

namespace App\Util;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;

/**
 * @template T
 * @implements \IteratorAggregate<int, T>
 */
final class Pagination implements \IteratorAggregate, \Countable
{
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
