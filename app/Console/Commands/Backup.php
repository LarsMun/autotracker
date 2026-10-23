<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The database as a compressed dump to the NAS, plus a copy of the raw pages, so everything can
 * be parsed again even when this machine's disk is lost.
 */
#[Signature('auto:backup')]
#[Description('Dump the database to the backups disk (keeping config/backup.php keep_days) and copy new raw pages there')]
class Backup extends Command
{
    private const string PREFIX = 'auto_';

    public function handle(): int
    {
        $disk = Storage::disk(config('backup.disk'));
        $file = self::PREFIX.now()->format('Ymd_His').'.sql.gz';

        if (! $this->dumpDatabase($disk, $file)) {
            return self::FAILURE;
        }

        $this->copyRawPages($disk);
        $this->removeOld($disk);

        return self::SUCCESS;
    }

    private function dumpDatabase(Filesystem $disk, string $file): bool
    {
        $database = config('database.connections.'.config('database.default'));

        // pipefail: a failing mysqldump must fail the whole line, not only gzip's part.
        $command = sprintf(
            'set -o pipefail; mysqldump --single-transaction --no-tablespaces -h %s -P %s -u %s %s | gzip > %s',
            escapeshellarg((string) $database['host']),
            escapeshellarg((string) $database['port']),
            escapeshellarg((string) $database['username']),
            escapeshellarg((string) $database['database']),
            escapeshellarg($disk->path($file)),
        );

        // The password through the environment, so it never shows in the process list.
        $result = Process::env(['MYSQL_PWD' => (string) $database['password']])
            ->timeout(1800)
            ->run(['bash', '-c', $command]);

        if ($result->failed() || ! $disk->exists($file) || $disk->size($file) === 0) {
            $disk->delete($file);
            $this->error("The database dump failed: {$result->errorOutput()}");
            report(new RuntimeException("auto:backup could not dump the database: {$result->errorOutput()}"));

            return false;
        }

        $this->info("Database: {$file}");

        return true;
    }

    /**
     * Raw pages are kept for good: new files are copied, existing ones left alone.
     */
    private function copyRawPages(Filesystem $disk): void
    {
        $from = Storage::disk(config('gaspedaal.raw_disk'))->path(config('gaspedaal.raw_dir'));

        if (! is_dir($from)) {
            return;
        }

        $result = Process::timeout(1800)->run(['cp', '-ru', $from.'/.', $disk->path('raw')]);

        if ($result->failed()) {
            $this->warn("Raw pages could not be copied: {$result->errorOutput()}");
            report(new RuntimeException("auto:backup could not copy the raw pages: {$result->errorOutput()}"));

            return;
        }

        $this->info('Raw pages copied.');
    }

    /**
     * Only its own dumps: anything else on the disk is left alone.
     */
    private function removeOld(Filesystem $disk): void
    {
        $before = now()->subDays(config('backup.keep_days'))->getTimestamp();

        foreach ($disk->files() as $file) {
            if (str_starts_with($file, self::PREFIX) && $disk->lastModified($file) < $before) {
                $disk->delete($file);
                $this->line("Removed: {$file}");
            }
        }
    }
}
