<?php

namespace App\Tests\Unit;

use App\Security\IdChiffreur;
use PHPUnit\Framework\TestCase;

final class IdChiffreurTest extends TestCase
{
    public function testAllerRetour(): void
    {
        $c = new IdChiffreur('secret-de-test');
        foreach ([1, 2, 35, 999999, PHP_INT_MAX] as $id) {
            $code = $c->encode($id);
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{22}$/', $code);
            $this->assertStringNotContainsString((string) $id, $code === (string) $id ? 'x' : '');
            $this->assertSame($id, $c->decode($code));
        }
        $this->assertNotSame($c->encode(1), $c->encode(2));
    }

    public function testRejetDesValeursInvalidesOuFalsifiees(): void
    {
        $c = new IdChiffreur('secret-de-test');
        $code = $c->encode(42);
        foreach (['42', '', 'abc', str_repeat('A', 22), substr($code, 0, -1).('A' === substr($code, -1) ? 'B' : 'A')] as $invalide) {
            $this->assertNull($c->decode($invalide), "« $invalide » doit être rejeté");
        }
    }

    public function testUneAutreCleNeDechiffrePas(): void
    {
        $code = (new IdChiffreur('cle-1'))->encode(42);
        $this->assertNull((new IdChiffreur('cle-2'))->decode($code));
    }
}
