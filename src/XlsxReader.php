<?php
declare(strict_types=1);

namespace Stratbook;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Читатель XLSX без внешних зависимостей (ZipArchive + SimpleXML).
 * Порт readXlsx() из v7.
 */
final class XlsxReader
{
    /**
     * @return array<string, array<int, array<int,string>>> лист → строки → ячейки
     */
    public function read(string $file): array
    {
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) throw new RuntimeException("Не открыть ZIP: $file");

        $shared = $this->readSharedStrings($zip);
        [$wb, $relMap] = $this->readWorkbook($zip);

        $out = [];
        $idx = 0;
        foreach ($wb->sheets->sheet as $sheet) {
            $idx++;
            $name = (string)$sheet['name'];
            $target = $this->resolveSheetTarget($sheet, $relMap, $idx);
            $sheetXml = $zip->getFromName($target) ?: $zip->getFromName("xl/worksheets/sheet{$idx}.xml");
            if ($sheetXml === false) continue;
            $sx = @simplexml_load_string($sheetXml);
            if ($sx === false) continue;

            $rows = [];
            foreach ($sx->sheetData->row as $row) {
                $rowData = $this->readRow($row, $shared);
                if (empty($rowData)) continue;
                $max  = max(array_keys($rowData));
                $full = [];
                for ($i = 1; $i <= $max; $i++) $full[] = $rowData[$i] ?? '';
                if (TextUtil::isEmptyRow($full)) continue;
                $rows[] = TextUtil::rtrimRow($full);
            }
            $out[$name] = $rows;
        }
        $zip->close();

        return $out;
    }

    /** @return string[] */
    private function readSharedStrings(ZipArchive $zip): array
    {
        $shared = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss === false) return $shared;
        $sx = @simplexml_load_string($ss);
        if ($sx === false) return $shared;
        foreach ($sx->si as $si) {
            if (isset($si->t)) {
                $shared[] = (string)$si->t;
            } else {
                $t = '';
                foreach ($si->r as $r) $t .= (string)$r->t;
                $shared[] = $t;
            }
        }
        return $shared;
    }

    /** @return array{SimpleXMLElement, array<string,string>} */
    private function readWorkbook(ZipArchive $zip): array
    {
        $wbXml = $zip->getFromName('xl/workbook.xml');
        if ($wbXml === false) throw new RuntimeException("Нет workbook.xml");
        $wb = @simplexml_load_string($wbXml);

        $relMap = [];
        $rx = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($rx !== false) {
            $r = @simplexml_load_string($rx);
            if ($r !== false) {
                foreach ($r->Relationship as $rel) {
                    $relMap[(string)$rel['Id']] = (string)$rel['Target'];
                }
            }
        }
        return [$wb, $relMap];
    }

    /** Путь к XML листа внутри архива через relationship-id. */
    private function resolveSheetTarget(SimpleXMLElement $sheet, array $relMap, int $idx): string
    {
        $a      = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $rid    = isset($a['id']) ? (string)$a['id'] : '';
        $target = $relMap[$rid] ?? "worksheets/sheet{$idx}.xml";
        $target = ltrim($target, '/');
        if (strpos($target, 'xl/') !== 0) $target = 'xl/' . $target;
        return str_replace(['xl/../', 'xl//'], ['xl/', 'xl/'], $target);
    }

    /** @return array<int,string> индекс колонки (1-based) → значение */
    private function readRow(SimpleXMLElement $row, array $shared): array
    {
        $rowData = [];
        foreach ($row->c as $cell) {
            if (!preg_match('/^([A-Z]+)(\d+)$/i', (string)$cell['r'], $m)) continue;
            $ci  = TextUtil::colToIndex($m[1]);
            $t   = (string)$cell['t'];
            if ($t === 's') {
                $val = $shared[(int)(string)$cell->v] ?? '';
            } elseif ($t === 'inlineStr') {
                $val = isset($cell->is->t) ? (string)$cell->is->t : '';
                if ($val === '' && isset($cell->is->r)) {
                    foreach ($cell->is->r as $r) $val .= (string)$r->t;
                }
            } else {
                $val = (string)$cell->v;
            }
            $rowData[$ci] = trim($val);
        }
        return $rowData;
    }
}
