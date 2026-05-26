<?php

namespace Procorad\ProcostatReporting\Node;

use Procorad\ProcostatReporting\Exceptions\ReportGenerationException;
use Symfony\Component\Process\Process;

/**
 * Serialises a PHP payload to a temp JSON file, invokes a Node.js renderer,
 * and cleans up regardless of outcome.
 *
 * The Node script receives:
 *   $argv[1] — absolute path to the JSON payload file
 *   $argv[2] — absolute output path for the generated document
 */
final class NodeRenderer
{
    public function __construct(
        private readonly string $nodeBinary = 'node',
        private readonly int    $timeout    = 120,
    ) {}

    /**
     * @param string              $script     Absolute path to the .js renderer
     * @param array<string,mixed> $payload    Data to pass as JSON
     * @param string              $outputPath Absolute path the Node script will write to
     * @param string              $format     Used in exception messages
     *
     * @throws ReportGenerationException
     */
    public function render(
        string $script,
        array  $payload,
        string $outputPath,
        string $format = 'node',
    ): void {
        $tmpJson = tempnam(sys_get_temp_dir(), 'procostat_');

        if ($tmpJson === false) {
            throw new ReportGenerationException($format, 'Cannot create temporary JSON file.');
        }

        try {
            file_put_contents($tmpJson, json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            $process = new Process(
                command: [$this->nodeBinary, $script, $tmpJson, $outputPath],
                timeout: $this->timeout,
            );

            $process->run();

            if (! $process->isSuccessful()) {
                throw ReportGenerationException::nodeFailure(
                    $format,
                    $process->getErrorOutput(),
                    $process->getExitCode() ?? -1,
                );
            }
        } catch (ReportGenerationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ReportGenerationException::fromThrowable($format, $e);
        } finally {
            /*if (file_exists($tmpJson)) {
                unlink($tmpJson);
            }*/
                \Log::debug('node_payload_path', ['path' => $tmpJson]);
        }
    }
}
