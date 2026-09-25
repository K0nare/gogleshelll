<?php
/**
 * STRATBOOK CONSOLIDATOR v8 (рефакторинг v7) — точка входа.
 *
 * Автономный скрипт консолидации и дедупликации Stratbook-файлов CS2
 * в единый STRATBOOK_FINAL.xlsx. Без внешних зависимостей.
 *
 * Использование: php consolidate.php
 */

set_time_limit(0);
mb_internal_encoding('UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', '1');

// ====================== АВТОЗАГРУЗКА ======================
spl_autoload_register(static function (string $class): void {
    $prefix = 'Stratbook\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $file = __DIR__ . '/src/' . substr($class, strlen($prefix)) . '.php';
    if (is_file($file)) require $file;
});

use Stratbook\Classifier;
use Stratbook\Config;
use Stratbook\Consolidator;
use Stratbook\Deduper;
use Stratbook\SheetBuilder;
use Stratbook\XlsxReader;
use Stratbook\XlsxWriter;

// ====================== НАСТРОЙКИ ======================
$config = new Config(
    files: [
        "22_06_2025Копия STRATBOOK EXCLUS1VE.xlsx",
        "Копия STRATBOOK EXCLUS1VE111.xlsx",
        "Копия STRATBOOK EXCLUS1VE 28 06 225.xlsx",
        "Копия STRATBOOK EXCLUS1VE19 07 2025.xlsx",
        "Копия STRATBOOK EXCLUS1VE100MAY.xlsx",
        "_STRATBOOK  konare.xlsx",
        "Копия STRATBOOK EXCLUS1VE 2__.xlsx",
        "Копия STRATBOOK EXCLUS1VE 3.xlsx",
        "Копия STRATBOOK EXCLUS1VE.xlsx",
        "Копия STRATBOOK EXCLUS1VE (1).xlsx",
    ],
    outputFile: "STRATBOOK_FINAL.xlsx",
    // $sheetOrder и $tabColors — по умолчанию из Config::*
    jaccardHard: 0.85,
    levMaxDist: 2,
);

// ====================== ЗАПУСК ======================
(new Consolidator(
    config:     $config,
    reader:     new XlsxReader(),
    classifier: new Classifier(),
    deduper:    new Deduper($config->levMaxDist, $config->jaccardHard),
    writer:     new XlsxWriter(),
))->run();
