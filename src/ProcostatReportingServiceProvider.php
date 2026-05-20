<?php

namespace Procorad\ProcostatReporting;

use Illuminate\Support\ServiceProvider;
use Procorad\ProcostatReporting\Application\GenerateIntercomparisonReport;
use Procorad\ProcostatReporting\Application\GenerateLaboratoryReport;
use Procorad\ProcostatReporting\Contract\StorageInterface;
use Procorad\ProcostatReporting\Excel\ExcelDocumentGenerator;
use Procorad\ProcostatReporting\Infrastructure\LocalFileStorage;
use Procorad\ProcostatReporting\Node\NodeRenderer;
use Procorad\ProcostatReporting\PowerPoint\PowerPointDocumentGenerator;
use Procorad\ProcostatReporting\Renderer\IntercomparisonPdfRendererInterface;
use Procorad\ProcostatReporting\Renderer\PdfRendererInterface;
use Procorad\ProcostatReporting\Renderer\SnappyIntercomparisonReportRenderer;
use Procorad\ProcostatReporting\Renderer\SnappyLaboratoryReportRenderer;
use Procorad\ProcostatReporting\Services\ReportManager;
use Procorad\ProcostatReporting\Word\WordDocumentGenerator;

final class ProcostatReportingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/procostat-reporting.php', 'procostat-reporting');

        // ── Bindings existants (Snappy PDF) — inchangés ──────────────────────

        $this->app->bind(StorageInterface::class, fn () => new LocalFileStorage(
            baseDirectory: config('procostat-reporting.storage_path'),
        ));

        $this->app->bind(IntercomparisonPdfRendererInterface::class, fn ($app)
            => new SnappyIntercomparisonReportRenderer(
                $app->make('snappy.pdf'),
                $app->make(\Illuminate\View\Factory::class),
            )
        );

        $this->app->bind(GenerateIntercomparisonReport::class, fn ($app)
            => new GenerateIntercomparisonReport(
                renderer: $app->make(IntercomparisonPdfRendererInterface::class),
                storage:  $app->make(StorageInterface::class),
            )
        );

        $this->app->bind(PdfRendererInterface::class, fn ($app)
            => new SnappyLaboratoryReportRenderer(
                $app->make('snappy.pdf'),
                $app->make(\Illuminate\View\Factory::class),
            )
        );

        $this->app->bind(GenerateLaboratoryReport::class, fn ($app)
            => new GenerateLaboratoryReport(
                renderer: $app->make(PdfRendererInterface::class),
                storage:  $app->make(StorageInterface::class),
            )
        );

        // ── Nouveaux générateurs de documents ────────────────────────────────

        $this->app->singleton(NodeRenderer::class, fn () => new NodeRenderer(
            nodeBinary: config('procostat-reporting.node_binary', 'node'),
            timeout:    config('procostat-reporting.node_timeout', 120),
        ));

        $this->app->singleton(ExcelDocumentGenerator::class);

        $this->app->singleton(WordDocumentGenerator::class, fn ($app)
            => new WordDocumentGenerator($app->make(NodeRenderer::class)));

        $this->app->singleton(PowerPointDocumentGenerator::class, fn ($app)
            => new PowerPointDocumentGenerator($app->make(NodeRenderer::class)));

        // ReportManager conservé pour usage générique éventuel
        $this->app->singleton(ReportManager::class, function ($app) {
            $manager = new ReportManager(stopOnFirstError: true);
            $manager->register($app->make(ExcelDocumentGenerator::class));
            $manager->register($app->make(WordDocumentGenerator::class));
            $manager->register($app->make(PowerPointDocumentGenerator::class));

            return $manager;
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'procostat-reporting');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/procostat-reporting.php' => config_path('procostat-reporting.php'),
            ], 'procostat-reporting-config');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/procostat-reporting'),
            ], 'procostat-reporting-views');

            $this->publishes([
                __DIR__ . '/../resources/assets' => public_path('vendor/procostat-reporting'),
            ], 'procostat-reporting-assets');
        }
    }
}
