<?php

declare(strict_types=1);

use GuzzleHttp\Client;

/**
 * GoogleSheetsSyncService
 *
 * Handles bidirectional (two-way) live synchronization between Apollo and Google Sheets:
 *  - Outbound (Apollo -> Sheets): Pushes new/updated inquiries to Google Apps Script webhook.
 *  - Inbound (Sheets -> Apollo): Processes row edits sent by Google Apps Script triggers.
 *  - Pull (Sheets -> Apollo): Fetches all rows from Google Sheets on demand.
 */
class GoogleSheetsSyncService
{
    private Client $http;
    private static bool $syncDisabled = false;

    public function __construct()
    {
        $this->http = new Client([
            'timeout' => 15,
            'http_errors' => false,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * Executes a callback with outbound Google Sheets sync suppressed.
     * Useful when processing inbound sync to prevent recursion/loops.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function withoutSync(callable $callback): mixed
    {
        $prev = self::$syncDisabled;
        self::$syncDisabled = true;
        try {
            return $callback();
        } finally {
            self::$syncDisabled = $prev;
        }
    }

    /**
     * Formats an inquiry record into a complete payload with all aliases.
     *
     * @param array<string, mixed> $inquiry
     * @return array<string, mixed>
     */
    public static function formatInquiryPayload(array $inquiry): array
    {
        // Resolve service name if service_id is available
        $serviceName = '';
        $rawServiceId = $inquiry['serviceId'] ?? $inquiry['service_id'] ?? null;
        if ($rawServiceId !== null && DB_NAME !== '') {
            try {
                $stmt = Database::getInstance()->prepare('SELECT title FROM services WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => (int) $rawServiceId]);
                if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $serviceName = (string) ($row['title'] ?? '');
                }
            } catch (\Throwable) {
                // Ignore service lookup failure
            }
        }

        if ($serviceName === '') {
            $serviceName = (string) ($inquiry['serviceName'] ?? $inquiry['service_name'] ?? $inquiry['service'] ?? '');
        }
        if ($serviceName === '') {
            $serviceName = (string) ($inquiry['productToPurchase'] ?? $inquiry['product_to_purchase'] ?? '');
        }

        $rawServiceType = (string) ($inquiry['serviceType'] ?? $inquiry['service_type'] ?? 'shop_visit');
        $serviceTypeVal = (strtolower($rawServiceType) === 'home_service' || strtolower($rawServiceType) === 'home service') ? 'Home Service' : 'Shop Visit';
        $idVal = (string) ($inquiry['id'] ?? $inquiry['inquiryId'] ?? '');
        $refVal = (string) ($inquiry['referenceNumber'] ?? $inquiry['reference_number'] ?? '');
        $fullNameVal = (string) ($inquiry['fullName'] ?? $inquiry['full_name'] ?? '');
        $emailVal = (string) ($inquiry['emailAddress'] ?? $inquiry['email_address'] ?? '');
        $addressVal = (string) ($inquiry['address'] ?? '');
        $phoneVal = (string) ($inquiry['contactNumber'] ?? $inquiry['contact_number'] ?? '');
        $fbVal = (string) ($inquiry['facebookName'] ?? $inquiry['facebook_name'] ?? '');
        $makeVal = (string) ($inquiry['make'] ?? '');
        $modelVal = (string) ($inquiry['model'] ?? '');
        $yearVal = (string) ($inquiry['yearModel'] ?? $inquiry['year_model'] ?? $inquiry['year'] ?? '');
        $productVal = (string) ($inquiry['productToPurchase'] ?? $inquiry['product_to_purchase'] ?? '');
        $plateVal = (string) ($inquiry['plateNumber'] ?? $inquiry['plate_number'] ?? '');
        $dateVal = (string) ($inquiry['appointmentDate'] ?? $inquiry['appointment_date'] ?? '');
        $timeVal = (string) ($inquiry['appointmentTime'] ?? $inquiry['appointment_time'] ?? '');
        $statusVal = (string) ($inquiry['status'] ?? 'pending');
        $notesVal = (string) ($inquiry['internalNotes'] ?? $inquiry['internal_notes'] ?? '');
        $createdVal = (string) ($inquiry['createdAt'] ?? $inquiry['created_at'] ?? date('Y-m-d H:i:s'));
        $nowStr = date('Y-m-d H:i:s');

        return [
            // Standard camelCase keys
            'id' => $idVal,
            'timestamp' => $createdVal,
            'fullName' => $fullNameVal,
            'emailAddress' => $emailVal,
            'address' => $addressVal,
            'contactNumber' => $phoneVal,
            'facebookName' => $fbVal,
            'make' => $makeVal,
            'model' => $modelVal,
            'yearModel' => $yearVal,
            'serviceType' => $serviceTypeVal,
            'serviceName' => $serviceName,
            'productToPurchase' => $productVal,
            'plateNumber' => $plateVal,
            'appointmentDate' => $dateVal,
            'appointmentTime' => $timeVal,
            'status' => $statusVal,
            'internalNotes' => $notesVal,
            'lastUpdated' => $nowStr,
            'inquiryId' => $idVal,
            'referenceNumber' => $refVal,

            // Snake_case aliases
            'inquiry_id' => $idVal,
            'reference_number' => $refVal,
            'full_name' => $fullNameVal,
            'email_address' => $emailVal,
            'contact_number' => $phoneVal,
            'facebook_name' => $fbVal,
            'year_model' => $yearVal,
            'service_type' => $serviceTypeVal,
            'product_to_purchase' => $productVal,
            'plate_number' => $plateVal,
            'appointment_date' => $dateVal,
            'appointment_time' => $timeVal,
            'internal_notes' => $notesVal,
            'last_updated' => $nowStr,

            // Header Name Aliases for header-matching scripts
            'Timestamp' => $createdVal,
            'Full Name' => $fullNameVal,
            'Email address' => $emailVal,
            'Address' => $addressVal,
            'Contact Number' => $phoneVal,
            'Facebook Name' => $fbVal,
            'Car Make' => $makeVal,
            'Car Model' => $modelVal,
            'Year Model' => $yearVal,
            'Service Type' => $serviceTypeVal,
            'Service Location' => $serviceTypeVal,
            'Service Name' => $serviceName,
            'Product to Purchase' => $productVal,
            'Plate Number' => $plateVal,
            'Appointment Date' => $dateVal,
            'Appointment Time' => $timeVal,
            'Status' => $statusVal,
            'Internal Notes' => $notesVal,
            'Last Updated' => $nowStr,
            'Inquiry ID' => $idVal,
            'Reference Number' => $refVal,
        ];
    }

    /**
     * Sends inquiry data to the Google Sheets Webhook URL if configured.
     *
     * This method is fire-and-forget: it flushes the HTTP response to the
     * client FIRST, then dispatches the webhook in the background so that
     * the Google Apps Script round-trip (which can take 5-25 s) never
     * blocks or times out the customer's browser request.
     *
     * @param array<string, mixed> $inquiry
     */
    public static function syncInquiry(array $inquiry): void
    {
        if (self::$syncDisabled) {
            return;
        }

        try {
            $settings = (new SiteSettingsService())->getAll();

            $syncEnabled = ($settings['google_sheets_sync_enabled'] ?? '1') !== '0';
            $outboundEnabled = ($settings['google_sheets_outbound_enabled'] ?? '1') !== '0';
            if (!$syncEnabled || !$outboundEnabled) {
                return;
            }

            $webhookUrl = trim((string) ($settings['google_sheets_webhook_url'] ?? ''));

            if ($webhookUrl === '') {
                return;
            }

            $payload = self::formatInquiryPayload($inquiry);
            $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);

            // ── Fire-and-forget: flush HTTP response before hitting Sheets ──
            // This ensures the customer's browser gets a response immediately
            // and never sees a timeout caused by the Google Apps Script latency.
            ignore_user_abort(true);

            if (function_exists('fastcgi_finish_request')) {
                // PHP-FPM: instantly close the connection to the browser.
                fastcgi_finish_request();
            } elseif (!headers_sent()) {
                // Apache mod_php: set Content-Length and flush output buffers
                // so the browser considers the response complete.
                $size = ob_get_length();
                if ($size !== false && $size > 0) {
                    header('Content-Length: ' . $size);
                }
                header('Connection: close');
                ob_end_flush();
                flush();
            }
            // ── Background: now call the Sheets webhook ─────────────────────
            // The browser already received its response; use a tight timeout
            // (12 s connect + 12 s total) so we never stall a PHP worker for
            // 30 s if Google Apps Script is slow or unreachable.

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $webhookUrl,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $jsonPayload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json; charset=utf-8',
                ],
                CURLOPT_CONNECTTIMEOUT => 8,   // fail fast if GAS is unreachable
                CURLOPT_TIMEOUT        => 12,  // browser is already gone; don't hog worker
                CURLOPT_SSL_VERIFYPEER => false,
            ]);

            $response  = curl_exec($ch);
            $curlError = curl_error($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($curlError !== '') {
                error_log('[GoogleSheetsSyncService] cURL error: ' . $curlError);
            } else {
                error_log("[GoogleSheetsSyncService] Outbound Webhook response ({$httpCode}): " . (string) $response);
            }
        } catch (\Throwable $e) {
            error_log('[GoogleSheetsSyncService] Outbound Webhook failed: ' . $e->getMessage());
        }
    }

