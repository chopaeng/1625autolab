<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';

echo "=========================================================\n";
echo "    1625 AUTOLAB - GOOGLE SHEETS INQUIRY SYNC / BACKFILL\n";
echo "=========================================================\n\n";

$settings = (new SiteSettingsService())->getAll();
$webhookUrl = trim((string) ($settings['google_sheets_webhook_url'] ?? ''));

if ($webhookUrl === '') {
    echo "ERROR: Google Sheets Webhook URL is not configured in Site Settings.\n";
    echo "Please set your Webhook URL in Admin Settings -> Google Sheets Integration.\n";
    exit(1);
}

echo "Configured Webhook URL: {$webhookUrl}\n\n";

$inquiryService = new InquiryService();
$inquiries = $inquiryService->getAll();
$total = count($inquiries);

echo "Found {$total} inquiries in database/storage.\n";
echo "Starting synchronization to Google Sheets...\n\n";

try {
    $result = GoogleSheetsSyncService::pushAllToSheets($inquiries);
    echo "Sync Completed Successfully!\n";
    echo "Successfully Synced: " . ($result['syncedCount'] ?? $total) . "\n";
} catch (\Throwable $e) {
    echo "Sync FAILED: " . $e->getMessage() . "\n";
    exit(1);
}

echo "=========================================================\n";
