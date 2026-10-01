<?php

namespace App\Tests\Service;

use App\Service\ExportExcel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Exports Excel aux couleurs de SOTRAMA, avec logo et date et heure d'export. */
final class ExportExcelTest extends KernelTestCase
{
    public function testMiseEnFormeEtValeurs(): void
    {
        $excel = static::getContainer()->get(ExportExcel::class);
        $classeur = $excel->classeur('Liste des colis', ['N° suivi', 'Date', 'Tél. destinataire', 'Poids (kg)', 'Tarif', 'Client'], [
            ['ST2610-0001', '01/10/2026 14:30', '76123456', '12,50', '150,00', '=HYPERLINK("http://x")'],
            ['ST2610-0002', '02/10/2026 09:00', '0612345678', '3', '45,90', 'Awa Diallo'],
        ], 'Du 01/10/2026 au 31/10/2026', 'Awa Diallo', new \DateTimeImmutable('2026-10-01 16:05'));
        $f = $classeur->getActiveSheet();

        $this->assertCount(1, $f->getDrawingCollection(), 'Logo');
        $this->assertStringContainsString('Liste des colis', $f->getCell('B1')->getValue());
        $this->assertSame('Du 01/10/2026 au 31/10/2026', $f->getCell('B2')->getValue());
        $this->assertSame('Exporté le 01/10/2026 à 16:05 par Awa Diallo', $f->getCell('B3')->getValue());
        $this->assertSame('0B7A3B', $f->getStyle('A6')->getFill()->getStartColor()->getRGB(), 'En-têtes verts');
        $this->assertSame('N° suivi', $f->getCell('A6')->getValue());
        $this->assertNotNull($f->getAutoFilter()->getRange());

        // Montants et poids : vrais nombres ; dates : vraies dates ; téléphones : texte.
        $this->assertSame(150.0, $f->getCell('E7')->getValue());
        $this->assertStringContainsString('€', $f->getStyle('E7')->getNumberFormat()->getFormatCode());
        $this->assertSame(12.5, $f->getCell('D7')->getValue());
        $this->assertSame('dd/mm/yyyy hh:mm', $f->getStyle('B7')->getNumberFormat()->getFormatCode());
        $this->assertSame('76123456', $f->getCell('C7')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $f->getCell('C7')->getDataType());
        // Saisie piégée : reste du texte, jamais une formule.
        $this->assertSame(DataType::TYPE_STRING, $f->getCell('F7')->getDataType());
        $this->assertStringContainsString('16:05', $f->getHeaderFooter()->getOddFooter());
    }
}