    /**
     * Pushes all inquiries in a single batch request to Google Sheets.
     *
     * @param array<int, array<string, mixed>> $inquiries
     * @return array{success: bool, syncedCount: int, error?: string}
     */
    public static function pushAllToSheets(array $inquiries): array
    {
        // Allow up to 5 min for large datasets; GAS itself is capped at ~6 min.
        @set_time_limit(300);

        if (self::$syncDisabled) {
            return ['success' => true, 'syncedCount' => 0];
        }

        $settings = (new SiteSettingsService())->getAll();
        $syncEnabled = ($settings['google_sheets_sync_enabled'] ?? '1') !== '0';
        $outboundEnabled = ($settings['google_sheets_outbound_enabled'] ?? '1') !== '0';
        if (!$syncEnabled || !$outboundEnabled) {
            return ['success' => true, 'syncedCount' => 0];
        }

        $webhookUrl = trim((string) ($settings['google_sheets_webhook_url'] ?? ''));
        if ($webhookUrl === '') {
            throw new RuntimeException('Google Sheets Webhook URL is not configured.', 422);
        }

        $rows = [];
        foreach ($inquiries as $inquiry) {
            $rows[] = self::formatInquiryPayload($inquiry);
        }

        $totalCount  = count($rows);
        $totalSynced = 0;
        $lastResponse = null;
        $errors = [];

        // ── Send in chunks of 100 rows per request ────────────────────────
        // GAS bulkSyncRowsFromApollo processes everything in memory, so large
        // chunks are fast.  Chunking at 100 keeps individual payloads small
        // enough to stay within GAS's 50 MB URL-fetch limit while reducing
        // round-trips from potentially 25+ (old chunk=4) down to 1–2.
        $chunks = array_chunk($rows, 100);

        foreach ($chunks as $chunkIndex => $chunk) {
            $payload = [
                'action' => 'sync_all',
                'rows'   => $chunk,
            ];

            $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $webhookUrl,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $jsonPayload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json; charset=utf-8',
                ],
                CURLOPT_CONNECTTIMEOUT => 10,
                // GAS execution limit is ~6 min; give each chunk up to 55 s
                // (well under GAS limit, and avoids PHP / nginx timeouts).
                CURLOPT_TIMEOUT        => 55,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);

