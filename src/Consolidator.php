<?php
declare(strict_types=1);

namespace Stratbook;

use Throwable;

/**
 * Оркестратор консолидации (порт основной логики v7):
 * чтение файлов → пропуск структурных копий → классификация →
 * жёсткая дедупликация → сборка листов → запись XLSX.
 */
final class Consolidator
{
    /** @var resource */
    private $out;

    public function __construct(
        private readonly Config $config,
        private readonly XlsxReader $reader,
        private readonly Classifier $classifier,
        private readonly Deduper $deduper,
        private readonly XlsxWriter $writer,
        $out = null,
    ) {
        $this->out = $out ?? STDOUT;
    }

    /**
     * Запускает консолидацию и возвращает путь к итоговому файлу.
     */
    public function run(): string
    {
        $store = $this->readAll();

        $this->printf("=== ЖЁСТКАЯ ДЕДУПЛИКАЦИЯ (exact + Levenshtein + Jaccard %.2F) ===\n", $this->config->jaccardHard);
        $dedupStats = $this->deduper->dedupe($store);
        foreach ($dedupStats['per_sheet'] as $sheet => $n) {
            if ($n > 0) $this->printf("  %-15s : удалено %d\n", $sheet, $n);
        }
        $this->printf("  Всего удалено: %d\n\n", $dedupStats['removed']);

        $this->printf("=== СБОРКА СТРУКТУРЫ ===\n\n");
        $finalSheets = $this->buildSheets($store);

        $this->printf("\n=== ЗАПИСЬ ===\n");
        try {
            $this->writer->write($this->config->outputFile, $finalSheets);
        } catch (Throwable $e) {
            $this->printf("ОШИБКА: %s\n", $e->getMessage());
            exit(1);
        }

        $this->printf("\n✓ Готово: %s\n", $this->config->outputFile);
        $this->printf("  Листов: %d\n", count($finalSheets));
        return $this->config->outputFile;
    }

    /**
     * Читает все входные файлы в хранилище лист → записи.
     * Файлы, структурно идентичные уже прочитанным (тот же набор листов и
     * количеств строк), пропускаются целиком.
     *
     * @return array<string,array{tokens:array,items:array}>
     */
    public function readAll(): array
    {
        $this->printf("=== ЧТЕНИЕ ФАЙЛОВ ===\n\n");
        $store = [];
        $seenFileHash = [];

        foreach ($this->config->files as $file) {
            if (!file_exists($file)) {
                $this->printf("[SKIP] нет файла: %s\n\n", $file);
                continue;
            }
            $this->printf("Читаю: %s\n", $file);
            try {
                $data = $this->reader->read($file);
            } catch (Throwable $e) {
                $this->printf("  [ERR] %s\n\n", $e->getMessage());
                continue;
            }

            $sheetsInfo = [];
            foreach ($data as $sn => $rows) $sheetsInfo[] = $sn . ':' . count($rows);
            $fh = sha1(implode('|', $sheetsInfo));
            if (isset($seenFileHash[$fh])) {
                $this->printf("  [DUP FILE] структурно идентичен: %s\n\n", $seenFileHash[$fh]);
                continue;
            }
            $seenFileHash[$fh] = $file;

            foreach ($data as $sheetName => $rows) {
                if (!isset($store[$sheetName])) $store[$sheetName] = ['tokens' => [], 'items' => []];
                $added = 0;
                foreach ($rows as $row) {
                    if (TextUtil::isEmptyRow($row)) continue;
                    $cand = $this->classifier->classify($row);
                    if (($cand['section'] ?? '') === 'skip') continue;
                    $cand['sources'] = [$file];
                    $store[$sheetName]['items'][] = $cand;
                    $added++;
                }
                $this->printf("  -> %-15s : +%d строк\n", $sheetName, $added);
            }
            $this->printf("\n");
        }

        return $store;
    }

    /**
     * Собирает листы итогового файла: «СВОДКА» + карты в фиксированном порядке.
     *
     * @param array<string,array{tokens:array,items:array}> $store
     * @return array<string,array>
     */
    public function buildSheets(array $store): array
    {
        $date = date('d.m.Y H:i');
        $outputSheets = [];

        $outputSheets['СВОДКА'] = SheetBuilder::buildSummarySheet($store, $date);

        foreach ($store as $sheetName => $data) {
            $items = $data['items'];
            if (empty($items)) continue;

            $strategies = []; $spawns = []; $walls = []; $nades = [];
            foreach ($items as $it) {
                switch ($it['section'] ?? '') {
                    case 'strategy': $strategies[] = $it; break;
                    case 'spawn':    $spawns[]     = $it; break;
                    case 'wall':     $walls[]      = $it; break;
                    case 'nade':     $nades[]      = $it; break;
                }
            }

            $tab = $this->config->tabColors[$sheetName] ?? 'FF4A6CF7';
            $outputSheets[$sheetName] = SheetBuilder::buildMapSheet(
                $sheetName, $strategies, $spawns, $walls, $nades, $tab, $date
            );
            $this->printf(
                "  %-15s : стратегий %d, спавнов %d, стенок %d, гранат %d\n",
                $sheetName, count($strategies), count($spawns), count($walls), count($nades)
            );
        }

        // Фиксированный порядок карт из конфига, неизвестные листы — по алфавиту в конце
        $order = $this->config->sheetOrder;
        $finalSheets = ['СВОДКА' => $outputSheets['СВОДКА']];
        unset($outputSheets['СВОДКА']);
        uksort($outputSheets, static function (string $a, string $b) use ($order): int {
            $ia = array_search($a, $order, true);
            $ib = array_search($b, $order, true);
            if ($ia === false && $ib === false) return strcmp($a, $b);
            if ($ia === false) return 1;
            if ($ib === false) return -1;
            return $ia <=> $ib;
        });
        foreach ($outputSheets as $k => $v) $finalSheets[$k] = $v;

        return $finalSheets;
    }

    private function printf(string $format, mixed ...$args): void
    {
        fprintf($this->out, $format, ...$args);
    }
}
