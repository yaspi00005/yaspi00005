<?php

namespace App\Tests\Unit;

use App\Enum\Destination;
use App\Util\Telephone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TelephoneTest extends TestCase
{
    public static function numeros(): iterable
    {
        yield 'France' => ['06 12 33 65 72', null, '33612336572'];
        yield 'France +33' => ['+33 6 12 33 65 72', null, '33612336572'];
        yield 'Mali local' => ['76 11 22 33', null, '22376112233'];
        yield 'Mali 00223' => ['00223 76 11 22 33', null, '22376112233'];
        yield 'Sénégal local' => ['77 123 45 67', null, '221771234567'];
        yield 'Destination par défaut' => ['0123456789012', Destination::Senegal, '221123456789012'];
        yield 'Trop court' => ['12 34', null, null];
        yield 'Vide' => ['—', null, null];
    }

    #[DataProvider('numeros')]
    public function testInternational(string $saisi, ?Destination $pays, ?string $attendu): void
    {
        $this->assertSame($attendu, Telephone::international($saisi, $pays));
    }
}
