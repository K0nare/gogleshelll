<?php
declare(strict_types=1);

namespace Stratbook;

/**
 * Конфигурация консолидатора: входные файлы, порядок и цвета листов,
 * пороги дедупликации. Заменяет одноимённые глобальные переменные v7.
 */
final class Config
{
    /** @var string[] Список входных XLSX-файлов (задаётся при запуске) */
    public array $files;

    public string $outputFile;

    /** @var string[] Фиксированный порядок листов карт в итоговом файле */
    public array $sheetOrder;

    /** @var array<string,string> Цвета ярлычков листов (ARGB HEX) */
    public array $tabColors;

    /** Порог похожести строк по коэффициенту Жаккара */
    public float $jaccardHard;

    /** Максимальное расстояние Левенштейна для склейки названий */
    public int $levMaxDist;

    /** @param string[] $files @param string[] $sheetOrder @param array<string,string> $tabColors */
    public function __construct(
        array $files,
        string $outputFile = 'STRATBOOK_FINAL.xlsx',
        array $sheetOrder = self::DEFAULT_SHEET_ORDER,
        array $tabColors = self::DEFAULT_TAB_COLORS,
        float $jaccardHard = 0.85,
        int $levMaxDist = 2
    ) {
        $this->files        = $files;
        $this->outputFile   = $outputFile;
        $this->sheetOrder   = $sheetOrder;
        $this->tabColors    = $tabColors;
        $this->jaccardHard  = $jaccardHard;
        $this->levMaxDist   = $levMaxDist;
    }

    public const DEFAULT_SHEET_ORDER = [
        'NUKE', 'TRAIN', 'ANCIENT', 'MIRAGE', 'ANUBIS',
        'DUST 2', 'INFERNO', 'VERTIGO', 'OVERPASS',
        'NADS ANUBIS', 'NADS ANCIENT', 'NADS TRAIN', 'NADS Mirage', 'NADS DUST 2',
    ];

    public const DEFAULT_TAB_COLORS = [
        'NUKE'         => 'FF10B981',
        'TRAIN'        => 'FF4A6CF7',
        'ANCIENT'      => 'FFF59E0B',
        'MIRAGE'       => 'FF8B5CF6',
        'ANUBIS'       => 'FF06B6D4',
        'DUST 2'       => 'FFEF4444',
        'INFERNO'      => 'FFF97316',
        'VERTIGO'      => 'FFEC4899',
        'OVERPASS'     => 'FF14B8A6',
        'NADS ANUBIS'  => 'FF64748B',
        'NADS ANCIENT' => 'FF64748B',
        'NADS TRAIN'   => 'FF64748B',
        'NADS Mirage'  => 'FF64748B',
        'NADS DUST 2'  => 'FF64748B',
    ];
}
