{{--
    Stacked daily bars, server-rendered SVG (no JavaScript).
    $days: list<array{date: string, accepted: int, pending: int, problem: int}>
    $labels: ['accepted' => 'Reported', ...]  $title: string
    Identity is never colour-only: legend + per-bar hover title + table view.
--}}
@php
    $series = [
        'accepted' => ['fill-emerald-600 dark:fill-emerald-500', 'bg-emerald-600 dark:bg-emerald-500'],
        'pending' => ['fill-slate-400 dark:fill-slate-500', 'bg-slate-400 dark:bg-slate-500'],
        'problem' => ['fill-red-600 dark:fill-red-400', 'bg-red-600 dark:bg-red-400'],
    ];
    $n = max(1, count($days));
    $max = max(1, ...array_map(static fn ($d) => $d['accepted'] + $d['pending'] + $d['problem'], $days ?: [['accepted' => 0, 'pending' => 0, 'problem' => 0]]));
    // a "nice" axis maximum: 1, 2 or 5 × 10^k
    $mag = 10 ** (int) floor(log10($max));
    $top = collect([1, 2, 5, 10])->map(fn ($m) => $m * $mag)->first(fn ($v) => $v >= $max);
    $W = 720; $H = 200; $padL = 36; $padB = 22; $padT = 8;
    $plotW = $W - $padL - 4; $plotH = $H - $padB - $padT;
    $slot = $plotW / $n;
    $bar = max(2, min(28, $slot - 2));
    $labelEvery = (int) ceil($n / 8);
    $total = array_sum(array_map(static fn ($d) => $d['accepted'] + $d['pending'] + $d['problem'], $days));
@endphp
<figure class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
    <figcaption class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
        <span class="text-sm font-semibold">{{ $title }}</span>
        <span class="flex flex-wrap gap-3 text-xs text-slate-600 dark:text-slate-300">
            @foreach ($series as $key => [$fill, $swatch])
                <span class="inline-flex items-center gap-1.5"><span class="inline-block h-2.5 w-2.5 rounded-sm {{ $swatch }}"></span>{{ $labels[$key] }}</span>
            @endforeach
        </span>
    </figcaption>
    @if ($total === 0)
        <p class="py-10 text-center text-sm text-slate-500">No documents in this period.</p>
    @else
    <svg viewBox="0 0 {{ $W }} {{ $H }}" class="h-auto w-full" role="img" aria-label="{{ $title }}: {{ $total }} documents">
        @foreach ([0, 0.5, 1] as $f)
            @php $y = $padT + $plotH - $f * $plotH; @endphp
            <line x1="{{ $padL }}" x2="{{ $W - 4 }}" y1="{{ $y }}" y2="{{ $y }}" class="stroke-slate-200 dark:stroke-slate-800" stroke-width="1"/>
            <text x="{{ $padL - 6 }}" y="{{ $y + 3 }}" text-anchor="end" class="fill-slate-500 text-[10px]">{{ $f == 0.5 ? rtrim(rtrim(number_format($top / 2, 1), '0'), '.') : (int) ($f * $top) }}</text>
        @endforeach
        @foreach ($days as $i => $d)
            @php
                $x = $padL + $i * $slot + ($slot - $bar) / 2;
                $base = $padT + $plotH;
                $sum = $d['accepted'] + $d['pending'] + $d['problem'];
            @endphp
            <g>
                <title>{{ $d['date'] }} — {{ $labels['accepted'] }}: {{ $d['accepted'] }}, {{ $labels['pending'] }}: {{ $d['pending'] }}, {{ $labels['problem'] }}: {{ $d['problem'] }}</title>
                {{-- hit target: the whole column --}}
                <rect x="{{ $padL + $i * $slot }}" y="{{ $padT }}" width="{{ $slot }}" height="{{ $plotH }}" fill="transparent"/>
                @foreach (['accepted', 'pending', 'problem'] as $key)
                    @if ($d[$key] > 0)
                        @php
                            $h = max(1, $d[$key] / $top * $plotH);
                            $base -= $h;
                        @endphp
                        <rect x="{{ $x }}" y="{{ $base + 1 }}" width="{{ $bar }}" height="{{ max(1, $h - 2) }}" rx="{{ min(2, $bar / 4) }}" class="{{ $series[$key][0] }}"/>
                    @endif
                @endforeach
            </g>
            @if ($i % $labelEvery === 0)
                <text x="{{ $padL + $i * $slot + $slot / 2 }}" y="{{ $H - 6 }}" text-anchor="middle" class="fill-slate-500 text-[10px]">{{ \Illuminate\Support\Carbon::parse($d['date'])->format('d M') }}</text>
            @endif
        @endforeach
        <line x1="{{ $padL }}" x2="{{ $W - 4 }}" y1="{{ $padT + $plotH }}" y2="{{ $padT + $plotH }}" class="stroke-slate-300 dark:stroke-slate-700" stroke-width="1"/>
    </svg>
    <details class="mt-2 text-xs">
        <summary class="cursor-pointer text-slate-500">Show as table</summary>
        <div class="mt-2 max-h-64 overflow-auto">
            <table class="min-w-full">
                <thead><tr class="text-left text-slate-500"><th class="py-1 pr-4">Day</th>@foreach ($series as $key => $_)<th class="py-1 pr-4">{{ $labels[$key] }}</th>@endforeach</tr></thead>
                <tbody>
                @foreach ($days as $d)
                    @continue($d['accepted'] + $d['pending'] + $d['problem'] === 0)
                    <tr><td class="py-0.5 pr-4 font-mono">{{ $d['date'] }}</td><td class="pr-4">{{ $d['accepted'] }}</td><td class="pr-4">{{ $d['pending'] }}</td><td class="pr-4">{{ $d['problem'] }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </details>
    @endif
</figure>
