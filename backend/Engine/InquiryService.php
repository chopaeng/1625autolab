<?php

declare(strict_types=1);

class InquiryService
{
    private const SLOT_CAPACITY = 3;
    private const SLOT_WINDOW_MINUTES = 5 * 60;

    private bool $useDb;
    private InquiryActivityService $activity;

    private static string $storageFile = __DIR__ . '/../storage/inquiries.json';
    private static string $occupancyStorageFile = __DIR__ . '/../storage/inquiry_slot_occupancy.json';

    public function __construct()
    {
        $this->useDb = DB_NAME !== '';
        $this->activity = new InquiryActivityService();
        $this->ensureReferenceNumbers();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $normalized = $this->normalizePayload($data);
        $this->validatePayload($normalized);
        $this->assertSlotCapacity($normalized['appointmentDate'], $normalized['appointmentTime']);

        // Resolve service_id: accept int, numeric string, or null
        $rawServiceId = $data['serviceId'] ?? $data['service_id'] ?? null;
        $serviceId = ($rawServiceId !== null && $rawServiceId !== '') ? (int) $rawServiceId : null;

        // Auto-match service_id from productToPurchase title if serviceId is not explicitly provided
        if ($serviceId === null && !empty($normalized['productToPurchase']) && $this->useDb) {
            try {
                $db = Database::getInstance();
                $stmtService = $db->prepare('SELECT id FROM services WHERE LOWER(:prod1) LIKE CONCAT("%", LOWER(title), "%") OR LOWER(title) LIKE CONCAT("%", LOWER(:prod2), "%") LIMIT 1');
                $stmtService->execute([
                    ':prod1' => trim($normalized['productToPurchase']),
                    ':prod2' => trim($normalized['productToPurchase']),
                ]);
                if ($rowSvc = $stmtService->fetch(PDO::FETCH_ASSOC)) {
                    $serviceId = (int)$rowSvc['id'];
                }
            } catch (Exception $e) {
                // Ignore matching errors if services table is unavailable
            }
        }

        $refNum = $this->generateReferenceNumber($normalized['appointmentDate']);
        $inquiry = [
            'id' => $this->uuid(),
            'referenceNumber' => $refNum,
            'reference_number' => $refNum,
            'user_id' => $data['userId'] ?? null,
            'service_id' => $serviceId,
            'serviceType' => $normalized['serviceType'] ?? 'shop_visit',
            'service_type' => $normalized['serviceType'] ?? 'shop_visit',
            'fullName' => $normalized['fullName'],
            'address' => $normalized['address'],
            'contactNumber' => $normalized['contactNumber'],
            'emailAddress' => $normalized['emailAddress'],
            'facebookName' => $normalized['facebookName'],
            'plateNumber' => $normalized['plateNumber'],
            'make' => $normalized['make'],
            'model' => $normalized['model'],
            'yearModel' => $normalized['yearModel'],
            'productToPurchase' => $normalized['productToPurchase'],
            'appointmentDate' => $normalized['appointmentDate'],
            'appointmentTime' => $normalized['appointmentTime'],
            'status' => 'pending',
            'createdAt' => date('Y-m-d H:i:s'),
        ];

        if ($this->useDb) {
            $this->dbInsert($inquiry);
        } else {
            $this->fileInsert($inquiry);
        }

        $this->syncOccupancyForInquiry($inquiry);

        $this->activity->add(
            $inquiry['id'],
            ActivityEvents::INQUIRY_CREATED,
            'Inquiry submitted',
            null,
            null,
            'client' // Typically created by client
        );

        return $inquiry;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAll(): array
    {
        return $this->useDb ? $this->dbGetAll() : $this->fileGetAll();
    }

    /**
     * @param string|int $userId
     * @return array<int, array<string, mixed>>
     */
    public function getAllForUser($userId): array
    {
        if (!$this->useDb) {
            // Not implemented for file storage
            return [];
        }
        $db = Database::getInstance();
        $stmt = $db->prepare(
                'SELECT id, reference_number, user_id, service_id, service_type, full_name, address, contact_number, email_address, facebook_name, plate_number,
                    make, model, year_model, product_to_purchase, appointment_date,
                    appointment_time, status, internal_notes, created_at
                 FROM customer_inquiries
                 WHERE user_id = :user_id
             ORDER BY appointment_date ASC, appointment_time ASC, created_at DESC'
        );
        $stmt->execute([':user_id' => (string) $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn (array $row): array => $this->mapDbRow($row), $rows);
    }

    /**
     * Update the status of an inquiry.
     *
     * @param string $id
     * @param string $status
     * @return array<string, mixed>
     */
    public function updateStatus(string $id, string $status): array
    {
        return $this->updateDetails($id, $status, null, null);
    }

    /**
     * Update inquiry details such as status or appointment schedule.
     *
     * @param string $id
     * @param string|null $status
     * @param string|null $appointmentDate
     * @param string|null $appointmentTime
     * @param int|null $actorUserId
     * @return array<string, mixed>
     */
    public function updateDetails(string $id, ?string $status = null, ?string $appointmentDate = null, ?string $appointmentTime = null, ?int $actorUserId = null): array
    {
        $status = $status === null ? null : trim($status);
        if ($status !== null) {
            $allowed = ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled'];
            if (!in_array($status, $allowed, true)) {
                throw new RuntimeException('Invalid inquiry status.', 422);
            }
        }

        if ($appointmentDate !== null && trim($appointmentDate) === '') {
            throw new RuntimeException('Appointment date is required.', 422);
        }

        if ($appointmentTime !== null && trim($appointmentTime) === '') {
            throw new RuntimeException('Appointment time is required.', 422);
        }

        if ($status === null && $appointmentDate === null && $appointmentTime === null) {
            throw new RuntimeException('No changes were provided.', 422);
        }

        $targetDate = $appointmentDate;
        $targetTime = $appointmentTime;
        if ($targetDate === null || $targetTime === null) {
            $existing = $this->useDb ? $this->dbGetById($id) : $this->fileGetById($id);
            if ($existing === null) {
                throw new RuntimeException('Inquiry not found.', 404);
            }
            if ($targetDate === null) {
                $targetDate = (string) ($existing['appointmentDate'] ?? '');
            }
            if ($targetTime === null) {
                $targetTime = (string) ($existing['appointmentTime'] ?? '');
            }
        }

        $isScheduleChange = $appointmentDate !== null || $appointmentTime !== null;
        if ($isScheduleChange && $targetDate !== null && $targetTime !== null && trim((string) $targetDate) !== '' && trim((string) $targetTime) !== '') {
            $this->assertSlotCapacity((string) $targetDate, (string) $targetTime, $id);
        }

        if ($this->useDb) {
            $this->dbUpdateDetails($id, $status, $appointmentDate, $appointmentTime);
            $inquiry = $this->dbGetById($id);
            if ($inquiry === null) {
                throw new RuntimeException('Inquiry not found.', 404);
            }
            $this->syncOccupancyForInquiry($inquiry);

            if ($status !== null) {
                $this->activity->add($id, ActivityEvents::INQUIRY_STATUS_CHANGED, "Status changed to {$status}", null, $actorUserId, $actorUserId ? 'admin' : 'system');
            }
            if ($isScheduleChange) {
                $this->activity->add($id, ActivityEvents::INQUIRY_RESCHEDULED, "Rescheduled to {$appointmentDate} at {$appointmentTime}", null, $actorUserId, $actorUserId ? 'admin' : 'system');
            }

            return $inquiry;
        }

        $inquiries = $this->fileGetAll();
        $found = false;
        foreach ($inquiries as &$item) {
            if ((string) ($item['id'] ?? '') === $id) {
                if ($status !== null) {
                    $item['status'] = $status;
                }
                if ($appointmentDate !== null) {
                    $item['appointmentDate'] = $appointmentDate;
                }
                if ($appointmentTime !== null) {
                    $item['appointmentTime'] = $appointmentTime;
                }
                $item['updatedAt'] = date('c');
                $found = true;
                break;
            }
        }
        unset($item);

        if (!$found) {
            throw new RuntimeException('Inquiry not found.', 404);
        }

        file_put_contents(self::$storageFile, json_encode($inquiries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $updated = array_values(array_filter($inquiries, fn ($item) => (string) ($item['id'] ?? '') === $id))[0] ?? null;
        if (is_array($updated)) {
            $this->syncOccupancyForInquiry($updated);
        }
        if (!is_array($updated)) {
            throw new RuntimeException('Inquiry not found.', 404);
        }

        if ($status !== null) {
            $this->activity->add($id, ActivityEvents::INQUIRY_STATUS_CHANGED, "Status changed to {$status}", null, $actorUserId, $actorUserId ? 'admin' : 'system');
        }
        if ($isScheduleChange) {
            $this->activity->add($id, ActivityEvents::INQUIRY_RESCHEDULED, "Rescheduled to {$appointmentDate} at {$appointmentTime}", null, $actorUserId, $actorUserId ? 'admin' : 'system');
        }

        return $updated;
    }

    /**
     * Comprehensive update for customer inquiry details by admin.
     *
     * @param string $id
     * @param array<string, mixed> $data
     * @param int|null $actorUserId
     * @return array<string, mixed>
     */
    public function updateFullInquiry(string $id, array $data, ?int $actorUserId = null): array
    {
        $existing = $this->useDb ? $this->dbGetById($id) : $this->fileGetById($id);
        if ($existing === null) {
            throw new RuntimeException('Inquiry not found.', 404);
        }

        $fieldsToUpdate = [];

        if (array_key_exists('fullName', $data) || array_key_exists('full_name', $data)) {
            $val = trim((string)($data['fullName'] ?? $data['full_name'] ?? ''));
            if ($val === '') throw new RuntimeException('Full name cannot be empty.', 422);
            $fieldsToUpdate['full_name'] = $val;
        }

        if (array_key_exists('emailAddress', $data) || array_key_exists('email_address', $data)) {
            $val = trim((string)($data['emailAddress'] ?? $data['email_address'] ?? ''));
            if ($val === '' || !filter_var($val, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('A valid email address is required.', 422);
            }
            $fieldsToUpdate['email_address'] = $val;
        }

        if (array_key_exists('contactNumber', $data) || array_key_exists('contact_number', $data)) {
            $val = trim((string)($data['contactNumber'] ?? $data['contact_number'] ?? ''));
            if ($val === '') throw new RuntimeException('Contact number cannot be empty.', 422);
            $fieldsToUpdate['contact_number'] = $val;
        }

        if (array_key_exists('facebookName', $data) || array_key_exists('facebook_name', $data)) {
            $fieldsToUpdate['facebook_name'] = trim((string)($data['facebookName'] ?? $data['facebook_name'] ?? ''));
        }

        if (array_key_exists('address', $data)) {
            $fieldsToUpdate['address'] = trim((string)($data['address'] ?? ''));
        }

        if (array_key_exists('make', $data)) {
            $val = trim((string)($data['make'] ?? ''));
            if ($val === '') throw new RuntimeException('Make cannot be empty.', 422);
            $fieldsToUpdate['make'] = $val;
        }

        if (array_key_exists('model', $data)) {
            $val = trim((string)($data['model'] ?? ''));
            if ($val === '') throw new RuntimeException('Model cannot be empty.', 422);
            $fieldsToUpdate['model'] = $val;
        }

        if (array_key_exists('yearModel', $data) || array_key_exists('year_model', $data)) {
            $val = trim((string)($data['yearModel'] ?? $data['year_model'] ?? ''));
            if ($val === '') throw new RuntimeException('Year model cannot be empty.', 422);
            $fieldsToUpdate['year_model'] = $val;
        }

        if (array_key_exists('plateNumber', $data) || array_key_exists('plate_number', $data)) {
            $fieldsToUpdate['plate_number'] = trim((string)($data['plateNumber'] ?? $data['plate_number'] ?? ''));
        }

        if (array_key_exists('productToPurchase', $data) || array_key_exists('product_to_purchase', $data)) {
            $val = trim((string)($data['productToPurchase'] ?? $data['product_to_purchase'] ?? ''));
            if ($val === '') throw new RuntimeException('Product or service name cannot be empty.', 422);
            $fieldsToUpdate['product_to_purchase'] = $val;
        }

        if (array_key_exists('serviceId', $data) || array_key_exists('service_id', $data)) {
            $rawSvc = $data['serviceId'] ?? $data['service_id'] ?? null;
            $fieldsToUpdate['service_id'] = ($rawSvc !== null && $rawSvc !== '') ? (int)$rawSvc : null;
        }

        if (array_key_exists('serviceType', $data) || array_key_exists('service_type', $data) || array_key_exists('serviceLocation', $data) || array_key_exists('service_location', $data)) {
            $rawServiceType = trim((string) ($data['serviceType'] ?? $data['service_type'] ?? $data['serviceLocation'] ?? $data['service_location'] ?? ''));
            if ($rawServiceType !== '') {
                $fieldsToUpdate['service_type'] = (strtolower($rawServiceType) === 'home_service' || strtolower($rawServiceType) === 'home service') ? 'home_service' : 'shop_visit';
            }
        }

        if (array_key_exists('status', $data)) {
            $status = trim((string)$data['status']);
            $allowed = ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled'];
            if (!in_array($status, $allowed, true)) {
                throw new RuntimeException('Invalid inquiry status.', 422);
            }
            $fieldsToUpdate['status'] = $status;
        }

        if (array_key_exists('internalNotes', $data) || array_key_exists('internal_notes', $data)) {
            $fieldsToUpdate['internal_notes'] = mb_substr((string)($data['internalNotes'] ?? $data['internal_notes'] ?? ''), 0, 5000);
        }

        $targetDate = array_key_exists('appointmentDate', $data) || array_key_exists('appointment_date', $data)
            ? trim((string)($data['appointmentDate'] ?? $data['appointment_date'] ?? ''))
            : (string)($existing['appointmentDate'] ?? '');
        $targetTime = array_key_exists('appointmentTime', $data) || array_key_exists('appointment_time', $data)
            ? trim((string)($data['appointmentTime'] ?? $data['appointment_time'] ?? ''))
            : (string)($existing['appointmentTime'] ?? '');

        if ($targetDate !== (string)($existing['appointmentDate'] ?? '') || $targetTime !== (string)($existing['appointmentTime'] ?? '')) {
            if ($targetDate === '' || $targetTime === '') {
                throw new RuntimeException('Appointment date and time are required.', 422);
            }
            $this->assertSlotCapacity($targetDate, $targetTime, $id);
            $fieldsToUpdate['appointment_date'] = $targetDate;
            $fieldsToUpdate['appointment_time'] = $targetTime;
        }

        if (empty($fieldsToUpdate)) {
            return $existing;
        }

        $diff = $this->buildFieldDiff($existing, $fieldsToUpdate);

        if ($this->useDb) {
            $db = Database::getInstance();
            $setStatements = ['updated_at = CURRENT_TIMESTAMP'];
            $params = [':id' => $id];
            foreach ($fieldsToUpdate as $col => $val) {
                $setStatements[] = "{$col} = :{$col}";
                $params[":{$col}"] = $val;
            }
            $stmt = $db->prepare('UPDATE customer_inquiries SET ' . implode(', ', $setStatements) . ' WHERE id = :id');
            $stmt->execute($params);

            $updated = $this->dbGetById($id);
            if ($updated === null) {
                throw new RuntimeException('Failed to retrieve updated inquiry.', 500);
            }
            $this->syncOccupancyForInquiry($updated);

            $this->activity->add(
                $id,
                ActivityEvents::INQUIRY_STATUS_CHANGED,
                'Inquiry details updated by admin',
                $diff ?: null,
                $actorUserId,
                $actorUserId ? 'admin' : 'system'
            );

            return $updated;
        }

        $inquiries = $this->fileGetAll();
        foreach ($inquiries as &$item) {
            if ((string) ($item['id'] ?? '') === $id) {
                if (isset($fieldsToUpdate['full_name'])) $item['fullName'] = $fieldsToUpdate['full_name'];
                if (isset($fieldsToUpdate['email_address'])) $item['emailAddress'] = $fieldsToUpdate['email_address'];
                if (isset($fieldsToUpdate['contact_number'])) $item['contactNumber'] = $fieldsToUpdate['contact_number'];
                if (isset($fieldsToUpdate['facebook_name'])) $item['facebookName'] = $fieldsToUpdate['facebook_name'];
                if (isset($fieldsToUpdate['address'])) $item['address'] = $fieldsToUpdate['address'];
                if (isset($fieldsToUpdate['make'])) $item['make'] = $fieldsToUpdate['make'];
                if (isset($fieldsToUpdate['model'])) $item['model'] = $fieldsToUpdate['model'];
                if (isset($fieldsToUpdate['year_model'])) $item['yearModel'] = $fieldsToUpdate['year_model'];
                if (isset($fieldsToUpdate['plate_number'])) $item['plateNumber'] = $fieldsToUpdate['plate_number'];
                if (isset($fieldsToUpdate['product_to_purchase'])) $item['productToPurchase'] = $fieldsToUpdate['product_to_purchase'];
                if (array_key_exists('service_id', $fieldsToUpdate)) $item['serviceId'] = $fieldsToUpdate['service_id'];
                if (isset($fieldsToUpdate['service_type'])) {
                    $item['serviceType'] = $fieldsToUpdate['service_type'];
                    $item['service_type'] = $fieldsToUpdate['service_type'];
                }
                if (isset($fieldsToUpdate['status'])) $item['status'] = $fieldsToUpdate['status'];
                if (isset($fieldsToUpdate['internal_notes'])) $item['internalNotes'] = $fieldsToUpdate['internal_notes'];
                if (isset($fieldsToUpdate['appointment_date'])) $item['appointmentDate'] = $fieldsToUpdate['appointment_date'];
                if (isset($fieldsToUpdate['appointment_time'])) $item['appointmentTime'] = $fieldsToUpdate['appointment_time'];
                $item['updatedAt'] = date('c');
                break;
            }
        }
        unset($item);

        file_put_contents(self::$storageFile, json_encode($inquiries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $updated = array_values(array_filter($inquiries, fn ($item) => (string) ($item['id'] ?? '') === $id))[0] ?? null;
        if (is_array($updated)) {
            $this->syncOccupancyForInquiry($updated);
        }
        if (!is_array($updated)) {
            throw new RuntimeException('Inquiry not found.', 404);
        }

        $this->activity->add(
            $id,
            ActivityEvents::INQUIRY_STATUS_CHANGED,
            'Inquiry details updated by admin',
            $diff ?: null,
            $actorUserId,
            $actorUserId ? 'admin' : 'system'
        );

        return $updated;
    }

    /**
     * @param string $id
     * @param string $notes
     * @param int|null $actorUserId
     * @param string $actorRole
     * @return array<string, mixed>
     */
    public function updateServiceId(string $id, ?int $serviceId, ?int $actorUserId = null, string $actorRole = 'admin'): array
    {
        if ($this->useDb) {
            $db = Database::getInstance();
            $stmt = $db->prepare(
                'UPDATE customer_inquiries SET service_id = :service_id WHERE id = :id'
            );
            $stmt->execute([
                ':service_id' => $serviceId,
                ':id'         => $id,
            ]);

            if ($stmt->rowCount() === 0) {
                // If it didn't change, we still return the full object
                $existing = $this->dbGetById($id);
                if ($existing === null) {
                    throw new RuntimeException('Inquiry not found.', 404);
                }
            } else {
                // Look up service name for a readable log entry
                $serviceName = 'none';
                if ($serviceId !== null) {
                    $stmtName = $db->prepare('SELECT title FROM services WHERE id = :id LIMIT 1');
                    $stmtName->execute([':id' => $serviceId]);
                    $row = $stmtName->fetch(PDO::FETCH_ASSOC);
                    $serviceName = $row ? $row['title'] : "ID: $serviceId";
                }
                $this->activity->add(
                    $id,
                    'service_id_updated',
                    'Linked service updated',
                    'New service: ' . $serviceName,
                    $actorUserId,
                    $actorRole
                );
            }

            $updated = $this->dbGetById($id);
            if ($updated === null) {
                throw new RuntimeException('Failed to retrieve updated inquiry.', 500);
            }
            return $updated;
        }

        throw new RuntimeException('Database not configured.', 500);
    }

    /**
     * @param string $id
     * @param string $notes
     * @param int|null $actorUserId
     * @param string $actorRole
     * @return array<string, mixed>
     */
    public function updateInternalNotes(string $id, string $notes, ?int $actorUserId = null, string $actorRole = 'admin'): array
    {
        if ($this->useDb) {
            $db = Database::getInstance();
            $stmt = $db->prepare(
                'UPDATE customer_inquiries SET internal_notes = :notes WHERE id = :id'
            );
            $stmt->execute([
                ':notes' => $notes,
                ':id'    => $id,
            ]);

            if ($stmt->rowCount() === 0) {
                // If it didn't change, we still return the full object
                $existing = $this->dbGetById($id);
                if ($existing === null) {
                    throw new RuntimeException('Inquiry not found.', 404);
                }
            } else {
                $this->activity->add(
                    $id,
                    ActivityEvents::INQUIRY_INTERNAL_NOTES_UPDATED,
                    'Inquiry internal notes updated',
                    null,
                    $actorUserId,
                    $actorRole
                );
            }
            return $this->dbGetById($id) ?? [];
        }

        $items = $this->fileGetAll();
        $found = false;
        foreach ($items as &$item) {
            if ((string) ($item['id'] ?? '') === $id) {
                $item['internalNotes'] = $notes;
                $found = true;
                break;
            }
        }
        unset($item);

        if (!$found) {
            throw new RuntimeException('Inquiry not found.', 404);
        }

        file_put_contents(self::$storageFile, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->activity->add(
            $id,
            ActivityEvents::INQUIRY_INTERNAL_NOTES_UPDATED,
            'Inquiry internal notes updated',
            null,
            $actorUserId,
            $actorRole
        );

        $updated = array_values(array_filter($items, fn ($it) => (string) ($it['id'] ?? '') === $id))[0] ?? null;
        if (!is_array($updated)) {
            throw new RuntimeException('Inquiry not found.', 404);
        }

        return $updated;
    }

    /**
     * @param string $id
     */
    public function delete(string $id): void
    {
        if ($this->useDb) {
            $this->dbDelete($id);
            $this->deleteOccupancyForInquiry($id);
            return;
        }

        $inquiries = $this->fileGetAll();
        $filtered = array_values(array_filter(
            $inquiries,
            static fn (array $item): bool => (string) ($item['id'] ?? '') !== $id
        ));

        if (count($filtered) === count($inquiries)) {
            throw new RuntimeException('Inquiry not found.', 404);
        }

        file_put_contents(self::$storageFile, json_encode($filtered, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->deleteOccupancyForInquiry($id);
    }

    /**
     * @return string[]
     */
    public function getOccupiedSlots(string $date): array
    {
        $availability = $this->getAvailabilityForDate($date, []);
        return $availability['bookedSlots'];
    }

    /**
     * @return array<string, int>
     */
    public function getSlotCounts(string $date): array
    {
        $availability = $this->getAvailabilityForDate($date, []);
        return $availability['slotCounts'];
    }

    /**
     * @param string[] $allSlots
     * @return array{availableSlots:string[], bookedSlots:string[], slotCounts:array<string,int>, slotCapacity:int}
     */
    public function getAvailabilityForDate(string $date, array $allSlots = []): array
    {
        $slots = $allSlots;
        if ($slots === []) {
            $slots = $this->getAllSlotsForDate($date);
        }

        $activeAppointments = $this->getActiveAppointmentsForDate($date);
        $slotCounts = [];
        $bookedSlots = [];

        foreach ($slots as $slot) {
            $slotMinutes = $this->parseTimeToMinutes($slot);
            if ($slotMinutes === null) {
                continue;
            }

            $overlapCount = 0;
            foreach ($activeAppointments as $appointment) {
                if ($this->appointmentsOverlap($slotMinutes, $appointment['startMinutes'])) {
                    $overlapCount++;
                }
            }

            $slotCounts[$slot] = $overlapCount;
            if ($overlapCount >= self::SLOT_CAPACITY) {
                $bookedSlots[] = $slot;
            }
        }

        $availableSlots = array_values(array_diff($slots, $bookedSlots));

        return [
            'availableSlots' => $availableSlots,
            'bookedSlots' => $bookedSlots,
            'slotCounts' => $slotCounts,
            'slotCapacity' => self::SLOT_CAPACITY,
        ];
    }

    public function getStats(?string $timeframe = null, ?string $from = null, ?string $to = null): array
    {
        return $this->useDb ? $this->dbGetStats($timeframe, $from, $to) : $this->fileGetStats($timeframe, $from, $to);
    }

    /**
     * @param string $id
     * @return array<string, mixed>|null
     */
    public function getById(string $id): ?array
    {
        if ($this->useDb) {
            $found = $this->dbGetById($id);
            if ($found) return $found;
        }

        $items = $this->fileGetAll();
        $cleanId = str_starts_with($id, 'inq-') ? substr($id, 4) : $id;
        $prefixedId = str_starts_with($id, 'inq-') ? $id : 'inq-' . $id;
        $normRef = strtolower(preg_replace('/[-_\s]+/', '', $id));

        foreach ($items as $item) {
            $itemId = (string) ($item['id'] ?? '');
            $rawRef = (string) ($item['referenceNumber'] ?? $item['reference_number'] ?? '');
            $itemNormRef = strtolower(preg_replace('/[-_\s]+/', '', $rawRef));

            if (
                $itemId === $id ||
                $itemId === $cleanId ||
                $itemId === $prefixedId ||
                $rawRef === $id ||
                $rawRef === $cleanId ||
                ($normRef !== '' && $itemNormRef === $normRef)
            ) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Locate an existing inquiry using multiple layered fallback heuristics.
     * Prevents accidental duplication when syncing from Google Sheets or third-party webhooks.
     *
     * @param array<string, mixed> $criteria
     * @return array<string, mixed>|null
     */
    public function findMatchingInquiry(array $criteria): ?array
    {
        $id = trim((string) ($criteria['id'] ?? $criteria['inquiryId'] ?? $criteria['inquiry_id'] ?? ''));
        $ref = trim((string) ($criteria['referenceNumber'] ?? $criteria['reference_number'] ?? ''));
        $rawPhone = trim((string) ($criteria['contactNumber'] ?? $criteria['contact_number'] ?? $criteria['phone'] ?? ''));
        $rawPlate = trim((string) ($criteria['plateNumber'] ?? $criteria['plate_number'] ?? $criteria['plate'] ?? ''));
        $rawDate = trim((string) ($criteria['appointmentDate'] ?? $criteria['appointment_date'] ?? $criteria['date'] ?? ''));
        $rawEmail = strtolower(trim((string) ($criteria['emailAddress'] ?? $criteria['email_address'] ?? $criteria['email'] ?? '')));
        $rawName = trim((string) ($criteria['fullName'] ?? $criteria['full_name'] ?? $criteria['name'] ?? ''));

        // 1. Primary: By exact Inquiry ID.
        // Reference Number is intentionally excluded — it changes on every reschedule,
        // so using it as a lookup key causes duplicate inquiries after rebooking.
        if ($id !== '') {
            $found = $this->getById($id);
            if ($found !== null) return $found;
        }

        // Clean values for secondary matching
        $digits = preg_replace('/\D+/', '', $rawPhone);
        $phone10 = strlen($digits) >= 10 ? substr($digits, -10) : ($digits !== '' ? $digits : null);
        $plateNorm = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $rawPlate));
        $dateNorm = '';
        if ($rawDate !== '') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) {
                $dateNorm = $rawDate;
            } else {
                $ts = strtotime($rawDate);
                if ($ts !== false && $ts > 0) {
                    $dateNorm = date('Y-m-d', $ts);
                }
            }
        }
        $validEmail = (filter_var($rawEmail, FILTER_VALIDATE_EMAIL) && !str_ends_with($rawEmail, '@1625autolab.local')) ? $rawEmail : null;
        $nameClean = mb_strtolower(trim(preg_replace('/\s+/', ' ', $rawName)));

        if ($this->useDb) {
            $db = Database::getInstance();

            // 2. Normalized Plate + Appointment Date
            if ($plateNorm !== '' && $dateNorm !== '') {
                $stmt = $db->prepare(
                    'SELECT id FROM customer_inquiries 
                     WHERE REPLACE(REPLACE(UPPER(plate_number), " ", ""), "-", "") = :plate 
                       AND appointment_date = :dt 
                     LIMIT 1'
                );
                $stmt->execute([':plate' => $plateNorm, ':dt' => $dateNorm]);
                if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $found = $this->dbGetById((string) $row['id']);
                    if ($found !== null) return $found;
                }
            }

            // 3. Normalized Phone (last 10 digits) + Appointment Date
            if ($phone10 !== null && $phone10 !== '' && $dateNorm !== '') {
                $stmt = $db->prepare(
                    'SELECT id FROM customer_inquiries 
                     WHERE RIGHT(contact_number, 10) = :phone10 
                       AND appointment_date = :dt 
                     LIMIT 1'
                );
                $stmt->execute([':phone10' => $phone10, ':dt' => $dateNorm]);
                if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $found = $this->dbGetById((string) $row['id']);
                    if ($found !== null) return $found;
                }
            }

            // 4. Normalized Phone + Normalized Plate
            if ($phone10 !== null && $phone10 !== '' && $plateNorm !== '') {
                $stmt = $db->prepare(
                    'SELECT id FROM customer_inquiries 
                     WHERE RIGHT(contact_number, 10) = :phone10 
                       AND REPLACE(REPLACE(UPPER(plate_number), " ", ""), "-", "") = :plate 
                     LIMIT 1'
                );
                $stmt->execute([':phone10' => $phone10, ':plate' => $plateNorm]);
                if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $found = $this->dbGetById((string) $row['id']);
                    if ($found !== null) return $found;
                }
            }

            // 5. Normalized Phone + Customer Name (fuzzy name check)
            if ($phone10 !== null && $phone10 !== '' && mb_strlen($nameClean) >= 3) {
                $stmt = $db->prepare(
                    'SELECT id, full_name FROM customer_inquiries 
                     WHERE RIGHT(contact_number, 10) = :phone10 
                     ORDER BY created_at DESC'
                );
                $stmt->execute([':phone10' => $phone10]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $existingName = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) ($r['full_name'] ?? ''))));
                    if (
                        $existingName === $nameClean ||
                        str_contains($existingName, $nameClean) ||
                        str_contains($nameClean, $existingName) ||
                        levenshtein($existingName, $nameClean) <= 3
                    ) {
                        $found = $this->dbGetById((string) $r['id']);
                        if ($found !== null) return $found;
                    }
                }
            }

            // 6. Valid Email + Customer Name (fuzzy name check)
            if ($validEmail !== null && mb_strlen($nameClean) >= 3) {
                $stmt = $db->prepare(
                    'SELECT id, full_name FROM customer_inquiries 
                     WHERE LOWER(email_address) = :email 
                     ORDER BY created_at DESC'
                );
                $stmt->execute([':email' => $validEmail]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $existingName = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) ($r['full_name'] ?? ''))));
                    if (
                        $existingName === $nameClean ||
                        str_contains($existingName, $nameClean) ||
                        str_contains($nameClean, $existingName)
                    ) {
                        $found = $this->dbGetById((string) $r['id']);
                        if ($found !== null) return $found;
                    }
                }
            }

            // 7. Normalized Phone alone — if there's only one inquiry with this phone
            if ($phone10 !== null && $phone10 !== '') {
                $stmt = $db->prepare(
                    'SELECT id FROM customer_inquiries 
                     WHERE RIGHT(contact_number, 10) = :phone10 
                     ORDER BY created_at DESC'
                );
                $stmt->execute([':phone10' => $phone10]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($rows) === 1) {
                    $found = $this->dbGetById((string) $rows[0]['id']);
                    if ($found !== null) return $found;
                }
            }
        } else {
            // File storage fallback
            $all = $this->fileGetAll();
            foreach ($all as $item) {
                $itemPhoneDigits = preg_replace('/\D+/', '', (string)($item['contactNumber'] ?? ''));
                $itemPhone10 = strlen($itemPhoneDigits) >= 10 ? substr($itemPhoneDigits, -10) : $itemPhoneDigits;
                $itemPlateNorm = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($item['plateNumber'] ?? '')));
                $itemDate = (string)($item['appointmentDate'] ?? '');
                $itemNameClean = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string)($item['fullName'] ?? ''))));
                $itemEmail = strtolower(trim((string)($item['emailAddress'] ?? '')));

                if ($plateNorm !== '' && $dateNorm !== '' && $itemPlateNorm === $plateNorm && $itemDate === $dateNorm) {
                    return $item;
                }
                if ($phone10 !== null && $phone10 !== '' && $dateNorm !== '' && $itemPhone10 === $phone10 && $itemDate === $dateNorm) {
                    return $item;
                }
                if ($phone10 !== null && $phone10 !== '' && $plateNorm !== '' && $itemPhone10 === $phone10 && $itemPlateNorm === $plateNorm) {
                    return $item;
                }
                if ($phone10 !== null && $phone10 !== '' && $nameClean !== '' && $itemPhone10 === $phone10 && ($itemNameClean === $nameClean || str_contains($itemNameClean, $nameClean) || str_contains($nameClean, $itemNameClean))) {
                    return $item;
                }
                if ($validEmail !== null && $nameClean !== '' && $itemEmail === $validEmail && ($itemNameClean === $nameClean || str_contains($itemNameClean, $nameClean))) {
                    return $item;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function normalizePayload(array $data): array
    {
        $getValue = static function (array $data, array $keys): string {
            foreach ($keys as $key) {
                if (array_key_exists($key, $data) && $data[$key] !== null) {
                    return trim((string) $data[$key]);
                }
            }
            return '';
        };

        return [
            'fullName' => $getValue($data, ['fullName', 'full_name', 'Full Name']),
            'address' => $getValue($data, ['address', 'Address']),
            'contactNumber' => $getValue($data, ['contactNumber', 'contact_number', 'Contact Number']),
            'emailAddress' => $getValue($data, ['emailAddress', 'email_address', 'Email address', 'Email Address']),
            'facebookName' => $getValue($data, ['facebookName', 'facebook_name', 'Facebook Name']),
            'plateNumber' => $getValue($data, ['plateNumber', 'plate_number', 'Plate Number']),
            'make' => $getValue($data, ['make', 'Car Make']),
            'model' => $getValue($data, ['model', 'Car Model']),
            'yearModel' => $getValue($data, ['yearModel', 'year_model', 'Year Model']),
            'serviceType' => $getValue($data, ['serviceType', 'service_type', 'serviceLocation', 'service_location', 'serviceOption', 'service_option', 'Service Type', 'Service Location']) ?: 'shop_visit',
            'productToPurchase' => $getValue($data, ['productToPurchase', 'product_to_purchase', 'Product to Purchase']),
            'appointmentDate' => $getValue($data, ['appointmentDate', 'appointment_date', 'Appointment Date', 'bookingDate', 'booking_date']),
            'appointmentTime' => $getValue($data, ['appointmentTime', 'appointment_time', 'Appointment Time', 'bookingTime', 'booking_time']),
        ];
    }

    /**
     * @param array<string, string> $inquiry
     */
    private function validatePayload(array $inquiry): void
    {
        $required = [
            'fullName' => 'Full name is required.',
            'address' => 'Address is required.',
            'contactNumber' => 'Contact number is required.',
            'emailAddress' => 'Email address is required.',
            'facebookName' => 'Facebook name is required.',
            'make' => 'Car make is required.',
            'model' => 'Car model is required.',
            'yearModel' => 'Year model is required.',
            'productToPurchase' => 'Product or service is required.',
            'appointmentDate' => 'Appointment date is required.',
            'appointmentTime' => 'Appointment time is required.',
        ];

        foreach ($required as $field => $message) {
            if (trim((string) ($inquiry[$field] ?? '')) === '') {
                throw new RuntimeException($message, 422);
            }
        }

        if (!filter_var($inquiry['emailAddress'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid email address is required.', 422);
        }
    }

    /**
     * @param array<string, mixed> $inquiry
     */
    private function dbInsert(array $inquiry): void
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO customer_inquiries (
                id, reference_number, user_id, service_id, service_type, full_name, address, contact_number, email_address, facebook_name, plate_number,
                make, model, year_model, product_to_purchase, appointment_date,
                appointment_time, status, created_at, updated_at
            ) VALUES (
                :id, :reference_number, :user_id, :service_id, :service_type, :full_name, :address, :contact_number, :email_address, :facebook_name, :plate_number,
                :make, :model, :year_model, :product_to_purchase, :appointment_date,
                :appointment_time, :status, :created_at, :updated_at
            )'
        );

        $stmt->execute([
            ':id' => (string) $inquiry['id'],
            ':reference_number' => (string) ($inquiry['referenceNumber'] ?? $inquiry['reference_number'] ?? ''),
            ':user_id' => $inquiry['user_id'] ? (string) $inquiry['user_id'] : null,
            ':service_id' => $inquiry['service_id'] ?? null,
            ':service_type' => (string) ($inquiry['serviceType'] ?? $inquiry['service_type'] ?? 'shop_visit'),
            ':full_name' => (string) $inquiry['fullName'],
            ':address' => (string) $inquiry['address'],
            ':contact_number' => (string) $inquiry['contactNumber'],
            ':email_address' => (string) $inquiry['emailAddress'],
            ':facebook_name' => (string) $inquiry['facebookName'],
            ':make' => (string) $inquiry['make'],
            ':model' => (string) $inquiry['model'],
            ':year_model' => (string) $inquiry['yearModel'],
            ':product_to_purchase' => (string) $inquiry['productToPurchase'],
            ':plate_number' => (string) ($inquiry['plateNumber'] ?? ''),
            ':appointment_date' => (string) $inquiry['appointmentDate'],
            ':appointment_time' => (string) $inquiry['appointmentTime'],
            ':status' => (string) $inquiry['status'],
            ':created_at' => (string) $inquiry['createdAt'],
            ':updated_at' => (string) $inquiry['createdAt'],
        ]);
    }

    private function dbGetStats(?string $timeframe = null, ?string $from = null, ?string $to = null): array
    {
        $db = Database::getInstance();

        $whereClause = "";
        if ($timeframe === 'this_week') {
            $whereClause = "WHERE YEARWEEK(appointment_date, 1) = YEARWEEK(CURDATE(), 1)";
        } elseif ($timeframe === 'this_month') {
            $whereClause = "WHERE YEAR(appointment_date) = YEAR(CURDATE()) AND MONTH(appointment_date) = MONTH(CURDATE())";
        } elseif ($timeframe === 'custom' && $from && $to) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                $whereClause = "WHERE appointment_date >= '$from' AND appointment_date <= '$to'";
            }
        }

        $total = (int) $db->query("SELECT COUNT(*) FROM customer_inquiries $whereClause")->fetchColumn();

        $byStatus = $db->query(
            "SELECT status, COUNT(*) AS cnt FROM customer_inquiries $whereClause GROUP BY status"
        )->fetchAll(\PDO::FETCH_KEY_PAIR);

        $pending    = (int) ($byStatus['pending']     ?? 0);
        $confirmed  = (int) ($byStatus['confirmed']   ?? 0);
        $inProgress = (int) ($byStatus['in_progress'] ?? 0);
        $completed  = (int) ($byStatus['completed']   ?? 0);
        $cancelled  = (int) ($byStatus['cancelled']   ?? 0);

        $thisWeek = (int) $db->query(
            "SELECT COUNT(*) FROM customer_inquiries WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        )->fetchColumn();

        $thisMonth = (int) $db->query(
            "SELECT COUNT(*) FROM customer_inquiries WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
        )->fetchColumn();

        $todayInquiries = (int) $db->query(
            "SELECT COUNT(*) FROM customer_inquiries WHERE appointment_date = CURDATE()"
        )->fetchColumn();

        $todayPending = (int) $db->query(
            "SELECT COUNT(*) FROM customer_inquiries WHERE appointment_date = CURDATE() AND status IN ('pending','confirmed','in_progress')"
        )->fetchColumn();

        $peakWhere = $whereClause ? str_replace("WHERE", "AND", $whereClause) : "";
        $peakHours = $db->query(
            "SELECT appointment_time AS hour_label, COUNT(*) AS cnt
               FROM customer_inquiries
              WHERE status IN ('pending', 'confirmed', 'completed', 'in_progress')
              $peakWhere
              GROUP BY appointment_time
              ORDER BY cnt DESC
              LIMIT 8"
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        usort($peakHours, static function (array $a, array $b): int {
            $timeA = strtotime((string) ($a['hour_label'] ?? ''));
            $timeB = strtotime((string) ($b['hour_label'] ?? ''));

            if ($timeA === false && $timeB === false) return 0;
            if ($timeA === false) return 1;
            if ($timeB === false) return -1;
            return $timeA <=> $timeB;
        });
        
        $peakInquiryHours = array_map(fn($r) => [
            'time' => (string) $r['hour_label'],
            'count' => (int) $r['cnt']
        ], $peakHours);

        $topInquiriesWhere = $whereClause ? str_replace("appointment_date", "ci.appointment_date", $whereClause) : "";
        $topInquiriesWhereWithAnd = $topInquiriesWhere ? $topInquiriesWhere . " AND ci.service_id IS NOT NULL" : "WHERE ci.service_id IS NOT NULL";
        $topInquiries = $db->query(
            "SELECT s.title AS service_name, COUNT(*) AS cnt
               FROM customer_inquiries ci
               JOIN services s ON s.id = ci.service_id
               $topInquiriesWhereWithAnd
              GROUP BY ci.service_id, s.title
              ORDER BY cnt DESC
              LIMIT 10"
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $topInquiryServices = [];
        foreach ($topInquiries as $r) {
            $name = trim((string) ($r['service_name'] ?? ''));
            if ($name !== '') {
                $topInquiryServices[] = [
                    'name' => $name,
                    'count' => (int) $r['cnt']
                ];
            }
        }

        return [
            'totalInquiries'        => $total,
            'pendingInquiries'      => $pending,
            'confirmedInquiries'    => $confirmed,
            'inProgressInquiries'   => $inProgress,
            'completedInquiries'    => $completed,
            'cancelledInquiries'    => $cancelled,
            'activeInquiries'       => $pending + $confirmed + $inProgress,
            'inquiriesThisWeek'     => $thisWeek,
            'inquiriesThisMonth'    => $thisMonth,
            'todayInquiries'        => $todayInquiries,
            'todayPendingInquiries' => $todayPending,
            'peakInquiryHours'      => $peakInquiryHours,
            'topInquiryServices'    => $topInquiryServices,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function dbGetAll(): array
    {
        $db = Database::getInstance();
        $stmt = $db->query(
                'SELECT id, reference_number, user_id, service_id, service_type, full_name, address, contact_number, email_address, facebook_name, plate_number,
                    make, model, year_model, product_to_purchase, appointment_date,
                    appointment_time, status, internal_notes, created_at
                 FROM customer_inquiries
             ORDER BY appointment_date ASC, appointment_time ASC, created_at DESC'
        );

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn (array $row): array => $this->mapDbRow($row), $rows);
    }

    /**
     * @param string $id
     * @param string|null $status
     * @param string|null $appointmentDate
     * @param string|null $appointmentTime
     */
    private function dbUpdateDetails(string $id, ?string $status, ?string $appointmentDate, ?string $appointmentTime): void
    {
        $db = Database::getInstance();
        $fields = ['updated_at = CURRENT_TIMESTAMP'];
        $params = [':id' => $id];

        if ($status !== null) {
            $fields[] = 'status = :status';
            $params[':status'] = $status;
        }

        if ($appointmentDate !== null) {
            $fields[] = 'appointment_date = :appointment_date';
            $params[':appointment_date'] = $appointmentDate;

            // Update reference number to reflect rescheduled appointment date (1625_DDMM_0000)
            $newRef = $this->generateReferenceNumber($appointmentDate);
            $fields[] = 'reference_number = :new_reference_number';
            $params[':new_reference_number'] = $newRef;
        }

        if ($appointmentTime !== null) {
            $fields[] = 'appointment_time = :appointment_time';
            $params[':appointment_time'] = $appointmentTime;
        }
        // allow updating plate number as part of details update
        if (array_key_exists(':plate_number', $params) || array_key_exists('plateNumber', $params)) {
            // noop here; update route will need to pass plateNumber explicitly when needed
        }

        $stmt = $db->prepare(
            'UPDATE customer_inquiries
             SET ' . implode(', ', $fields) . '
             WHERE id = :id'
        );
        $stmt->execute($params);
    }

    /**
     * @param string $id
     */
    private function dbDelete(string $id): void
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('DELETE FROM customer_inquiries WHERE id = :id');
        $stmt->execute([':id' => $id]);

        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Inquiry not found.', 404);
        }
    }

    /**
     * @param string $id
     * @return array<string, mixed>|null
     */
    private function dbGetById(string $id): ?array
    {
        $db = Database::getInstance();
        $cleanId = str_starts_with($id, 'inq-') ? substr($id, 4) : $id;
        $prefixedId = str_starts_with($id, 'inq-') ? $id : 'inq-' . $id;
        $dashRef = str_replace('_', '-', $id);
        $underRef = str_replace('-', '_', $id);
        $cleanDashRef = str_replace('_', '-', $cleanId);
        $cleanUnderRef = str_replace('-', '_', $cleanId);
        $normRef = strtolower(preg_replace('/[-_\s]+/', '', $id));

        $stmt = $db->prepare(
            'SELECT id, reference_number, user_id, service_id, service_type, full_name, address, contact_number, email_address, facebook_name, plate_number,
                make, model, year_model, product_to_purchase, appointment_date,
                appointment_time, status, internal_notes, created_at
             FROM customer_inquiries
             WHERE id = :id 
                OR id = :cleanId 
                OR id = :prefixedId 
                OR reference_number = :ref 
                OR reference_number = :cleanRef
                OR reference_number = :dashRef
                OR reference_number = :underRef
                OR reference_number = :cleanDashRef
                OR reference_number = :cleanUnderRef
                OR LOWER(REPLACE(REPLACE(REPLACE(reference_number, "-", ""), "_", ""), " ", "")) = :normRef
             LIMIT 1'
        );
        $stmt->execute([
            ':id'            => $id,
            ':cleanId'       => $cleanId,
            ':prefixedId'    => $prefixedId,
            ':ref'           => $id,
            ':cleanRef'      => $cleanId,
            ':dashRef'       => $dashRef,
            ':underRef'      => $underRef,
            ':cleanDashRef'  => $cleanDashRef,
            ':cleanUnderRef' => $cleanUnderRef,
            ':normRef'       => $normRef,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->mapDbRow($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapDbRow(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'referenceNumber' => (string) ($row['reference_number'] ?? ''),
            'userId' => $row['user_id'] ? (string) $row['user_id'] : null,
            'serviceId' => isset($row['service_id']) && $row['service_id'] !== null ? (int) $row['service_id'] : null,
            'serviceType' => (string) ($row['service_type'] ?? 'shop_visit'),
            'service_type' => (string) ($row['service_type'] ?? 'shop_visit'),
            'fullName' => (string) ($row['full_name'] ?? ''),
            'address' => (string) ($row['address'] ?? ''),
            'contactNumber' => (string) ($row['contact_number'] ?? ''),
            'emailAddress' => (string) ($row['email_address'] ?? ''),
            'facebookName' => (string) ($row['facebook_name'] ?? ''),
            'plateNumber' => (string) ($row['plate_number'] ?? ''),
            'make' => (string) ($row['make'] ?? ''),
            'model' => (string) ($row['model'] ?? ''),
            'yearModel' => (string) ($row['year_model'] ?? ''),
            'productToPurchase' => (string) ($row['product_to_purchase'] ?? ''),
            'appointmentDate' => (string) ($row['appointment_date'] ?? ''),
            'appointmentTime' => (string) ($row['appointment_time'] ?? ''),
            'status' => (string) ($row['status'] ?? 'pending'),
            'internalNotes' => $row['internal_notes'] ?? null,
            'createdAt' => (string) ($row['created_at'] ?? ''),
        ];
    }

    public function generateReferenceNumber(?string $appointmentDate = null): string
    {
        $dd = date('d');
        $mm = date('m');
        $yy = date('y');
        if ($appointmentDate !== null && trim($appointmentDate) !== '') {
            $ts = strtotime($appointmentDate);
            if ($ts !== false) {
                $dd = date('d', $ts);
                $mm = date('m', $ts);
                $yy = date('y', $ts);
            }
        }
        $prefixDash  = "1625-{$dd}{$mm}{$yy}-";
        $prefixUnder = "1625_{$dd}{$mm}{$yy}_";

        $nextSeq = 1;
        if ($this->useDb) {
            $db = Database::getInstance();
            $stmt = $db->prepare('SELECT reference_number FROM customer_inquiries WHERE reference_number LIKE :p1 OR reference_number LIKE :p2 ORDER BY id DESC LIMIT 1');
            $stmt->execute([':p1' => $prefixDash . '%', ':p2' => $prefixUnder . '%']);
            $lastRef = $stmt->fetchColumn();
            if ($lastRef) {
                $parts = preg_split('/[-_]/', (string) $lastRef);
                $lastSeq = (int) end($parts);
                $nextSeq = $lastSeq + 1;
            }
        } else {
            $items = $this->fileGetAll();
            $maxSeq = 0;
            foreach ($items as $item) {
                $ref = (string) ($item['referenceNumber'] ?? $item['reference_number'] ?? '');
                if (str_starts_with($ref, $prefixDash) || str_starts_with($ref, $prefixUnder)) {
                    $parts = preg_split('/[-_]/', $ref);
                    $seq = (int) end($parts);
                    if ($seq > $maxSeq) {
                        $maxSeq = $seq;
                    }
                }
            }
            $nextSeq = $maxSeq + 1;
        }

        return $prefixDash . sprintf('%04d', $nextSeq);
    }

    public function ensureReferenceNumbers(): void
    {
        if (!$this->useDb) {
            return;
        }
        try {
            $db = Database::getInstance();
            $colCheck = $db->query("SHOW COLUMNS FROM customer_inquiries LIKE 'reference_number'");
            if (!$colCheck->fetch()) {
                return;
            }
            $stmt = $db->query("SELECT id, appointment_date, created_at FROM customer_inquiries WHERE reference_number IS NULL OR reference_number = '' ORDER BY created_at ASC");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($rows)) {
                return;
            }
            foreach ($rows as $row) {
                $id = (string) $row['id'];
                $ts = !empty($row['appointment_date']) ? strtotime($row['appointment_date']) : strtotime($row['created_at'] ?? 'now');
                if ($ts === false) $ts = time();
                $refNum = $this->generateReferenceNumber(date('Y-m-d', $ts));
                $stmtUpd = $db->prepare('UPDATE customer_inquiries SET reference_number = :ref WHERE id = :id');
                $stmtUpd->execute([':ref' => $refNum, ':id' => $id]);
            }
        } catch (Exception $e) {
            // Ignore if schema not migrated yet
        }
    }

    /**
     * @param array<string, mixed> $inquiry
     */
    private function syncOccupancyForInquiry(array $inquiry): void
    {
        $inquiryId = (string) ($inquiry['id'] ?? '');
        if ($inquiryId === '') {
            return;
        }

        $appointmentDate = trim((string) ($inquiry['appointmentDate'] ?? ''));
        $appointmentTime = trim((string) ($inquiry['appointmentTime'] ?? ''));
        $status = strtolower(trim((string) ($inquiry['status'] ?? 'pending')));

        if ($appointmentDate === '' || $appointmentTime === '' || $status === 'cancelled') {
            $this->deleteOccupancyForInquiry($inquiryId);
            return;
        }

        if ($this->useDb) {
            $this->dbUpsertOccupancy($inquiry);
            return;
        }

        $this->fileUpsertOccupancy($inquiry);
    }

    private function deleteOccupancyForInquiry(string $inquiryId): void
    {
        if ($inquiryId === '') {
            return;
        }

        if ($this->useDb) {
            $this->dbDeleteOccupancy($inquiryId);
            return;
        }

        $rows = $this->fileGetOccupancyRows();
        $filtered = array_values(array_filter(
            $rows,
            static fn (array $item): bool => (string) ($item['inquiryId'] ?? '') !== $inquiryId
        ));

        $this->fileWriteOccupancyRows($filtered);
    }

    /**
     * @param array<string, mixed> $inquiry
     */
    private function dbUpsertOccupancy(array $inquiry): void
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO inquiry_slot_occupancy (
                id, inquiry_id, appointment_date, appointment_time, status, created_at, updated_at
            ) VALUES (
                :id, :inquiry_id, :appointment_date, :appointment_time, :status, :created_at, :updated_at
            ) ON DUPLICATE KEY UPDATE
                appointment_date = VALUES(appointment_date),
                appointment_time = VALUES(appointment_time),
                status = VALUES(status),
                updated_at = VALUES(updated_at)'
        );

        $stmt->execute([
            ':id' => (string) $this->uuid(),
            ':inquiry_id' => (string) ($inquiry['id'] ?? ''),
            ':appointment_date' => trim((string) ($inquiry['appointmentDate'] ?? '')),
            ':appointment_time' => trim((string) ($inquiry['appointmentTime'] ?? '')),
            ':status' => strtolower(trim((string) ($inquiry['status'] ?? 'pending'))),
            ':created_at' => date('Y-m-d H:i:s'),
            ':updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function dbDeleteOccupancy(string $inquiryId): void
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('DELETE FROM inquiry_slot_occupancy WHERE inquiry_id = :inquiry_id');
        $stmt->execute([':inquiry_id' => $inquiryId]);
    }

    /**
     * @return string[]
     */
    private function dbGetOccupiedSlots(string $date): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT appointment_time FROM inquiry_slot_occupancy WHERE appointment_date = :appointment_date'
        );
        $stmt->execute([':appointment_date' => $date]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $slots = array_values(array_filter(array_map(
            static fn (array $row): string => trim((string) ($row['appointment_time'] ?? '')),
            $rows
        ), static fn (string $slot): bool => $slot !== ''));
        sort($slots);
        return $slots;
    }

    /**
     * @return array<string, int>
     */
    private function dbGetSlotCounts(string $date): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT appointment_time, COUNT(*) AS slot_count FROM inquiry_slot_occupancy WHERE appointment_date = :appointment_date GROUP BY appointment_time'
        );
        $stmt->execute([':appointment_date' => $date]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $counts = [];
        foreach ($rows as $row) {
            $time = trim((string) ($row['appointment_time'] ?? ''));
            if ($time !== '') {
                $counts[$time] = (int) ($row['slot_count'] ?? 0);
            }
        }
        return $counts;
    }

    /**
     * @param array<string, mixed> $inquiry
     */
    private function fileUpsertOccupancy(array $inquiry): void
    {
        $rows = $this->fileGetOccupancyRows();
        $inquiryId = (string) ($inquiry['id'] ?? '');
        $appointmentDate = trim((string) ($inquiry['appointmentDate'] ?? ''));
        $appointmentTime = trim((string) ($inquiry['appointmentTime'] ?? ''));
        $status = strtolower(trim((string) ($inquiry['status'] ?? 'pending')));

        if ($appointmentDate === '' || $appointmentTime === '' || $status === 'cancelled') {
            $this->deleteOccupancyForInquiry($inquiryId);
            return;
        }

        $found = false;
        foreach ($rows as &$row) {
            if ((string) ($row['inquiryId'] ?? '') === $inquiryId) {
                $row['appointmentDate'] = $appointmentDate;
                $row['appointmentTime'] = $appointmentTime;
                $row['status'] = $status;
                $row['updatedAt'] = date('c');
                $found = true;
                break;
            }
        }
        unset($row);

        if (!$found) {
            $rows[] = [
                'id' => $this->uuid(),
                'inquiryId' => $inquiryId,
                'appointmentDate' => $appointmentDate,
                'appointmentTime' => $appointmentTime,
                'status' => $status,
                'createdAt' => date('c'),
                'updatedAt' => date('c'),
            ];
        }

        $this->fileWriteOccupancyRows($rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fileGetOccupancyRows(): array
    {
        if (!file_exists(self::$occupancyStorageFile)) {
            return [];
        }

        $raw = file_get_contents(self::$occupancyStorageFile);
        if ($raw === false) {
            return [];
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function fileWriteOccupancyRows(array $rows): void
    {
        $dir = dirname(self::$occupancyStorageFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        file_put_contents(self::$occupancyStorageFile, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return string[]
     */
    private function fileGetOccupiedSlots(string $date): array
    {
        $slots = array_values(array_filter(
            array_map(
                static fn (array $row): string => (string) ($row['appointmentTime'] ?? ''),
                array_filter(
                    $this->fileGetOccupancyRows(),
                    static fn (array $row): bool => (string) ($row['appointmentDate'] ?? '') === $date
                )
            ),
            static fn (string $slot): bool => $slot !== ''
        ));
        sort($slots);
        return $slots;
    }

    /**
     * @return array<string, int>
     */
    private function fileGetSlotCounts(string $date): array
    {
        $counts = [];
        foreach ($this->fileGetOccupancyRows() as $row) {
            if ((string) ($row['appointmentDate'] ?? '') !== $date) {
                continue;
            }
            $time = trim((string) ($row['appointmentTime'] ?? ''));
            if ($time === '') {
                continue;
            }
            $counts[$time] = ($counts[$time] ?? 0) + 1;
        }
        return $counts;
    }

    /**
     * @param array<string, mixed> $inquiry
     */
    private function fileInsert(array $inquiry): void
    {
        $path = dirname(self::$storageFile);
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }

        $items = $this->fileGetAll();
        $items[] = $inquiry;
        file_put_contents(self::$storageFile, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function fileGetStats(?string $timeframe = null, ?string $from = null, ?string $to = null): array
    {
        $all = $this->fileGetAll();

        if ($timeframe === 'custom' && $from && $to) {
            $all = array_filter($all, function($b) use ($from, $to) {
                $date = $b['appointmentDate'] ?? '';
                return $date >= $from && $date <= $to;
            });
        } elseif ($timeframe === 'this_week') {
            $all = array_filter($all, function($b) {
                $date = new \DateTime($b['appointmentDate'] ?? '');
                $start = (new \DateTime('monday this week'))->format('Y-m-d');
                $end = (new \DateTime('sunday this week'))->format('Y-m-d');
                return $date->format('Y-m-d') >= $start && $date->format('Y-m-d') <= $end;
            });
        } elseif ($timeframe === 'this_month') {
            $all = array_filter($all, function($b) {
                $date = new \DateTime($b['appointmentDate'] ?? '');
                return $date->format('Y-m') === (new \DateTime())->format('Y-m');
            });
        }

        $weekAgo    = new \DateTime('-7 days');
        $monthStart = new \DateTime('first day of this month midnight');

        $pending   = 0;
        $confirmed = 0;
        $inProgress= 0;
        $completed = 0;
        $cancelled = 0;
        $thisWeek  = 0;
        $thisMonth = 0;
        $todayInquiries = 0;
        $todayPending  = 0;
        $todayIso = (new \DateTime('today'))->format('Y-m-d');

        $peakHourCounts = [];
        foreach ($all as $b) {
            $status = (string) ($b['status'] ?? '');

            switch ($status) {
                case 'pending':     $pending++;     break;
                case 'confirmed':   $confirmed++;   break;
                case 'in_progress': $inProgress++;  break;
                case 'completed':   $completed++;   break;
                case 'cancelled':   $cancelled++;   break;
            }

            $created = new \DateTime($b['createdAt'] ?? 'now');
            if ($created >= $weekAgo)    $thisWeek++;
            if ($created >= $monthStart) $thisMonth++;

            $appointmentDate = (string) ($b['appointmentDate'] ?? '');
            if ($appointmentDate === $todayIso) {
                $todayInquiries++;
                if (in_array($status, ['pending', 'confirmed', 'in_progress'], true)) {
                    $todayPending++;
                }
            }
            
            $serviceId = $b['serviceId'] ?? $b['service_id'] ?? null;
            $serviceName = trim((string) ($b['serviceName'] ?? $b['service_name'] ?? ''));
            if ($serviceId || $serviceName !== '') {
                $serviceLabel = $serviceName !== '' ? $serviceName : "Service #$serviceId";
                $topServiceCounts[$serviceLabel] = ($topServiceCounts[$serviceLabel] ?? 0) + 1;
            }

            $timeLabel = trim((string) ($b['appointmentTime'] ?? ''));
            if ($timeLabel !== '' && in_array($status, ['pending', 'confirmed', 'completed', 'in_progress'], true)) {
                $peakHourCounts[$timeLabel] = ($peakHourCounts[$timeLabel] ?? 0) + 1;
            }
        }

        arsort($topServiceCounts);
        $topServiceCounts = array_slice($topServiceCounts, 0, 10, true);
        $topInquiryServices = [];
        foreach ($topServiceCounts as $name => $count) {
            $topInquiryServices[] = ['name' => (string) $name, 'count' => (int) $count];
        }

        arsort($peakHourCounts);
        $peakHourCounts = array_slice($peakHourCounts, 0, 8, true);

        $peakInquiryHours = [];
        foreach ($peakHourCounts as $time => $count) {
            $peakInquiryHours[] = ['time' => (string) $time, 'count' => (int) $count];
        }

        usort($peakInquiryHours, static function (array $a, array $b): int {
            $timeA = strtotime((string) ($a['time'] ?? ''));
            $timeB = strtotime((string) ($b['time'] ?? ''));

            if ($timeA === false && $timeB === false) return 0;
            if ($timeA === false) return 1;
            if ($timeB === false) return -1;
            return $timeA <=> $timeB;
        });

        return [
            'totalInquiries'        => count($all),
            'pendingInquiries'      => $pending,
            'confirmedInquiries'    => $confirmed,
            'inProgressInquiries'   => $inProgress,
            'completedInquiries'    => $completed,
            'cancelledInquiries'    => $cancelled,
            'activeInquiries'       => $pending + $confirmed + $inProgress,
            'inquiriesThisWeek'     => $thisWeek,
            'inquiriesThisMonth'    => $thisMonth,
            'todayInquiries'        => $todayInquiries,
            'todayPendingInquiries' => $todayPending,
            'peakInquiryHours'      => $peakInquiryHours,
            'topInquiryServices'    => $topInquiryServices,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fileGetAll(): array
    {
        if (!file_exists(self::$storageFile)) {
            return [];
        }

        $raw = file_get_contents(self::$storageFile);
        if ($raw === false) {
            return [];
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function assertSlotCapacity(string $date, string $time, ?string $excludeInquiryId = null): void
    {
        if ($date === '' || $time === '') {
            return;
        }

        $count = $this->countOverlappingAppointments($date, $time, $excludeInquiryId);
        if ($count >= self::SLOT_CAPACITY) {
            throw new RuntimeException('This time slot is fully booked. Please choose a different time.', 409);
        }
    }

    /**
     * @return array<int, array{startMinutes:int}>
     */
    private function getActiveAppointmentsForDate(string $date): array
    {
        $items = $this->useDb ? $this->dbGetAll() : $this->fileGetAll();
        $appointments = [];

        foreach ($items as $item) {
            $itemDate = trim((string) ($item['appointmentDate'] ?? ''));
            if ($itemDate !== $date) {
                continue;
            }

            $status = strtolower(trim((string) ($item['status'] ?? 'pending')));
            if ($status === 'cancelled' || $status === 'completed') {
                continue;
            }

            $itemTime = trim((string) ($item['appointmentTime'] ?? ''));
            $startMinutes = $this->parseTimeToMinutes($itemTime);
            if ($startMinutes === null) {
                continue;
            }

            $appointments[] = [
                'inquiryId' => (string) ($item['id'] ?? ''),
                'startMinutes' => $startMinutes,
            ];
        }

        return $appointments;
    }

    private function countOverlappingAppointments(string $date, string $time, ?string $excludeInquiryId = null): int
    {
        $candidateStart = $this->parseTimeToMinutes($time);
        if ($candidateStart === null) {
            return 0;
        }

        $appointments = $this->getActiveAppointmentsForDate($date);
        $count = 0;
        foreach ($appointments as $appointment) {
            if ($excludeInquiryId !== null && isset($appointment['inquiryId']) && (string) $appointment['inquiryId'] === $excludeInquiryId) {
                continue;
            }
            if ($this->appointmentsOverlap($candidateStart, $appointment['startMinutes'])) {
                $count++;
            }
        }

        return $count;
    }

    private function appointmentsOverlap(int $candidateStart, int $existingStart): bool
    {
        $candidateEnd = $candidateStart + self::SLOT_WINDOW_MINUTES;
        $existingEnd = $existingStart + self::SLOT_WINDOW_MINUTES;
        return $candidateStart < $existingEnd && $existingStart < $candidateEnd;
    }

    private function getAllSlotsForDate(string $date): array
    {
        $shopHoursService = new ShopHoursService();
        $dayHours = $shopHoursService->getForDate($date);
        return $shopHoursService->generateSlots($dayHours);
    }

    private function parseTimeToMinutes(string $value): ?int
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^(\d{1,2}):(\d{2})(?:\s*([ap]\.?m\.?))?$/i', $normalized, $matches) !== 1) {
            return null;
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];
        $meridiem = isset($matches[3]) ? strtolower($matches[3]) : null;

        if ($meridiem === 'p' || $meridiem === 'pm') {
            if ($hours < 12) {
                $hours += 12;
            }
        } elseif ($meridiem === 'a' || $meridiem === 'am') {
            if ($hours === 12) {
                $hours = 0;
            }
        }

        if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
            return null;
        }

        return $hours * 60 + $minutes;
    }

    private function fileGetById(string $id): ?array
    {
        $items = $this->fileGetAll();
        foreach ($items as $item) {
            if ((string) ($item['id'] ?? '') === $id) {
                return $item;
            }
        }
        return null;
    }

    private function uuid(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Build a friendly, human-readable summary of what changed for the activity log.
     *
     * Produces sentences like "Status updated to: Confirmed" that non-technical
     * staff can read at a glance in the inquiry timeline.
     *
     * @param array<string, mixed> $existing       The inquiry snapshot before saving.
     * @param array<string, mixed> $fieldsToUpdate Snake_case col => new value map.
     * @return string  e.g. "Status updated to: Confirmed. Appointment changed to: Aug 15, 2026 at 9:00 AM."
     */
    private function buildFieldDiff(array $existing, array $fieldsToUpdate): string
    {
        // snake_case column -> camelCase key in $existing
        $colToCamel = [
            'full_name'           => 'fullName',
            'email_address'       => 'emailAddress',
            'contact_number'      => 'contactNumber',
            'facebook_name'       => 'facebookName',
            'address'             => 'address',
            'make'                => 'make',
            'model'               => 'model',
            'year_model'          => 'yearModel',
            'plate_number'        => 'plateNumber',
            'product_to_purchase' => 'productToPurchase',
            'service_id'          => 'serviceId',
            'service_type'        => 'serviceType',
            'status'              => 'status',
            'internal_notes'      => 'internalNotes',
            'appointment_date'    => 'appointmentDate',
            'appointment_time'    => 'appointmentTime',
        ];

        // Human-friendly label per column
        $labels = [
            'full_name'           => 'Name',
            'email_address'       => 'Email',
            'contact_number'      => 'Contact number',
            'facebook_name'       => 'Facebook name',
            'address'             => 'Address',
            'make'                => 'Vehicle make',
            'model'               => 'Vehicle model',
            'year_model'          => 'Year model',
            'plate_number'        => 'Plate number',
            'product_to_purchase' => 'Product / service',
            'service_id'          => 'Assigned service',
            'service_type'        => 'Service type',
            'status'              => 'Status',
            'internal_notes'      => 'Internal notes',
            'appointment_date'    => 'Appointment date',
            'appointment_time'    => 'Appointment time',
        ];

        // Pretty-print status values
        $statusLabels = [
            'pending'     => 'Pending',
            'confirmed'   => 'Confirmed',
            'in_progress' => 'In Progress',
            'completed'   => 'Completed',
            'cancelled'   => 'Cancelled',
        ];

        $parts = [];

        // Handle appointment date + time together for a nicer sentence
        $dateChanged = isset($fieldsToUpdate['appointment_date']);
        $timeChanged = isset($fieldsToUpdate['appointment_time']);

        foreach ($fieldsToUpdate as $col => $newVal) {
            // Merge date & time into one readable sentence
            if ($col === 'appointment_date' || $col === 'appointment_time') {
                if ($col === 'appointment_date') {
                    $newDate = (string) ($fieldsToUpdate['appointment_date'] ?? $existing['appointmentDate'] ?? '');
                    $newTime = (string) ($fieldsToUpdate['appointment_time'] ?? $existing['appointmentTime'] ?? '');

                    $oldDate = (string) ($existing['appointmentDate'] ?? '');
                    $oldTime = (string) ($existing['appointmentTime'] ?? '');

                    $fmtDate = fn(string $d): string => $d !== ''
                        ? date('M j, Y', strtotime($d))
                        : $d;
                    $fmtTime = fn(string $t): string => $t !== ''
                        ? date('g:i A', strtotime($t))
                        : $t;

                    $oldFormatted = trim($fmtDate($oldDate) . ' at ' . $fmtTime($oldTime), ' at');
                    $newFormatted = trim($fmtDate($newDate) . ' at ' . $fmtTime($newTime), ' at');

                    if ($oldFormatted !== $newFormatted) {
                        $parts[] = "Appointment changed to: {$newFormatted}";
                    }
                }
                // Skip time-only — already handled above
                continue;
            }

            $camel  = $colToCamel[$col] ?? $col;
            $oldVal = (string) ($existing[$camel] ?? $existing[$col] ?? '');
            $newStr = (string) ($newVal ?? '');

            if ($oldVal === $newStr) {
                continue;
            }

            $label = $labels[$col] ?? ucfirst(str_replace('_', ' ', $col));

            if ($col === 'status') {
                $newDisplay = $statusLabels[$newStr] ?? ucfirst($newStr);
                $parts[] = "Status updated to: {$newDisplay}";
            } elseif ($col === 'internal_notes') {
                $parts[] = 'Internal notes updated';
            } elseif ($col === 'service_id') {
                $parts[] = $newStr !== '' && $newStr !== '0'
                    ? "Service assigned (ID: {$newStr})"
                    : 'Service assignment removed';
            } else {
                $parts[] = "{$label} updated to: {$newStr}";
            }
        }

        return implode('. ', $parts);
    }
}
