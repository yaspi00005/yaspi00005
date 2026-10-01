<?php

namespace App\Service;

use App\Entity\User;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as DateExcel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports Excel (.xlsx) aux couleurs de SOTRAMA : logo, titre, période, date et heure d'export et auteur,
 * en-têtes verts avec filtres, montants et dates reconnus par Excel (tri, sommes), prêt à imprimer.
 */
final class ExportExcel
{
    private const VERT = '0B7A3B';
    private const JAUNE = 'FFD23F';
    private const ROUGE = 'D7263D';
    private const VERT_CLAIR = 'EEF7F1';
    private const LIGNE_ENTETES = 6;

    /** Colonnes de montants (en-tête contenant l'un de ces mots) : format monétaire. */
    private const MONTANTS = ['montant', 'remise', 'tarif', 'payé', 'reste', 'encaissé', 'charges', 'marge', "chiffre d'affaires", 'bonus', 'total'];

    /** Colonnes toujours en texte : téléphones (« 76 12 34 56 » au Mali), numéros, codes, références. */
    private const TEXTE = '/t[ée]l|n°|code|r[ée]f[ée]rence/iu';

    public function __construct(
        private readonly Security $security,
        #[Autowire('%app.nom%')] private readonly string $appNom,
        #[Autowire('%app.devise%')] private readonly string $devise,
        #[Autowire('%kernel.project_dir%/public/images/logo-sotrama.png')] private readonly string $cheminLogo,
    ) {
    }

    /**
     * @param list<string>          $entetes
     * @param iterable<list<mixed>> $lignes  valeurs telles que pour le CSV (montants « 12,50 », dates « 01/10/2026 14:30 »)
     */
    public function reponse(string $fichier, string $titre, array $entetes, iterable $lignes, ?string $sousTitre = null): StreamedResponse
    {
        $auteur = $this->security->getUser();
        $exporteLe = new \DateTimeImmutable();

        $reponse = new StreamedResponse(function () use ($titre, $entetes, $lignes, $sousTitre, $auteur, $exporteLe) {
            $classeur = $this->classeur($titre, $entetes, $lignes, $sousTitre, $auteur instanceof User ? $auteur->getNomComplet() : null, $exporteLe);
            (new Xlsx($classeur))->save('php://output');
            $classeur->disconnectWorksheets();
        });
        $reponse->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $reponse->headers->set('Content-Disposition', 'attachment; filename="'.$fichier.'"');
        $reponse->headers->set('Cache-Control', 'private, no-store');

        return $reponse;
    }

    /** « Du 01/09/2026 au 30/09/2026 » à partir de filtres au format Y-m-d (vides acceptés). */
    public static function periode(?string $du, ?string $au): ?string
    {
        $date = fn (?string $d) => $d && ($x = \DateTimeImmutable::createFromFormat('!Y-m-d', $d)) ? $x->format('d/m/Y') : null;
        [$du, $au] = [$date($du), $date($au)];

        return match (true) {
            $du && $au => "Du $du au $au",
            (bool) $du => "Depuis le $du",
            (bool) $au => "Jusqu'au $au",
            default => null,
        };
    }

    /** @param list<string> $entetes */
    public function classeur(string $titre, array $entetes, iterable $lignes, ?string $sousTitre, ?string $auteur, \DateTimeImmutable $exporteLe): Spreadsheet
    {
        $classeur = new Spreadsheet();
        $classeur->getProperties()->setCreator($this->appNom)->setTitle($titre)->setCompany($this->appNom);
        $classeur->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);
        $feuille = $classeur->getActiveSheet();
        $feuille->setTitle(mb_substr(preg_replace('#[\\\\/?*\[\]:]#', ' ', $titre), 0, 31));
        $nbColonnes = max(\count($entetes), 2);
        $derniere = Coordinate::stringFromColumnIndex($nbColonnes);
        $mention = 'Exporté le '.$exporteLe->format('d/m/Y').' à '.$exporteLe->format('H:i').($auteur ? ' par '.$auteur : '');

        // En-tête : logo, nom, titre, période, date et heure d'export.
        if (is_file($this->cheminLogo)) {
            $logo = new Drawing();
            $logo->setPath($this->cheminLogo)->setHeight(64)->setCoordinates('A1')->setOffsetX(4)->setOffsetY(3)->setWorksheet($feuille);
        }
        $feuille->getRowDimension(1)->setRowHeight(24);
        $feuille->getRowDimension(2)->setRowHeight(18);
        $feuille->getRowDimension(3)->setRowHeight(16);
        $feuille->setCellValue('B1', $this->appNom.' — '.$titre);
        $feuille->getStyle('B1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB(self::VERT);
        $feuille->setCellValueExplicit('B2', $sousTitre ?? '', DataType::TYPE_STRING);
        $feuille->getStyle('B2')->getFont()->setSize(11)->getColor()->setRGB('34443B');
        $feuille->setCellValue('B3', $mention);
        $feuille->getStyle('B3')->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB('66766D');

        // Bandeau aux trois couleurs du logo.
        $feuille->getRowDimension(4)->setRowHeight(5);
        foreach ([self::VERT, self::JAUNE, self::ROUGE] as $i => $couleur) {
            $debut = intdiv($nbColonnes * $i, 3) + 1;
            $fin = max($debut, intdiv($nbColonnes * ($i + 1), 3));
            $feuille->getStyle(Coordinate::stringFromColumnIndex($debut).'4:'.Coordinate::stringFromColumnIndex($fin).'4')
                ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($couleur);
        }

        // En-têtes de colonnes.
        $l = self::LIGNE_ENTETES;
        foreach ($entetes as $i => $entete) {
            $feuille->setCellValueExplicit(Coordinate::stringFromColumnIndex($i + 1).$l, $entete, DataType::TYPE_STRING);
        }
        $plageEntetes = "A$l:$derniere$l";
        $feuille->getStyle($plageEntetes)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::VERT]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $feuille->getRowDimension($l)->setRowHeight(22);

