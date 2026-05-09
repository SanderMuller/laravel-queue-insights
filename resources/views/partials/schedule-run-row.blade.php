@php
    /**
     * Shared `<tr>` for scheduled-run tables. Used in two scopes —
     * the panel-level recent-runs table (`$showTask = true`) and the
     * per-task modal's recent-runs table (`$showTask = false`). Per
     * `06-drilldown-modals.md` §7 — diverging row markup between scopes
     * is a maintenance burden for zero user benefit.
     *
     * Required scope:
     *   array<string, mixed>  $run        Row from `ScheduleReader::recentRuns`
     *   bool                  $showTask   Render the leading Task column
     *   array<string, string> $taskLabels Optional task_key → label map
     *
     * Both scopes click-through to the per-run modal via `openRunModal`
     * so the operator's mental model is consistent across surfaces.
     */
    $showTask ??= false;
    $taskLabels ??= [];

    $statusBadge = static function (string $status): array {
        return match ($status) {
            'success' => ['label' => '✓ ok', 'cls' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300 ring-emerald-600/20 dark:ring-emerald-400/30'],
            'failed' => ['label' => '✗ failed', 'cls' => 'bg-red-50 text-red-700 dark:bg-red-900/40 dark:text-red-300 ring-red-600/20 dark:ring-red-400/30'],
            'skipped' => ['label' => '↷ skipped', 'cls' => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300 ring-gray-950/10 dark:ring-white/10'],
            'hung' => ['label' => '⏳ hung', 'cls' => 'bg-amber-50 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300 ring-amber-600/20 dark:ring-amber-400/30'],
            'missed' => ['label' => '⏰ missed', 'cls' => 'bg-amber-50 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300 ring-amber-600/20 dark:ring-amber-400/30'],
            'starting' => ['label' => '… running', 'cls' => 'bg-sky-50 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300 ring-sky-600/20 dark:ring-sky-400/30'],
            default => ['label' => $status, 'cls' => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300 ring-gray-950/10 dark:ring-white/10'],
        };
    };

    $formatDuration = static function (?int $ms): string {
        if ($ms === null || $ms <= 0) {
            return '—';
        }
        if ($ms < 1000) {
            return $ms . 'ms';
        }
        return number_format($ms / 1000, 2) . 's';
    };

    $badge = $statusBadge($run['status']);
    $taskLabel = $taskLabels[$run['task_key']] ?? null;
@endphp

<tr class="cursor-pointer transition hover:bg-gray-50 dark:hover:bg-white/5"
    wire:click="openRunModal('{{ $run['task_key'] }}', '{{ $run['run_id'] }}')">
    @if($showTask)
        <td class="px-3 py-2 align-top">
            @if($taskLabel !== null)
                <p class="truncate text-gray-900 dark:text-gray-100" title="{{ $taskLabel }}">{{ $taskLabel }}</p>
                <p class="font-mono text-[11px] text-gray-400 dark:text-gray-500">{{ substr($run['task_key'], 0, 8) }}</p>
            @else
                <p class="font-mono text-xs text-gray-700 dark:text-gray-300">{{ substr($run['task_key'], 0, 8) }}</p>
            @endif
        </td>
    @endif
    <td class="px-3 py-2 align-top tabular-nums text-gray-700 dark:text-gray-300">{{ $run['host_id'] }}</td>
    <td class="px-3 py-2 align-top tabular-nums text-gray-700 dark:text-gray-300">
        <x-queue-insights::qi-time :at="$run['started_at_ms']"/>
    </td>
    <td class="px-3 py-2 align-top tabular-nums text-gray-700 dark:text-gray-300">{{ $formatDuration($run['runtime_ms']) }}</td>
    <td class="px-3 py-2 align-top tabular-nums text-gray-700 dark:text-gray-300">{{ $run['exit_code'] ?? '—' }}</td>
    <td class="px-3 py-2 align-top">
        <span class="inline-flex items-center rounded-md py-1 pr-2 pl-1.5 text-xs font-medium ring-1 ring-inset {{ $badge['cls'] }}">{{ $badge['label'] }}</span>
    </td>
</tr>
