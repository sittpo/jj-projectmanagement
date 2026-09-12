<?php
declare(strict_types=1);

// One report model shared by the HTML dashboard and CSV export.
// Replace this sample provider with database queries when rollout data is introduced.
final class DashboardReport
{
    public static function sample(): array
    {
        return [
            'metrics' => [
                ['label' => 'Stores live', 'value' => '64', 'unit' => '/ 68', 'trend' => '94% of the rollout complete', 'icon' => 'store'],
                ['label' => 'Network uptime', 'value' => '99.87', 'unit' => '%', 'trend' => 'Stable across live stores', 'icon' => 'pulse'],
                ['label' => 'Installed on time', 'value' => '100', 'unit' => '%', 'trend' => 'All completed visits on schedule', 'icon' => 'calendar'],
                ['label' => 'DVR deployed', 'value' => '94', 'unit' => '%', 'trend' => '64 of 68 stores upgraded', 'icon' => 'camera'],
            ],
            'workstreams' => [
                ['label' => 'Networking', 'complete' => 64, 'total' => 68, 'icon' => 'network'],
                ['label' => 'Audio equipment', 'complete' => 58, 'total' => 68, 'icon' => 'audio'],
                ['label' => 'DVR replacement', 'complete' => 64, 'total' => 68, 'icon' => 'camera'],
                ['label' => 'Rack cabinets', 'complete' => 61, 'total' => 68, 'icon' => 'rack'],
            ],
            'visits' => [
                ['code' => 'ST-065', 'store' => 'Riverside', 'work' => 'Network & rack installation', 'team' => 'Field team A', 'date' => '14 Sep', 'status' => 'Scheduled', 'tone' => 'neutral'],
                ['code' => 'ST-066', 'store' => 'Northgate', 'work' => 'Audio & DVR replacement', 'team' => 'Field team B', 'date' => '15 Sep', 'status' => 'Scheduled', 'tone' => 'neutral'],
                ['code' => 'ST-067', 'store' => 'Central Square', 'work' => 'Full equipment upgrade', 'team' => 'Unassigned', 'date' => '16 Sep', 'status' => 'Needs assignment', 'tone' => 'warning'],
                ['code' => 'ST-068', 'store' => 'Westfield', 'work' => 'Full equipment upgrade', 'team' => 'Field team A', 'date' => '17 Sep', 'status' => 'Scheduled', 'tone' => 'neutral'],
            ],
            'activity' => [
                ['title' => 'DVR replacement completed', 'detail' => 'ST-064 · Harbour Point', 'time' => '25 min ago', 'icon' => 'check'],
                ['title' => 'Photo documentation uploaded', 'detail' => 'ST-061 · Oak Avenue · 6 photos', 'time' => '1 hour ago', 'icon' => 'camera'],
                ['title' => 'Rack cabinet signed off', 'detail' => 'ST-063 · South Park', 'time' => '2 hours ago', 'icon' => 'check'],
            ],
        ];
    }
}
