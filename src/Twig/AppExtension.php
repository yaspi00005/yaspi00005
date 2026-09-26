<?php

namespace App\Twig;

use App\Security\IdChiffreur;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class AppExtension extends AbstractExtension
{
    public function __construct(
        #[Autowire('%app.devise%')] private readonly string $devise,
        private readonly IdChiffreur $chiffreur,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('hid', fn (?int $id) => null === $id ? '' : $this->chiffreur->encode($id))];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('money', $this->money(...)),
            new TwigFilter('poids', fn ($kg) => number_format((float) $kg, 2, ',', ' ').' kg'),
            new TwigFilter('tel', fn (?string $t) => $t ? trim(chunk_split(preg_replace('/\s+/', '', $t), 2, ' ')) : ''),
        ];
    }

    /** Formate un montant en centimes : 123450 → « 1 234,50 € ». */
    public function money(?int $centimes, bool $devise = true): string
    {
        $v = number_format(($centimes ?? 0) / 100, 2, ',', "\u{202F}");
        if (str_ends_with($v, ',00')) {
            $v = substr($v, 0, -3);
        }

        return $devise ? $v."\u{00A0}".$this->devise : $v;
    }
}
