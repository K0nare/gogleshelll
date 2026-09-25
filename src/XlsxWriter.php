<?php
declare(strict_types=1);

namespace Stratbook;

use RuntimeException;
use ZipArchive;

/**
 * Писатель минимального, но валидного XLSX (inline strings, свои стили).
 * Порт writeXlsx()/buildSheetXml() из v7 — побайтово совместимый вывод.
 */
final class XlsxWriter
{
    /**
     * @param array<string,array{cols:array,rows:array,tab:string,freeze:int}> $sheets
     */
    public function write(string $file, array $sheets): void
    {
        @unlink($file);
        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Не создать $file");
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml($sheets));
        $zip->addFromString('_rels/.rels', $this->rootRelsXml());
        $zip->addFromString('xl/styles.xml', Styles::xml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml($sheets));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml($sheets));

        $i = 1;
        foreach ($sheets as $sheetInfo) {
            $zip->addFromString('xl/worksheets/sheet' . $i . '.xml', self::buildSheetXml($sheetInfo));
            $i++;
        }
        $zip->close();
    }

    private function contentTypesXml(array $sheets): string
    {
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $i = 1;
        foreach ($sheets as $_) {
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $i++;
        }
        return $ct . '</Types>';
    }

    private function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbookXml(array $sheets): string
    {
        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $i = 1;
        foreach (array_keys($sheets) as $name) {
            $safe = htmlspecialchars(substr($name, 0, 31), ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $wb .= '<sheet name="' . $safe . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
            $i++;
        }
        return $wb . '</sheets></workbook>';
    }

    private function workbookRelsXml(array $sheets): string
    {
        $wbr = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $i = 1;
        foreach (array_keys($sheets) as $_) {
            $wbr .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
            $i++;
        }
        $wbr .= '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        return $wbr . '</Relationships>';
    }

    /** @param array{cols:array,rows:array,tab?:string,freeze?:int} $sheet */
    public static function buildSheetXml(array $sheet): string
    {
        $cols     = $sheet['cols'];
        $rows     = $sheet['rows'];
        $tabColor = $sheet['tab'] ?? 'FF4A6CF7';
        $freeze   = $sheet['freeze'] ?? 5;
        $maxCols  = count($cols);

        $colXml = '<cols>';
        foreach ($cols as $ci => $w) {
            $colXml .= '<col min="' . ($ci + 1) . '" max="' . ($ci + 1) . '" width="' . round($w, 2) . '" customWidth="1"/>';
        }
        $colXml .= '</cols>';

        $merges = [];
        foreach ($rows as $ri => $row) {
            if (empty($row['merge'])) continue;
            $m = $row['merge'];
            if (strpos($m, ':') !== false) {
                [$from, $to] = explode(':', $m);
                $merges[] = $from . ($ri + 1) . ':' . $to . ($ri + 1);
            } else {
                $merges[] = $m . ($ri + 1);
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
             . '<sheetPr><tabColor rgb="' . $tabColor . '"/></sheetPr>'
             . '<sheetViews><sheetView workbookViewId="0">'
             .   '<pane ySplit="' . $freeze . '" topLeftCell="A' . ($freeze + 1) . '" activePane="bottomLeft" state="frozen"/>'
             . '</sheetView></sheetViews>'
             . $colXml
             . '<sheetData>';

        $r = 1;
        foreach ($rows as $row) {
            $h = $row['h'] ?? null;
            $rowAttr = ' r="' . $r . '"';
            if ($h) $rowAttr .= ' ht="' . $h . '" customHeight="1"';

            $rowXml = '<row' . $rowAttr . '>';
            $cells = $row['cells'] ?? [];
            for ($c = 0; $c < $maxCols; $c++) {
                $cell = $cells[$c] ?? null;
                if ($cell === null) continue;
                $st  = $cell['s'] ?? 0;
                $val = $cell['v'] ?? '';
                $ref = TextUtil::indexToCol($c + 1) . $r;
                if ($val === '' && $st === 0) continue;
                if ($val === '') {
                    $rowXml .= '<c r="' . $ref . '" s="' . $st . '"/>';
                } else {
                    $safe = htmlspecialchars((string)$val, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                    $rowXml .= '<c r="' . $ref . '" s="' . $st . '" t="inlineStr"><is><t xml:space="preserve">' . $safe . '</t></is></c>';
                }
            }
            $xml .= $rowXml . '</row>';
            $r++;
        }
        $xml .= '</sheetData>';
        if ($merges) {
            $xml .= '<mergeCells count="' . count($merges) . '">';
            foreach ($merges as $m) $xml .= '<mergeCell ref="' . $m . '"/>';
            $xml .= '</mergeCells>';
        }
        return $xml . '</worksheet>';
    }
}
