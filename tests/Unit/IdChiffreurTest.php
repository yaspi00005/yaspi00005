<?php

namespace App\Tests\Unit;

use App\Security\IdChiffreur;
use PHPUnit\Framework\TestCase;

final class IdChiffreurTest extends TestCase
{
    public function testAllerRetour(): void
    {
        $c = new IdChiffreur('secret-de-test-0123456789');
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
        $c = new IdChiffreur('secret-de-test-0123456789');
        $code = $c->encode(42);
        foreach (['42', '', 'abc', str_repeat('A', 22), substr($code, 0, -1).('A' === substr($code, -1) ? 'B' : 'A')] as $invalide) {
            $this->assertNull($c->decode($invalide), "« $invalide » doit être rejeté");
        }
    }

    public function testUneAutreCleNeDechiffrePas(): void
    {
        $code = (new IdChiffreur('premiere-cle-0123456789'))->encode(42);
        $this->assertNull((new IdChiffreur('seconde-cle-0123456789'))->decode($code));
    }

    public function testCleTropCourteRefusee(): void
    {
        $this->expectException(\RuntimeException::class);
        new IdChiffreur('court');
    }
}