        $estTexte = array_map(fn ($e) => (bool) preg_match(self::TEXTE, $e), $entetes);
        $estMontant = array_map(fn ($e) => (bool) array_filter(self::MONTANTS, fn ($m) => str_contains(mb_strtolower($e), $m) && !str_contains($e, '%')), $entetes);
        $formatMontant = '#,##0.00'.('' !== trim($this->devise) ? ' "'.str_replace('"', '', trim($this->devise)).'"' : '');

        $largeurs = array_map(fn ($e) => mb_strlen($e), $entetes);
        $ligne = $l;
        foreach ($lignes as $valeurs) {
            ++$ligne;
            foreach (array_values($valeurs) as $i => $valeur) {
                $cellule = Coordinate::stringFromColumnIndex($i + 1).$ligne;
                $largeurs[$i] = max($largeurs[$i] ?? 0, min(60, mb_strlen((string) $valeur)));
                if ($estTexte[$i] ?? false) {
                    null === $valeur || '' === $valeur || $feuille->setCellValueExplicit($cellule, (string) $valeur, DataType::TYPE_STRING);
                } else {
                    $this->ecrire($feuille, $cellule, $valeur, $estMontant[$i] ?? false, $formatMontant);
                }
            }
            if (0 === ($ligne - $l) % 2) {
                $feuille->getStyle("A$ligne:$derniere$ligne")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::VERT_CLAIR);
            }
        }
        $nbLignes = $ligne - $l;
        if ($nbLignes > 0) {
            $feuille->getStyle("A$l:$derniere$ligne")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D9E4DD');
            $feuille->setAutoFilter("A$l:$derniere$ligne");
        }
        $feuille->setCellValue('A'.($ligne + 2), $nbLignes.' ligne'.($nbLignes > 1 ? 's' : ''));
        $feuille->getStyle('A'.($ligne + 2))->getFont()->setItalic(true)->getColor()->setRGB('66766D');

        // Largeurs : la colonne A garde la place du logo.
        foreach ($largeurs as $i => $n) {
            $feuille->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setWidth(max(0 === $i ? 12 : 8, $n + 3));
        }
        $feuille->freezePane('A'.($l + 1));
        $feuille->setSelectedCell('A'.($l + 1));

        // Impression : paysage, sur la largeur d'une page, en-têtes répétés, pied avec date d'export.
        $feuille->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd($l, $l);
        $feuille->getPageMargins()->setTop(0.5)->setBottom(0.6)->setLeft(0.4)->setRight(0.4);
        $feuille->getHeaderFooter()->setOddFooter('&L&8'.$this->appNom.' — '.$titre.'&C&8'.$mention.'&R&8Page &P / &N');

        return $classeur;
    }

    private function ecrire(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $feuille, string $cellule, mixed $valeur, bool $montant, string $formatMontant): void
    {
        if (null === $valeur || '' === $valeur) {
            return;
        }
        if (\is_int($valeur) || \is_float($valeur)) {
            $feuille->setCellValueExplicit($cellule, $valeur, DataType::TYPE_NUMERIC);
            $montant && $feuille->getStyle($cellule)->getNumberFormat()->setFormatCode($formatMontant);

            return;
        }
        $texte = (string) $valeur;
        // Nombre écrit à la française (« 1250,50 », « 12 ») : vrai nombre pour Excel. Les téléphones (0…, +…) restent du texte.
        if (preg_match('/^-?(0|[1-9]\d{0,9})(,\d+)?$/', $texte)) {
            $feuille->setCellValueExplicit($cellule, (float) str_replace(',', '.', $texte), DataType::TYPE_NUMERIC);
            $feuille->getStyle($cellule)->getNumberFormat()->setFormatCode($montant ? $formatMontant : (str_contains($texte, ',') ? '0.00' : '0'));

            return;
        }
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})(?: (\d{2}):(\d{2}))?$#', $texte, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            $date = new \DateTimeImmutable(\sprintf('%s-%s-%s %s:%s', $m[3], $m[2], $m[1], $m[4] ?? '00', $m[5] ?? '00'));
            $feuille->setCellValueExplicit($cellule, DateExcel::PHPToExcel($date), DataType::TYPE_NUMERIC);
            $feuille->getStyle($cellule)->getNumberFormat()->setFormatCode(isset($m[4]) ? 'dd/mm/yyyy hh:mm' : 'dd/mm/yyyy');

            return;
        }
        // Toujours du texte : une saisie commençant par « = » n'est jamais exécutée comme formule.
        $feuille->setCellValueExplicit($cellule, $texte, DataType::TYPE_STRING);
    }
}
