<?php
require_once __DIR__ . '/../config/config.php';

$fs = getFS();
$record = $fs->findById('sponsor', 1);

if (!$record) {
    jsonSuccess(['total_amount' => 0, 'current_amount' => 0, 'sponsor_list' => []]);
}

$sponsorList = json_decode($record['sponsor_list'] ?? '[]', true) ?: [];
$totalAmount = 0;
foreach ($sponsorList as $item) {
    $totalAmount += floatval($item['amount'] ?? 0);
}

jsonSuccess([
    'total_amount' => (float)($record['total_amount'] ?? $totalAmount),
    'current_amount' => (float)($record['current_amount'] ?? 0),
    'sponsor_list' => $sponsorList
]);