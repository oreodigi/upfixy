<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ACC-007 tamper evidence.
 *   --verify          recompute the hash chain; exit 1 and name the first broken row if anything changed
 *   --anchor          record the newest hash and append it to an anchor log OUTSIDE the database
 *                     (production: ship this line to separate storage / email it daily)
 *   --against=FILE    also check that every anchor in FILE still matches the database
 */
class AuditChain extends Command
{
    protected $signature = 'upfixy:audit {--verify} {--anchor} {--against=}';
    protected $description = 'Verify or anchor the tamper-evident audit chain';

    public function handle(): int
    {
        $db = DB::connection('pgsql_worker');
        $status = self::SUCCESS;
        if ($this->option('verify')) {
            $r = $db->selectOne('SELECT * FROM audit_verify(0)');
            if ($r->broken_id === null) {
                $this->info("Audit chain intact ({$r->checked} rows).");
            } else {
                $this->error("Audit chain BROKEN at row {$r->broken_id}: {$r->reason}");
                $status = self::FAILURE;
            }
        }
        if ($file = $this->option('against')) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                [, $id, $hash] = array_pad(explode(' ', $line), 3, null);
                $now = $db->selectOne('SELECT row_hash FROM audit_events WHERE id = ?', [(int) $id])?->row_hash;
                if ($now !== $hash) {
                    $this->error("Anchor mismatch at row {$id}: the audit trail was rewritten after it was anchored.");
                    $status = self::FAILURE;
                }
            }
            if ($status === self::SUCCESS) $this->info('All external anchors match.');
        }
        if ($this->option('anchor')) {
            $a = $db->selectOne('SELECT * FROM audit_anchor()');
            if (!$a) { $this->warn('Audit trail is empty; nothing to anchor.'); return $status; }
            $line = now()->utc()->toIso8601String() . " {$a->last_id} {$a->last_hash}";
            file_put_contents(storage_path('app/audit-anchors.log'), $line . PHP_EOL, FILE_APPEND | LOCK_EX);
            $this->line($line);
        }
        return $status;
    }
}
