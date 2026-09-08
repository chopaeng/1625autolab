<?php

declare(strict_types=1);

/**
 * SiteSettingsService
 *
 * Key-value store for site-wide settings such as company description,
 * about page content, etc.
 *
 * When DB_NAME is empty it falls back to backend/storage/site_settings.json.
 *
 * Requires migration 011_create_site_settings.sql to have been run.
 */
class SiteSettingsService
{
    private bool $useDb;
    private static string $storageFile = __DIR__ . '/../storage/site_settings.json';

    public function __construct()
    {
        $this->useDb = DB_NAME !== '';
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Return all settings as key => value map.
     *
     * @return array<string, string|null>
     */
    public function getAll(): array
    {
        return $this->useDb ? $this->dbGetAll() : $this->fileGetAll();
    }

    /**
     * Update one or more settings. Keys not in $data are left unchanged.
     *
     * @param  array<string, string|null> $data
     * @return array<string, string|null>
     */
    public function update(array $data): array
    {
        $before = $this->getAll();
        $after = $this->useDb ? $this->dbUpdate($data) : $this->fileUpdate($data);

        $changedKeys = [];
        foreach ($after as $key => $value) {
            $beforeValue = $before[$key] ?? null;
            if ((string) ($beforeValue ?? '') !== (string) ($value ?? '')) {
                $changedKeys[] = (string) $key;
            }
        }

        if ($changedKeys !== []) {
            $this->logSiteSettingsActivity(ActivityEvents::SITE_SETTINGS_UPDATED, [
                'changedKeys' => $changedKeys,
            ]);
        }

        return $after;
    }

    // -------------------------------------------------------------------------
    // DB
    // -------------------------------------------------------------------------

    /** @return array<string, string|null> */
    private function dbGetAll(): array
    {
        $stmt = Database::getInstance()->query('SELECT `key`, `value` FROM site_settings');
        $rows = $stmt->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['key']] = $row['value'];
        }
        return $result;
    }

    /** @param array<string, string|null> $data @return array<string, string|null> */
    private function dbUpdate(array $data): array
    {
        $before = $this->dbGetAll();

        $db   = Database::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO site_settings (`key`, `value`) VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
        );
        foreach ($data as $key => $value) {
            $stmt->execute([':key' => $key, ':value' => $value]);
        }

        $after = $this->dbGetAll();
        $this->cleanupRemovedImageSettingUrls($before, $after);

        return $after;
    }

    // -------------------------------------------------------------------------
    // File storage – fallback
    // -------------------------------------------------------------------------

    /** @return array<string, string|null> */
    private function fileGetAll(): array
    {
        if (!file_exists(self::$storageFile)) {
            return $this->defaultSettings();
        }
        $data = json_decode((string) file_get_contents(self::$storageFile), true);
        return is_array($data) ? $data : $this->defaultSettings();
    }

    /** @param array<string, string|null> $data @return array<string, string|null> */
    private function fileUpdate(array $data): array
    {
        $current = $this->fileGetAll();
        $merged  = array_merge($current, $data);
        $dir     = dirname(self::$storageFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            self::$storageFile,
            json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        $this->cleanupRemovedImageSettingUrls($current, $merged);

        return $merged;
    }

    /**
     * @param array<string, string|null> $before
     * @param array<string, string|null> $after
     */
    private function cleanupRemovedImageSettingUrls(array $before, array $after): void
    {
        $storage = new UploadStorage();

        foreach ($before as $key => $oldValue) {
            if (!$this->isImageSettingKey($key)) {
                continue;
            }

            $oldUrl = trim((string) ($oldValue ?? ''));
            $newUrl = trim((string) ($after[$key] ?? ''));
            if ($oldUrl === '' || $oldUrl === $newUrl) {
                continue;
            }

            try {
                $storage->deleteByUrl($oldUrl);
            } catch (\Throwable) {
                // Keep settings update successful even if cleanup fails.
            }
        }
    }

    private function isImageSettingKey(string $key): bool
    {
        return str_ends_with($key, 'image_url') || str_contains($key, 'image_url_');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return array<string, string> */
    private function defaultSettings(): array
    {
        return [
            'about_heading'         => 'Built on Precision. Driven by Passion.',
            'company_description_1' => 'Founded in 2018, 1625 Auto Lab started as a small garage operation focused on fixing poorly done headlight retrofits. Today, we are Los Angeles\' premier destination for high-end automotive electronics and premium vehicle upgrades.',
            'company_description_2' => 'We believe that your vehicle is an extension of your personality. Our mission is to provide unparalleled craftsmanship, using only the highest quality components, to turn your automotive vision into reality.',
            'company_phones'        => "0939 330 8263\n+639393308263",
            'company_emails'        => "1625autolab@gmail.com\nservice@1625autolab.com",
            'about_image_url'       => 'https://images.unsplash.com/photo-1632823471565-1ec2a74b45b4?q=80&w=2070&auto=format&fit=crop',
            // Footer settings
            'footer_tagline'   => 'The premier automotive retrofitting shop. We turn ordinary vehicles into extraordinary machines.',
            'footer_address'   => "NKKS Arcade, Krystal Homes, Brgy. Alasas\nPampanga, San Fernando, Philippines, 2000",
            'footer_phone'     => '0939 330 8263',
            'footer_phones'    => "0939 330 8263\n+639393308263",
            'footer_email'     => '1625autolab@gmail.com',
            'footer_emails'    => "1625autolab@gmail.com\nservice@1625autolab.com",
            'footer_instagram' => 'https://www.instagram.com/1625autolab',
            'footer_facebook'  => 'https://www.facebook.com/1625autolab/',
            'footer_youtube'   => '',
            // Map / location settings
            'map_embed_url'    => 'https://www.openstreetmap.org/export/embed.html?bbox=120.6699%2C15.0086%2C120.7099%2C15.0486&layer=mapnik&marker=15.0286%2C120.6899',
            'map_link_url'     => 'https://www.openstreetmap.org/?mlat=15.0286&mlon=120.6899#map=15/15.0286/120.6899',
            // Booking & Security settings
            'slot_capacity'    => '3',
            'booking_horizon_weeks' => '2',
            'staff_can_view_all_bookings' => '0',
            'staff_can_manage_all_bookings' => '0',
            'disable_registration' => '0',
            'shop_enabled' => '1',
            'google_sheets_webhook_url' => 'https://script.google.com/macros/s/AKfycbxgwLpuVCHKIqTTnOukQRWaPNJgcoUVVLHGh1KDf9hDx1nKrEdwj3iJB49cUvPp6PErrg/exec',
            'google_sheets_sync_enabled' => '1',
            'google_sheets_outbound_enabled' => '1',
            'google_sheets_bidirectional_enabled' => '1',
            // Contact page settings
            'contact_heading'  => 'Contact The Lab',
            'contact_tagline'  => 'Ready to upgrade your ride? Reach out and we\'ll get back to you within 24 hours.',
            'contact_address'  => "NKKS Arcade, Krystal Homes, Brgy. Alasas\nPampanga, San Fernando, Philippines, 2000",
            'contact_phone'    => '0939 330 8263',
            'contact_phones'   => "0939 330 8263\n+639393308263",
            'contact_email'    => '1625autolab@gmail.com',
            'contact_emails'   => "1625autolab@gmail.com\nservice@1625autolab.com",
            'contact_hours'    => "Mon–Fri: 9:00 AM – 6:00 PM\nSat: By Appointment Only\nSun: Closed",
        ];
    }

    /** @param array<string, mixed> $properties */
    private function logSiteSettingsActivity(string $event, array $properties = []): void
    {
        try {
            $logger = activity()->forSubject('site_settings', 0);

            $actorUserId = $this->resolveActorUserId();
            if ($actorUserId !== null && $actorUserId > 0) {
                $logger->byUser($actorUserId);
            }

            if ($properties !== []) {
                $logger->withProperties($properties);
            }

            $logger->log($event, 'site_settings');
        } catch (\Throwable $e) {
            error_log('[SiteSettingsService] Activity logging failed: ' . $e->getMessage());
        }
    }

    private function resolveActorUserId(): ?int
    {
        try {
            $payload = Auth::user();
            $userId = (int) ($payload['sub'] ?? 0);
            return $userId > 0 ? $userId : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
