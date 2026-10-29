<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Consistent gzip'd SQL dump using mysqldump --single-transaction (no table locks, so the
 * shop keeps selling while it runs). Keeps the newest N files.
 *
 *   php artisan shop:backup            (scheduled daily, see routes/console.php)
 *   php artisan shop:backup --keep=30
 * Restore: gunzip -c backup.sql.gz | mysql -u USER -p DBNAME
 */
class BackupDatabase extends Command
{
    protected $signature = 'shop:backup {--keep=14} {--path=}';

    protected $description = 'Dump the database to storage/app/backups (gzip) and prune old dumps';

    public function handle(): int
    {
        $c = config('database.connections.'.config('database.default'));
        $dir = $this->option('path') ?: storage_path('app/backups');
        File::ensureDirectoryExists($dir);
        $file = $dir.'/'.$c['database'].'_'.now()->format('Ymd_His').'.sql.gz';
        $bin = env('MYSQLDUMP_PATH', 'mysqldump');

        // Password via env var, never on the command line (visible in process lists).
        $cmd = sprintf('%s --single-transaction --quick --routines --triggers --no-tablespaces -h%s -P%s -u%s %s',
            escapeshellarg($bin), escapeshellarg($c['host']), escapeshellarg((string) $c['port']), escapeshellarg($c['username']), escapeshellarg($c['database']));
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), ['MYSQL_PWD' => (string) $c['password']]));
        if (! is_resource($proc)) {
            $this->error('Could not start mysqldump');

            return 1;
        }
        $gz = gzopen($file, 'wb6');
        while (! feof($pipes[1])) {
            gzwrite($gz, fread($pipes[1], 1 << 20));
        }
        gzclose($gz);
        $err = stream_get_contents($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0 || filesize($file) < 200) {
            @unlink($file);
            $this->error('mysqldump failed: '.trim($err));
            report(new \RuntimeException('Database backup failed: '.trim($err)));

            return 1;
        }

        $files = collect(File::glob($dir.'/'.$c['database'].'_*.sql.gz'))->sort()->values();
        $files->slice(0, max(0, $files->count() - (int) $this->option('keep')))->each(fn ($f) => File::delete($f));
        $this->info('Backup written: '.$file.' ('.round(filesize($file) / 1024).' KB)');

        return 0;
    }
}