            $response  = curl_exec($ch);
            $curlError = curl_error($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($curlError !== '') {
                $msg = "Chunk " . ($chunkIndex + 1) . "/" . count($chunks) . " cURL error: {$curlError}";
                error_log('[GoogleSheetsSyncService] pushAllToSheets ' . $msg);
                $errors[] = $msg;
                // Continue with remaining chunks rather than aborting entirely
                continue;
            }

            $resData = json_decode((string) $response, true);
            if ($httpCode < 200 || $httpCode >= 400 || (is_array($resData) && ($resData['success'] ?? null) === false)) {
                $err = is_array($resData) ? ($resData['error'] ?? 'Google Sheets rejected sync.') : "HTTP {$httpCode}";
                $msg = "Chunk " . ($chunkIndex + 1) . "/" . count($chunks) . ": {$err}";
                error_log('[GoogleSheetsSyncService] pushAllToSheets error — ' . $msg);
                $errors[] = $msg;
                continue;
            }

            $totalSynced += count($chunk);
            $lastResponse = $resData;
        }

        $nowStr = date('Y-m-d H:i:s');
        (new SiteSettingsService())->update([
            'google_sheets_last_sync_at' => $nowStr,
        ]);

        if (!empty($errors) && $totalSynced === 0) {
            throw new RuntimeException('Google Sheets push failed: ' . implode('; ', $errors), 502);
        }

