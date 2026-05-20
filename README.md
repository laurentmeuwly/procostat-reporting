# procostat_reporting

Laravel package — document reporting engine for Procorad.

Generates **XLSX**, **DOCX**, **PPTX** and **PDF** from a single `ProcostatReportData` DTO.

---

## Architecture

```
ProcostatResult (procostat package)
        │
        ▼  ReportDataFactory::fromProcostatResult()
ProcostatReportData  ◄──── stable DTO, decoupled from statistics engine
        │
        ▼  ReportManager::generateAll()
  ┌─────┴──────────────────────────────────┐
  │          │            │                │
  ▼          ▼            ▼                ▼
xlsx       docx         pptx             pdf
(PHP)    (Node.js)   (Node.js)     (Word → LibreOffice)
```

**Why Node.js for DOCX/PPTX?**  
`pptxgenjs` and `docx` produce fully-editable native charts and rich layouts that
end-users can rework in PowerPoint / Word. PHP libraries cannot match this fidelity.

**Why PHP for XLSX?**  
PhpSpreadsheet gives us full control over conditional formatting, native Excel charts,
and formula cells — all requirements for the Procorad data sheet.

---

## Installation

### 1. Add to your monorepo `composer.json`

```json
{
    "repositories": [
        { "type": "path", "url": "packages/procostat_reporting" }
    ],
    "require": {
        "procorad/procostat-reporting": "*"
    }
}
```

### 2. Install PHP dependencies

```bash
composer install
```

### 3. Install Node dependencies

```bash
cd packages/procostat_reporting/node
npm install
```

### 4. Publish config (optional)

```bash
php artisan vendor:publish --tag=procostat-reporting-config
```

---

## Usage

### From the Procorad app

```php
use Procorad\ProcostatReporting\Factories\ReportDataFactory;
use Procorad\ProcostatReporting\Services\ReportManager;

// 1. Build the DTO from the immutable DataResult
$reportData = ReportDataFactory::fromProcostatResult(
    result:          $dataResult,          // ProcostatResult (immutable)
    intercomparison: 'CARBON 14',
    sample:          '25CB',
    isotope:         '14C',
    year:            2025,
    locale:          'fr',
);

// 2. Generate all formats
$manager = app(ReportManager::class);
$result  = $manager->generateAll($reportData, storage_path('reports/25CB_14C'));

// 3. Use the result
if ($result->isFullySuccessful()) {
    $xlsxPath = $result->getFile('xlsx');
    $pptxPath = $result->getFile('pptx');
}

// Partial failures are captured without throwing:
foreach ($result->errors as $format => $message) {
    Log::error("Report generation failed for {$format}: {$message}");
}
```

### Single format

```php
$manager->generate('xlsx', $reportData, '/path/to/report.xlsx');
```

---

## Configuration

Published to `config/procostat_reporting.php` or override via `.env`:

| Key | Default | Description |
|-----|---------|-------------|
| `PROCOSTAT_NODE_BINARY` | `node` | Path to node binary |
| `PROCOSTAT_NODE_TIMEOUT` | `120` | Node process timeout (s) |
| `PROCOSTAT_LIBREOFFICE_BINARY` | `libreoffice` | Path to LibreOffice |
| `PROCOSTAT_STOP_ON_FIRST_ERROR` | `false` | Fail-fast mode |

---

## Running tests

```bash
# Unit tests only (no Node required)
./vendor/bin/phpunit --testsuite Unit

# Full suite (requires node on PATH)
./vendor/bin/phpunit
```

---

## Roadmap

- [ ] Native Excel charts in XLSX (PhpSpreadsheet)
- [ ] Chart slides in PPTX (pptxgenjs addChart)  
- [ ] Chart pages in DOCX (one page per ChartConfig)
- [ ] PDF via wkhtmltopdf alternative
- [ ] Multilingual labels (fr / en)
- [ ] Artisan command `procostat:report {sample} {isotope}`
