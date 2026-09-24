<?php

namespace App\Console\Commands;

use App\Models\ExternalAirport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class ImportExternalAirportsCommand extends Command
{
    private const SOURCE_URL = 'https://davidmegginson.github.io/ourairports-data/airports.csv';

    private const BATCH_SIZE = 1000;

    protected $signature = 'external-airports:import
        {--file= : Optional local OurAirports airports.csv path}';

    protected $description = 'Import valid ICAO airports from the OurAirports global CSV.';

    public function handle(): int
    {
        $startedAt = microtime(true);
        $localFile = $this->option('file');
        $temporaryFile = null;

        try {
            if (is_string($localFile) && $localFile !== '') {
                $source = $localFile;
                $this->assertReadableFile($source);
            } else {
                $source = self::SOURCE_URL;
                $temporaryFile = $this->downloadSource();
                $localFile = $temporaryFile;
            }

            [$totalRows, $upsertedRows, $skippedRows] = $this->importFile($localFile);
            $elapsed = number_format(microtime(true) - $startedAt, 2);

            $this->newLine();
            $this->info('External airports import completed.');
            $this->line("Source: {$source}");
            $this->line("Rows read: {$totalRows}");
            $this->line("Valid ICAO rows upserted: {$upsertedRows}");
            $this->line("Rows skipped (missing or invalid ICAO): {$skippedRows}");
            $this->line("Elapsed: {$elapsed} seconds");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error("External airports import failed: {$exception->getMessage()}");

            return self::FAILURE;
        } finally {
            if ($temporaryFile && is_file($temporaryFile)) {
                @unlink($temporaryFile);
            }
        }
    }

    private function downloadSource(): string
    {
        $temporaryFile = tempnam(sys_get_temp_dir(), 'ourairports-');

        if ($temporaryFile === false) {
            throw new RuntimeException('Unable to create a temporary file for the CSV download.');
        }

        try {
            Http::connectTimeout(15)
                ->timeout(180)
                ->accept('text/csv')
                ->sink($temporaryFile)
                ->get(self::SOURCE_URL)
                ->throw();

            if (filesize($temporaryFile) === 0) {
                throw new RuntimeException('Downloaded CSV is empty.');
            }

            return $temporaryFile;
        } catch (Throwable $exception) {
            @unlink($temporaryFile);

            throw new RuntimeException('Unable to download the OurAirports CSV.', previous: $exception);
        }
    }

    private function importFile(string $path): array
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to open the CSV file.');
        }

        try {
            $headers = fgetcsv($handle);

            if ($headers === false) {
                throw new RuntimeException('The CSV file does not contain a header row.');
            }

            $headers = array_map(static fn (string $header): string => trim($header), $headers);
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]) ?? $headers[0];
            $columnIndexes = $this->columnIndexes($headers);

            $progress = $this->output->createProgressBar();
            $progress->setRedrawFrequency(1000);
            $progress->start();

            $batch = [];
            $totalRows = 0;
            $upsertedRows = 0;
            $skippedRows = 0;
            $timestamp = now('UTC');

            while (true) {
                $row = fgetcsv($handle);

                if ($row === false) {
                    if (! feof($handle)) {
                        throw new RuntimeException('Unable to parse the CSV file.');
                    }

                    break;
                }

                $totalRows++;
                $progress->advance();

                $code = strtoupper(trim((string) ($row[$columnIndexes['icao_code']] ?? '')));

                if (! preg_match('/^[A-Z]{4}$/', $code)) {
                    $skippedRows++;

                    continue;
                }

                $batch[$code] = [
                    'code' => $code,
                    'name' => trim((string) ($row[$columnIndexes['name']] ?? '')),
                    'city' => trim((string) ($row[$columnIndexes['municipality']] ?? '')),
                    'country' => trim((string) ($row[$columnIndexes['iso_country']] ?? '')),
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];

                if (count($batch) >= self::BATCH_SIZE) {
                    $this->upsert($batch);
                    $upsertedRows += count($batch);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                $this->upsert($batch);
                $upsertedRows += count($batch);
            }

            $progress->finish();

            return [$totalRows, $upsertedRows, $skippedRows];
        } finally {
            fclose($handle);
        }
    }

    private function columnIndexes(array $headers): array
    {
        $indexes = array_flip($headers);
        $requiredHeaders = ['icao_code', 'name', 'municipality', 'iso_country'];
        $missingHeaders = array_filter(
            $requiredHeaders,
            static fn (string $header): bool => ! array_key_exists($header, $indexes),
        );

        if ($missingHeaders !== []) {
            throw new RuntimeException('CSV is missing required headers: '.implode(', ', $missingHeaders).'.');
        }

        return array_intersect_key($indexes, array_flip($requiredHeaders));
    }

    private function upsert(array $batch): void
    {
        ExternalAirport::query()->upsert(
            array_values($batch),
            ['code'],
            ['name', 'city', 'country', 'updated_at'],
        );
    }

    private function assertReadableFile(string $path): void
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Unable to read CSV file: {$path}");
        }
    }
}