        return [
            'success'      => true,
            'syncedCount'  => $totalSynced,
            'totalCount'   => $totalCount,
            'errors'       => $errors,
            'response'     => $lastResponse,
        ];
    }

    /**
     * Processes an inbound inquiry payload sent from Google Sheets (onEdit trigger or manual sync).
     *
     * @param array<string, mixed> $data
     * @param string|null $providedSecret
     * @param bool $bypassSecretCheck
     * @return array<string, mixed>
     */
    public static function processInboundSync(array $data, ?string $providedSecret = null, bool $bypassSecretCheck = false, bool $pullMode = false): array
    {
        $settings = (new SiteSettingsService())->getAll();

        $syncEnabled = ($settings['google_sheets_sync_enabled'] ?? '1') !== '0';
        $inboundEnabled = ($settings['google_sheets_bidirectional_enabled'] ?? $settings['google_sheets_inbound_enabled'] ?? '1') !== '0';
        if (!$syncEnabled || !$inboundEnabled) {
            return [
                'success' => false,
                'action'  => 'disabled',
                'message' => 'Bidirectional Google Sheets sync / inbound edits are disabled in site settings.',
            ];
        }

        if (!$bypassSecretCheck) {
            $configuredSecret = defined('GOOGLE_SHEETS_SYNC_SECRET') && GOOGLE_SHEETS_SYNC_SECRET !== ''
                ? GOOGLE_SHEETS_SYNC_SECRET
                : trim((string) ($settings['google_sheets_sync_secret'] ?? ''));
            if ($configuredSecret !== '') {
                $candidate = trim((string) (
                    $providedSecret
                    ?? $data['secret']
                    ?? $data['api_secret']
                    ?? ($_SERVER['HTTP_X_SHEETS_SECRET'] ?? '')
                    ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '')
                ));
                if (str_starts_with($candidate, 'Bearer ')) {
                    $candidate = substr($candidate, 7);
                }
                if ($candidate === '' || !hash_equals($configuredSecret, $candidate)) {
                    throw new RuntimeException('Invalid or missing Google Sheets webhook secret key.', 401);
                }
            }
        }

        // Handle batch sync payload { action: 'sync_all', rows: [...] }
        if (isset($data['action']) && $data['action'] === 'sync_all' && isset($data['rows']) && is_array($data['rows'])) {
            $created = 0;
            $updated = 0;
            $unchanged = 0;
            $errors = [];

            foreach ($data['rows'] as $idx => $row) {
                if (!is_array($row)) continue;
                try {
                    $res = self::processInboundSync($row, null, true, $pullMode);
                    if (($res['action'] ?? '') === 'created') $created++;
                    elseif (($res['action'] ?? '') === 'updated') $updated++;
                    else $unchanged++;
                } catch (\Throwable $e) {
                    $errors[] = "Row #" . ($idx + 2) . ": " . $e->getMessage();
                }
            }

            return [
                'success' => true,
                'action' => 'batch',
                'total' => count($data['rows']),
                'created' => $created,
                'updated' => $updated,
                'unchanged' => $unchanged,
                'errors' => $errors,
            ];
        }

        // Normalize incoming data from various header/key variations
        $id = trim((string) ($data['id'] ?? $data['inquiryId'] ?? $data['inquiry_id'] ?? $data['Inquiry ID'] ?? $data['ID'] ?? ''));
        $ref = trim((string) ($data['referenceNumber'] ?? $data['reference_number'] ?? $data['Reference Number'] ?? $data['ref'] ?? ''));
        $fullName = trim((string) ($data['fullName'] ?? $data['full_name'] ?? $data['Full Name'] ?? $data['customerName'] ?? $data['name'] ?? ''));
        $email = trim((string) ($data['emailAddress'] ?? $data['email_address'] ?? $data['Email address'] ?? $data['Email Address'] ?? $data['email'] ?? ''));
        $phone = trim((string) ($data['contactNumber'] ?? $data['contact_number'] ?? $data['Contact Number'] ?? $data['phone'] ?? ''));
        $address = trim((string) ($data['address'] ?? $data['Address'] ?? ''));
        $fbName = trim((string) ($data['facebookName'] ?? $data['facebook_name'] ?? $data['Facebook Name'] ?? ''));
        $make = trim((string) ($data['make'] ?? $data['Car Make'] ?? $data['Make'] ?? ''));
        $model = trim((string) ($data['model'] ?? $data['Car Model'] ?? $data['Model'] ?? ''));
        $year = trim((string) ($data['yearModel'] ?? $data['year_model'] ?? $data['Year Model'] ?? $data['year'] ?? ''));
        $rawServiceType = trim((string) ($data['serviceType'] ?? $data['service_type'] ?? $data['Service Type'] ?? $data['Service Location'] ?? $data['serviceLocation'] ?? $data['service_location'] ?? ''));
        $serviceType = (strtolower($rawServiceType) === 'home_service' || strtolower($rawServiceType) === 'home service') ? 'home_service' : ($rawServiceType !== '' ? 'shop_visit' : '');
        $serviceOrProduct = trim((string) ($data['productToPurchase'] ?? $data['product_to_purchase'] ?? $data['Product to Purchase'] ?? $data['serviceName'] ?? $data['service_name'] ?? $data['Service Name'] ?? $data['service'] ?? ''));
        $plate = trim((string) ($data['plateNumber'] ?? $data['plate_number'] ?? $data['Plate Number'] ?? ''));
        $rawDate = trim((string) ($data['appointmentDate'] ?? $data['appointment_date'] ?? $data['Appointment Date'] ?? $data['date'] ?? ''));
        $rawTime = trim((string) ($data['appointmentTime'] ?? $data['appointment_time'] ?? $data['Appointment Time'] ?? $data['time'] ?? ''));
        $rawStatus = trim((string) ($data['status'] ?? $data['Status'] ?? ''));
        $notes = trim((string) ($data['internalNotes'] ?? $data['internal_notes'] ?? $data['Internal Notes'] ?? $data['notes'] ?? ''));

        // Normalize Date
        $appDate = self::normalizeDate($rawDate);
        // Normalize Time
        $appTime = self::normalizeTime($rawTime);
        // Normalize Status
        $cleanStatus = self::normalizeStatus($rawStatus);

        // Normalize Phone Number (leading 0, international format, etc.)
        $normalizedPhone = self::normalizePhone($phone);

        $inquirySvc = new InquiryService();

        if ($pullMode) {
            // Pull mode: Inquiry ID is the ONLY stable unique key.
            // Two distinct inquiries from the same customer must not be merged
            // via fuzzy phone/name/date matching — each row in Sheets represents
            // a separate booking. Only match if we have a valid ID that exists.
            $existing = ($id !== '') ? $inquirySvc->getById($id) : null;
        } else {
            $existing = $inquirySvc->findMatchingInquiry([
                'id'            => $id,
                // referenceNumber intentionally omitted: it changes on every reschedule,
                // so using it as a match key would create duplicate inquiries after rebooking.
                'contactNumber' => $normalizedPhone !== '' ? $normalizedPhone : $phone,
                'plateNumber'   => $plate,
                'appointmentDate' => $appDate,
                'emailAddress'  => $email,
                'fullName'      => $fullName,
            ]);
        }

        // Suppress outbound sync while updating/inserting to prevent sync echo loops
        return self::withoutSync(function () use (
            $existing,
            $inquirySvc,
            $id,
            $ref,
            $fullName,
            $email,
            $phone,
            $normalizedPhone,
            $address,
            $fbName,
            $make,
            $model,
            $year,
            $serviceType,
            $serviceOrProduct,
            $plate,
            $appDate,
            $appTime,
            $cleanStatus,
            $notes
        ): array {
            // Case A: Existing inquiry found -> Update
            if ($existing !== null) {
                $inqId = (string) $existing['id'];
                $changes = [];

                if ($fullName !== '' && $fullName !== (string) ($existing['fullName'] ?? '')) {
                    $changes['fullName'] = $fullName;
                }
                if ($email !== '' && $email !== (string) ($existing['emailAddress'] ?? '') && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $changes['emailAddress'] = $email;
                }
                $effectivePhone = $normalizedPhone !== '' ? $normalizedPhone : $phone;
                if ($effectivePhone !== '' && $effectivePhone !== (string) ($existing['contactNumber'] ?? '')) {
                    $changes['contactNumber'] = $effectivePhone;
                }
                if ($address !== '' && $address !== (string) ($existing['address'] ?? '')) {
                    $changes['address'] = $address;
                }
                if ($fbName !== '' && $fbName !== (string) ($existing['facebookName'] ?? '')) {
                    $changes['facebookName'] = $fbName;
                }
                if ($make !== '' && $make !== (string) ($existing['make'] ?? '')) {
                    $changes['make'] = $make;
                }
                if ($model !== '' && $model !== (string) ($existing['model'] ?? '')) {
                    $changes['model'] = $model;
                }
                if ($year !== '' && $year !== (string) ($existing['yearModel'] ?? '')) {
                    $changes['yearModel'] = $year;
                }
                if ($serviceType !== '' && $serviceType !== (string) ($existing['serviceType'] ?? $existing['service_type'] ?? '')) {
                    $changes['serviceType'] = $serviceType;
                }
                if ($plate !== '' && $plate !== (string) ($existing['plateNumber'] ?? '')) {
                    $changes['plateNumber'] = $plate;
                }
                if ($serviceOrProduct !== '' && $serviceOrProduct !== (string) ($existing['productToPurchase'] ?? '')) {
                    $changes['productToPurchase'] = $serviceOrProduct;
                }
                if ($cleanStatus !== null && $cleanStatus !== (string) ($existing['status'] ?? '')) {
                    $changes['status'] = $cleanStatus;
                }
                if ($notes !== '' && $notes !== (string) ($existing['internalNotes'] ?? '')) {
                    $changes['internalNotes'] = $notes;
                }

                // Date/Time Schedule Changes
                $targetDate = $appDate !== '' ? $appDate : (string) ($existing['appointmentDate'] ?? '');
                $targetTime = $appTime !== '' ? $appTime : (string) ($existing['appointmentTime'] ?? '');
                $isRescheduled = false;

                if ($appDate !== '' && $appDate !== (string) ($existing['appointmentDate'] ?? '')) {
                    $changes['appointmentDate'] = $appDate;
                    $isRescheduled = true;
                }
                if ($appTime !== '' && $appTime !== (string) ($existing['appointmentTime'] ?? '')) {
                    $changes['appointmentTime'] = $appTime;
                    $isRescheduled = true;
                }

                if (empty($changes)) {
                    return [
                        'success' => true,
                        'action' => 'unchanged',
                        'message' => 'No changes detected for this inquiry.',
                        'inquiry' => $existing,
                        'inquiryId' => $inqId,
                        'referenceNumber' => $existing['referenceNumber'] ?? '',
                    ];
                }

                $updated = $inquirySvc->updateFullInquiry($inqId, $changes, null);

                // Notifications on status change or reschedule
                if (isset($changes['status'])) {
                    try {
                        (new NotificationJobQueueService())->dispatch('inquiry_status_changed', [
                            'inquiry' => $updated,
                        ]);
                    } catch (\Throwable $e) {
                        error_log('[processInboundSync] Notification failed: ' . $e->getMessage());
                    }
                }

                if ($isRescheduled) {
                    try {
                        (new NotificationJobQueueService())->dispatch('inquiry_rescheduled', [
                            'inquiry' => $updated,
                        ]);
                    } catch (\Throwable $e) {
                        error_log('[processInboundSync] Reschedule notification failed: ' . $e->getMessage());
                    }
                }

                return [
                    'success' => true,
                    'action' => 'updated',
                    'message' => 'Inquiry updated successfully from Google Sheets.',
                    'inquiry' => $updated,
                    'inquiryId' => $inqId,
                    'referenceNumber' => $updated['referenceNumber'] ?? $existing['referenceNumber'] ?? '',
                ];
            }

            // Case B: No existing inquiry found -> Create New Inquiry
            if ($fullName === '' && $phone === '' && $email === '') {
                return [
                    'success' => false,
                    'action' => 'skipped',
                    'error' => 'Row does not have enough information to create a new inquiry (Full Name or Contact required).',
                ];
            }

            $validEmail = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : 'customer@1625autolab.local';
            $validDate = $appDate !== '' ? $appDate : date('Y-m-d');
            $validTime = $appTime !== '' ? $appTime : '10:00 AM';

            $newPayload = [
                'fullName' => $fullName !== '' ? $fullName : 'Google Sheets Customer',
                'contactNumber' => $normalizedPhone !== '' ? $normalizedPhone : ($phone !== '' ? $phone : '09000000000'),
                'emailAddress' => $validEmail,
                'address' => $address !== '' ? $address : 'N/A',
                'facebookName' => $fbName,
                'make' => $make !== '' ? $make : 'General',
                'model' => $model !== '' ? $model : 'Vehicle',
                'yearModel' => $year !== '' ? $year : date('Y'),
                'serviceType' => $serviceType !== '' ? $serviceType : 'shop_visit',
                'productToPurchase' => $serviceOrProduct !== '' ? $serviceOrProduct : 'General Inquiry',
                'plateNumber' => $plate,
                'appointmentDate' => $validDate,
                'appointmentTime' => $validTime,
                'status' => $cleanStatus ?? 'pending',
                'internalNotes' => $notes,
            ];

            $created = $inquirySvc->create($newPayload);

            // Dispatch customer inquiry notification
            try {
                (new NotificationJobQueueService())->dispatch('customer_inquiry', ['data' => $created]);
            } catch (\Throwable $e) {
                error_log('[processInboundSync] New inquiry notification dispatch failed: ' . $e->getMessage());
            }

            return [
                'success' => true,
                'action' => 'created',
                'message' => 'New inquiry created in Apollo from Google Sheets.',
                'inquiry' => $created,
                'inquiryId' => $created['id'] ?? '',
                'referenceNumber' => $created['referenceNumber'] ?? $created['reference_number'] ?? '',
            ];
        });
    }

    /**
     * Pulls all inquiries from the configured Google Sheets Web App URL into Apollo.
     *
     * @return array<string, mixed>
     */
    public static function pullAllFromSheets(): array
    {
        $settings = (new SiteSettingsService())->getAll();
        $webhookUrl = trim((string) ($settings['google_sheets_webhook_url'] ?? ''));

        if ($webhookUrl === '') {
            throw new RuntimeException('Google Sheets Webhook URL is not configured in Site Settings.', 422);
        }

        $urlWithAction = $webhookUrl . (str_contains($webhookUrl, '?') ? '&' : '?') . 'action=get_all';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $urlWithAction,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $curlErr = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErr !== '') {
            throw new RuntimeException('Failed to connect to Google Sheets Web App: ' . $curlErr, 500);
        }

        $json = json_decode((string) $response, true);
        if (!is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
            $respPreview = mb_substr((string) $response, 0, 300);
            throw new RuntimeException("Invalid response from Google Sheets Web App (HTTP {$httpCode}): {$respPreview}", 502);
        }

        $rows = $json['data'];
        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $errors = [];

        self::$syncDisabled = true;
        try {
            foreach ($rows as $index => $row) {
                if (!is_array($row)) continue;
                try {
                    // pullMode=true: only match by Inquiry ID, never by phone/name/date.
                    // This ensures that a customer with multiple distinct bookings in
                    // Google Sheets always gets each row imported as its own inquiry
                    // rather than being merged into a single record.
                    $res = self::processInboundSync($row, null, true, true);
                    if (($res['action'] ?? '') === 'created') {
                        $created++;
                    } elseif (($res['action'] ?? '') === 'updated') {
                        $updated++;
                    } else {
                        $unchanged++;
                    }
                } catch (\Throwable $e) {
                    $errors[] = "Row #" . ($index + 2) . ": " . $e->getMessage();
                }
            }
        } finally {
            self::$syncDisabled = false;
        }

        $nowStr = date('Y-m-d H:i:s');
        (new SiteSettingsService())->update([
            'google_sheets_last_sync_at' => $nowStr,
        ]);

        return [
            'success' => true,
            'total' => count($rows),
            'created' => $created,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'errors' => $errors,
            'lastSyncAt' => $nowStr,
        ];
    }

    /**
     * Generates a pre-filled Google Apps Script (Code.gs) template.
     */
    public static function getAppsScriptTemplate(?string $siteUrl = null, ?string $secret = null): string
    {
        $settings = (new SiteSettingsService())->getAll();
        $configuredSecret = $secret ?? (
            (defined('GOOGLE_SHEETS_SYNC_SECRET') && GOOGLE_SHEETS_SYNC_SECRET !== '')
                ? GOOGLE_SHEETS_SYNC_SECRET
                : trim((string) ($settings['google_sheets_sync_secret'] ?? ''))
        );
        
        $baseInboundUrl = $siteUrl ?? (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://')
            . ($_SERVER['HTTP_HOST'] ?? 'localhost')
            . '/api/integrations/google-sheets/inbound'
        );

        $templatePath = __DIR__ . '/../google-sheets/Code.gs';
        if (file_exists($templatePath)) {
            $code = (string) file_get_contents($templatePath);
            // Replace DEFAULT_API_URL and DEFAULT_SECRET
            $code = preg_replace("/DEFAULT_API_URL:\s*'[^']*'/", "DEFAULT_API_URL: '" . addslashes($baseInboundUrl) . "'", $code);
            $code = preg_replace("/DEFAULT_SECRET:\s*'[^']*'/", "DEFAULT_SECRET: '" . addslashes($configuredSecret) . "'", $code);
            return (string) $code;
        }

        return "// Template file Code.gs not found.";
    }

    /**
     * Normalizes various date string formats to Y-m-d.
     */
    private static function normalizeDate(string $rawDate): string
    {
        $raw = trim($rawDate);
        if ($raw === '') return '';

        // If already in Y-m-d format
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw;
        }

        // Try standard parsing
        $ts = strtotime($raw);
        if ($ts !== false && $ts > 0) {
            return date('Y-m-d', $ts);
        }

        return '';
    }

    /**
     * Normalizes time strings to clean standard format (e.g. 10:00 AM).
     */
    private static function normalizeTime(string $rawTime): string
    {
        $raw = trim($rawTime);
        if ($raw === '') return '';

        $ts = strtotime($raw);
        if ($ts !== false) {
            return date('g:i A', $ts);
        }

        return $raw;
    }

    /**
     * Normalizes status to accepted enum values.
     */
    private static function normalizeStatus(string $rawStatus): ?string
    {
        $st = strtolower(trim($rawStatus));
        if ($st === '') return null;

        $map = [
            'pending' => 'pending',
            'confirmed' => 'confirmed',
            'in_progress' => 'in_progress',
            'in progress' => 'in_progress',
            'in-progress' => 'in_progress',
            'completed' => 'completed',
            'done' => 'completed',
            'finished' => 'completed',
            'cancelled' => 'cancelled',
            'canceled' => 'cancelled',
        ];

        return $map[$st] ?? null;
    }

    /**
     * Normalizes Philippine contact numbers to standard 11-digit format (09XXXXXXXXX).
     */
    public static function normalizePhone(string $rawPhone): string
    {
        $raw = trim($rawPhone);
        if ($raw === '') return '';
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '') return $raw;

        if (str_starts_with($digits, '63') && strlen($digits) === 12) {
            return '0' . substr($digits, 2);
        }
        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            return '0' . $digits;
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '09')) {
            return $digits;
        }

        return $raw;
    }

    /**
     * Test a webhook URL with a test payload.
     *
     * @return array<string, mixed>
     */
    public static function testWebhook(string $webhookUrl): array
    {
        $testPayload = [
            'timestamp' => date('Y-m-d H:i:s'),
            'fullName' => 'Test Customer',
            'emailAddress' => 'test@1625autolab.com',
            'address' => 'Test Address, San Fernando, Pampanga',
            'contactNumber' => '09123456789',
            'facebookName' => 'Test FB Profile',
            'make' => 'Toyota',
            'model' => 'Vios',
            'yearModel' => '2023',
            'serviceType' => 'Shop Visit',
            'Service Type' => 'Shop Visit',
            'serviceName' => 'Headlight Retrofit Test',
            'productToPurchase' => 'Headlight Retrofit Package',
            'plateNumber' => 'TEST-123',
            'appointmentDate' => date('Y-m-d'),
            'appointmentTime' => '10:00 AM',
            'status' => 'confirmed',
            'internalNotes' => 'Test internal notes from Apollo sync',
            'lastUpdated' => date('Y-m-d H:i:s'),
            'inquiryId' => 'test-id-0000',
            'referenceNumber' => '1625-TEST-0001',
        ];

        $jsonPayload = json_encode($testPayload, JSON_UNESCAPED_UNICODE);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $webhookUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json; charset=utf-8',
            ],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError !== '') {
            return ['success' => false, 'error' => 'cURL error: ' . $curlError];
        }

        $respStr = (string) $response;
        $isOk = $httpCode >= 200 && $httpCode < 400 && !str_contains($respStr, 'Hindi Nahanap ang Page') && !str_contains($respStr, 'drive.google.com');

        $errorMessage = null;
        if (!$isOk) {
            if (str_contains($respStr, 'Hindi Nahanap ang Page') || str_contains($respStr, 'drive.google.com') || $httpCode === 401 || $httpCode === 404) {
                $errorMessage = "Google Webhook Access Error (HTTP {$httpCode}): Please ensure your Google Apps Script Web App Deployment settings have 'Who has access' set to 'Anyone' and that your URL ends with '/exec'.";
            } else {
                $errorMessage = "Google Webhook Error (HTTP {$httpCode}).";
            }
        }

        return [
            'success' => $isOk,
            'httpCode' => $httpCode,
            'error' => $errorMessage,
            'response' => $respStr,
        ];
    }
}
