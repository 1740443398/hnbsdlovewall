<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/fields.php';

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

// 字段投影（E14）：不传 fields/top 时与改造前完全一致。
jsonSuccess(lwProjectTop([
    'total_amount' => (float)($record['total_amount'] ?? $totalAmount),
    'current_amount' => (float)($record['current_amount'] ?? 0),
    'sponsor_list' => lwProjectRows($sponsorList, lwFieldsRequest(),
        ['name', 'amount', 'message', 'date', 'created_at', 'nickname', 'avatar', 'qq'], []),
], lwTopRequest(), ['sponsor_list']));