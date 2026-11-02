<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Dashboard\Http\Controllers;

use CodingTriplets\Zatca\Laravel\Dashboard\Queries\DashboardStats;
use CodingTriplets\Zatca\Laravel\Dashboard\Queries\ReportQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class DashboardController
{
    public function __invoke(Request $request, DashboardStats $stats): View
    {
        $period = in_array($request->query('period'), ['1', '7', '30', 'all'], true) ? (string) $request->query('period') : '30';
        $since = $period === 'all' ? null : now()->subDays((int) $period);

        return view('zatca::dashboard.home', [
            'stats' => $stats->summary($since),
            'period' => $period,
            // last 14 days by invoice date, for the small charts under each workflow panel
            'daily' => (new ReportQuery(['from' => now()->subDays(13)->toDateString(), 'to' => now()->toDateString()]))->daily(),
        ]);
    }
}
