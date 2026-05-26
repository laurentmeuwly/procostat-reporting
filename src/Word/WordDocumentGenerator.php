<?php

namespace Procorad\ProcostatReporting\Word;

use Procorad\ProcostatReporting\Contracts\DocumentGenerator;
use Procorad\ProcostatReporting\Data\IntercomparisonReportData;
use Procorad\ProcostatReporting\Data\SampleAnalysisData;
use Procorad\ProcostatReporting\Data\ReportResult;
use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\GraphDefinitionFactory;
use Procorad\ProcostatReporting\Node\NodeRenderer;
use Procorad\ProcostatReporting\Support\PackagePaths;
use Procorad\ProcostatReporting\Word\Ooxml\DocxChartInjector;

final class WordDocumentGenerator implements DocumentGenerator
{
    /**
     * Atomic number (Z) by element symbol — used to sort isotopes by ascending Z then A.
     * Covers the full periodic table (Z 1–118).
     */
    private const ATOMIC_NUMBERS = [
        'H'=>1,'He'=>2,'Li'=>3,'Be'=>4,'B'=>5,'C'=>6,'N'=>7,'O'=>8,'F'=>9,'Ne'=>10,
        'Na'=>11,'Mg'=>12,'Al'=>13,'Si'=>14,'P'=>15,'S'=>16,'Cl'=>17,'Ar'=>18,'K'=>19,'Ca'=>20,
        'Sc'=>21,'Ti'=>22,'V'=>23,'Cr'=>24,'Mn'=>25,'Fe'=>26,'Co'=>27,'Ni'=>28,'Cu'=>29,'Zn'=>30,
        'Ga'=>31,'Ge'=>32,'As'=>33,'Se'=>34,'Br'=>35,'Kr'=>36,'Rb'=>37,'Sr'=>38,'Y'=>39,'Zr'=>40,
        'Nb'=>41,'Mo'=>42,'Tc'=>43,'Ru'=>44,'Rh'=>45,'Pd'=>46,'Ag'=>47,'Cd'=>48,'In'=>49,'Sn'=>50,
        'Sb'=>51,'Te'=>52,'I'=>53,'Xe'=>54,'Cs'=>55,'Ba'=>56,'La'=>57,'Ce'=>58,'Pr'=>59,'Nd'=>60,
        'Pm'=>61,'Sm'=>62,'Eu'=>63,'Gd'=>64,'Tb'=>65,'Dy'=>66,'Ho'=>67,'Er'=>68,'Tm'=>69,'Yb'=>70,
        'Lu'=>71,'Hf'=>72,'Ta'=>73,'W'=>74,'Re'=>75,'Os'=>76,'Ir'=>77,'Pt'=>78,'Au'=>79,'Hg'=>80,
        'Tl'=>81,'Pb'=>82,'Bi'=>83,'Po'=>84,'At'=>85,'Rn'=>86,'Fr'=>87,'Ra'=>88,'Ac'=>89,'Th'=>90,
        'Pa'=>91,'U'=>92,'Np'=>93,'Pu'=>94,'Am'=>95,'Cm'=>96,'Bk'=>97,'Cf'=>98,'Es'=>99,'Fm'=>100,
        'Md'=>101,'No'=>102,'Lr'=>103,'Rf'=>104,'Db'=>105,'Sg'=>106,'Bh'=>107,'Hs'=>108,'Mt'=>109,'Ds'=>110,
        'Rg'=>111,'Cn'=>112,'Nh'=>113,'Fl'=>114,'Mc'=>115,'Lv'=>116,'Ts'=>117,'Og'=>118,
    ];

    public function __construct(
        private readonly NodeRenderer           $nodeRenderer,
        private readonly DocxChartInjector      $chartInjector  = new DocxChartInjector(),
        private readonly GraphDefinitionFactory $graphFactory   = new GraphDefinitionFactory(),
    ) {}

    public function format(): string { return 'docx'; }

    public function generate(IntercomparisonReportData $data, string $outputPath): ReportResult
    {
        $start = hrtime(true);

        // Step 1 — Node.js renders cover + one data/stats page per analysis,
        //          sorted by ascending atomic number (Z) then mass number (A).
        //          The sorted order is embedded in the payload so render-docx.js
        //          outputs the pages in the correct sequence.
        $this->nodeRenderer->render(
            script:     PackagePaths::nodeRenderer('render-docx.js'),
            payload:    $this->buildPayload($data),
            outputPath: $outputPath,
            format:     $this->format(),
        );

        // Step 2 — PHP appends chart pages immediately after each analysis's data page,
        //          in the same sorted order: [tableau isotope N] then [graphs isotope N].
        $sorted = $this->sortAnalysesByAtomicNumber($data->analyses);

        foreach ($sorted as $index => $analysis) {
            $graphs   = $this->graphFactory->fromAnalysis($analysis);
            $xlsxPath = $this->resolveXlsxPath($outputPath, $data, $analysis->sampleCode, $analysis->isotope);
            $this->chartInjector->inject($graphs, $outputPath, $xlsxPath, $index);
        }

        return new ReportResult(
            files: [$this->format() => $outputPath],
            errors: [],
            durationMs: (hrtime(true) - $start) / 1_000_000,
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Sort analyses by (Z, A): atomic number ascending, then mass number ascending.
     *
     * Isotope strings like "14C", "228Th", "230Th" are parsed by splitting the
     * leading digit run (mass number A) from the trailing letter run (element symbol).
     * Unknown symbols sort to Z=999 (end of list).
     *
     * @param  SampleAnalysisData[] $analyses
     * @return SampleAnalysisData[]
     */
    private function sortAnalysesByAtomicNumber(array $analyses): array
    {
        $sorted = $analyses;
        usort($sorted, function (SampleAnalysisData $a, SampleAnalysisData $b): int {
            [$zA, $aA] = $this->parseIsotope($a->isotope);
            [$zB, $aB] = $this->parseIsotope($b->isotope);
            return $zA !== $zB ? $zA <=> $zB : $aA <=> $aB;
        });
        return $sorted;
    }

    /**
     * Parse an isotope string like "228Th" into [Z, A].
     * Returns [999, 0] for unrecognised symbols.
     *
     * @return array{0: int, 1: int}  [atomicNumber, massNumber]
     */
    private function parseIsotope(string $isotope): array
    {
        if (!preg_match('/^(\d*)([A-Za-z]+)/', $isotope, $m)) {
            return [999, 0];
        }
        $massNumber = (int) $m[1];
        $symbol     = ucfirst(strtolower($m[2]));
        $z          = self::ATOMIC_NUMBERS[$symbol] ?? 999;
        return [$z, $massNumber];
    }

    /** @return array<string,mixed> */
    private function buildPayload(IntercomparisonReportData $data): array
    {
        return array_merge($data->toArray(), [
            'logoPath'   => PackagePaths::asset('logo.png'),
            'locale'     => $data->metadata['locale'] ?? 'fr',
            'generatedAt'=> (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }

    private function resolveXlsxPath(
        string $docxPath,
        IntercomparisonReportData $data,
        string $sampleCode,
        string $isotope,
    ): string {
        $dir = dirname($docxPath);
        return $dir . '/' . sprintf('%d_%s_%s_%s.xlsx', $data->year, $data->icCode, $sampleCode, $isotope);
    }
}
