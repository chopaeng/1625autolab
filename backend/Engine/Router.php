<?php

declare(strict_types=1);

use FastRoute\Dispatcher;
use function FastRoute\simpleDispatcher;

/**
 * Front-controller router using nikic/fast-route.
 *
 * Resolves the request path/method to the appropriate handler and converts
 * any RuntimeException into a structured JSON error response.
 */
class Router
{
    /** @var array<string, string[]>|null */
    private ?array $rolePermissionsCache = null;

    /** @var array<string, string[]> */
    private const FALLBACK_ROLE_PERMISSIONS = [
        'owner' => [
            'analytics:view',
            'bookings:manage',
            'bookings:assign-tech',
            'bookings:notes',
            'chatbot:manage',
            'build-updates:manage',
            'clients:manage',
            'users:manage',
            'roles:view',
            'roles:manage',
            'security:audit:view',
            'reviews:manage',
            'services:manage',
            'products:manage',
            'content:manage',
            'settings:manage',
            'shop-hours:manage',
            'media:upload',
            'backups:manage',
        ],
        'admin' => [
            'analytics:view',
            'bookings:manage',
            'bookings:assign-tech',
            'bookings:notes',
            'chatbot:manage',
            'build-updates:manage',
            'clients:manage',
            'users:manage',
            'roles:view',
            'roles:manage',
            'reviews:manage',
            'services:manage',
            'products:manage',
            'content:manage',
            'settings:manage',
            'shop-hours:manage',
            'media:upload',
            'backups:manage',
        ],
        'manager' => [
            'analytics:view',
            'bookings:manage',
            'bookings:assign-tech',
            'bookings:notes',
            'build-updates:manage',
            'clients:manage',
            'security:audit:view',
            'roles:view',
            'reviews:manage',
            'services:manage',
            'products:manage',
            'media:upload',
        ],
        'staff' => [
            'bookings:manage',
            'build-updates:manage',
            'clients:manage',
            'roles:view',
            'media:upload',
        ],
        'client' => ['client:self'],
    ];

    public function dispatch(): void
    {
        header('Content-Type: application/json');

        $dispatcher = simpleDispatcher(function (FastRoute\RouteCollector $r): void {
            // ── Public ──────────────────────────────────────────────────────
            $r->addRoute('GET',  '/health',             'handleHealth');
            $r->addRoute('GET',  '/api/posts',          'handlePosts');

            $r->addRoute('GET',  '/api/manychat/menu', 'getManyChatMenu');
            $r->addRoute('POST',  '/api/manychat/variants', 'getManyChatDrillDown');

            // ── Services (public read, admin write) ─────────────────────────
            $r->addRoute('GET',    '/api/services',                   'handleServiceList');
            $r->addRoute('GET',    '/api/services/{id:\d+}',          'handleServiceGet');
            $r->addRoute('GET',    '/api/services/{slug:[a-z0-9]+(?:-[a-z0-9]+)*}', 'handleServiceGetBySlug');
            $r->addRoute('POST',   '/api/services',                   'handleServiceCreate');
            $r->addRoute('PUT',    '/api/services/{id:\d+}',          'handleServiceUpdate');
            $r->addRoute('DELETE', '/api/services/{id:\d+}',          'handleServiceDelete');
            

            // Service variations (admin write, public read via parent service)
            $r->addRoute('GET',    '/api/services/{id:\d+}/variations',              'handleServiceVariationList');
            $r->addRoute('POST',   '/api/services/{id:\d+}/variations',              'handleServiceVariationCreate');
            $r->addRoute('PUT',    '/api/services/{id:\d+}/variations/{vid:\d+}',    'handleServiceVariationUpdate');
            $r->addRoute('DELETE', '/api/services/{id:\d+}/variations/{vid:\d+}',    'handleServiceVariationDelete');

            // ── Auth ────────────────────────────────────────────────────────
            $r->addRoute('POST', '/api/auth/register',         'handleAuthRegister');
            $r->addRoute('POST', '/api/auth/login',            'handleAuthLogin');
            $r->addRoute('POST', '/api/auth/logout',           'handleAuthLogout');
            $r->addRoute('POST', '/api/auth/refresh',          'handleAuthRefresh');
            $r->addRoute('GET',  '/api/auth/me',               'handleAuthMe');
            $r->addRoute('PUT',  '/api/auth/profile',          'handleAuthProfile');
            $r->addRoute('POST', '/api/auth/avatar-upload',    'handleAuthAvatarUpload');
            $r->addRoute('POST', '/api/auth/forgot-password',  'handleAuthForgotPassword');
            $r->addRoute('POST', '/api/auth/reset-password',   'handleAuthResetPassword');
            $r->addRoute('GET',  '/api/auth/verify-email',     'handleAuthVerifyEmail');
            $r->addRoute('POST', '/api/auth/resend-verification', 'handleAuthResendVerification');
            $r->addRoute('GET',  '/api/auth/sessions',         'handleAuthSessionList');
            $r->addRoute('DELETE', '/api/auth/sessions/revoke-others', 'handleAuthSessionRevokeOthers');
            $r->addRoute('DELETE', '/api/auth/sessions/{id:\d+}', 'handleAuthSessionRevoke');
            // ── GDPR / Data Privacy ─────────────────────────────────────────
            $r->addRoute('GET',    '/api/auth/data-export',       'handleAuthDataExport');
            $r->addRoute('DELETE', '/api/auth/account',           'handleAuthAccountDelete');

            // ── Bookings ────────────────────────────────────────────────────
            $r->addRoute('POST',  '/api/bookings',                  'handleBookingCreate');
            $r->addRoute('POST',  '/api/booking/external',          'handleBookingExternalCreate');
            $r->addRoute('POST',  '/api/inquiries',                 'handleInquiryCreate');
            $r->addRoute('GET',   '/api/inquiries',                 'handleInquiryList');
            $r->addRoute('GET',   '/api/inquiries/mine',            'handleInquiryMine');
            $r->addRoute('GET',   '/api/inquiries/calendar',        'handleInquiryCalendar');
            $r->addRoute('GET',   '/api/inquiries/availability',   'handleInquiryAvailability');
            $r->addRoute('GET',   '/api/inquiries/{id}',             'handleInquiryGet');
            $r->addRoute('GET',   '/api/inquiries/{id}/activity',    'handleInquiryActivity');
            $r->addRoute('PATCH', '/api/inquiries/{id}',             'handleInquiryUpdate');
            $r->addRoute('PATCH', '/api/inquiries/{id}/notes',       'handleInquiryInternalNotes');
            $r->addRoute('PATCH', '/api/inquiries/{id}/service',     'handleInquiryUpdateService');
            $r->addRoute('PUT',   '/api/admin/inquiries/{id}/service', 'handleInquiryUpdateService');
            $r->addRoute('DELETE','/api/inquiries/{id}',             'handleInquiryDelete');
            
            // ── Inquiry Checklists ─────────────────────────────────────────
            $r->addRoute('GET',    '/api/checklist/lookup',                          'handleChecklistLookup');
            $r->addRoute('POST',   '/api/checklist/submit',                          'handlePublicChecklistSubmit');
            $r->addRoute('GET',    '/api/checklist/submission',                      'handlePublicChecklistGetSubmission');
            $r->addRoute('POST',   '/api/public/checklist/pdf',                      'handlePublicChecklistPdf');
            $r->addRoute('GET',    '/api/public/checklist/pdf',                      'handlePublicChecklistPdfGet');

            $r->addRoute('GET',   '/api/bookings',                  'handleBookingList');
            $r->addRoute('GET',   '/api/bookings/mine',             'handleBookingMine');
            $r->addRoute('GET',   '/api/bookings/availability',     'handleBookingAvailability');
            $r->addRoute('POST',  '/api/bookings/media',            'handleBookingMediaUpload');
            $r->addRoute('GET',   '/api/bookings/{id}',             'handleBookingGet');
            $r->addRoute('PATCH', '/api/bookings/{id}',             'handleBookingUpdate');
            $r->addRoute('DELETE','/api/bookings/{id}',             'handleBookingDelete');
            $r->addRoute('PATCH', '/api/bookings/{id}/assign-tech', 'handleBookingAssignTech');
            $r->addRoute('PATCH', '/api/bookings/{id}/cancel',      'handleBookingCancel');
            $r->addRoute('PATCH', '/api/bookings/{id}/reschedule',        'handleBookingReschedule');
            $r->addRoute('PATCH', '/api/bookings/{id}/admin-reschedule', 'handleAdminBookingReschedule');
            $r->addRoute('PATCH', '/api/bookings/{id}/parts',             'handleBookingPartsUpdate');
            $r->addRoute('GET',   '/api/bookings/{id}/parts/requirements', 'handleBookingPartRequirementList');
            $r->addRoute('POST',  '/api/bookings/{id}/parts/requirements', 'handleBookingPartRequirementCreate');
            $r->addRoute('PATCH', '/api/bookings/{id}/parts/requirements/{rid:\d+}', 'handleBookingPartRequirementUpdate');
            $r->addRoute('PATCH', '/api/bookings/{id}/qa-photos',         'handleBookingQaPhotosUpdate');
            $r->addRoute('PATCH', '/api/bookings/{id}/notes',             'handleBookingInternalNotes');
            $r->addRoute('PATCH', '/api/bookings/{id}/calibration',       'handleBookingCalibrationUpdate');
            $r->addRoute('GET',   '/api/bookings/{id}/activity',          'handleBookingActivityList');

            // ── Build updates (progress photos – admin write, owner read) ────
            $r->addRoute('GET',  '/api/bookings/{id}/build-updates',       'handleBuildUpdateList');
            $r->addRoute('POST', '/api/bookings/{id}/build-updates',       'handleBuildUpdateCreate');
            $r->addRoute('POST', '/api/bookings/{id}/build-updates/media', 'handleBuildUpdateMediaUpload');

            // ── Reviews (client write after completed, admin moderate) ───────
            $r->addRoute('GET',    '/api/reviews/published',        'handlePublishedReviewList');
            $r->addRoute('GET',    '/api/bookings/{id}/review',    'handleBookingReviewGet');
            $r->addRoute('POST',   '/api/bookings/{id}/review',    'handleBookingReviewCreate');
            $r->addRoute('GET',    '/api/reviews',                 'handleReviewList');
            $r->addRoute('PATCH',  '/api/reviews/{id:\d+}/approve','handleReviewApprove');
            $r->addRoute('PATCH',  '/api/reviews/{id:\d+}/reject', 'handleReviewReject');
            $r->addRoute('DELETE', '/api/reviews/{id:\d+}',        'handleReviewDelete');

            // ── In-app notifications ────────────────────────────────────────
            $r->addRoute('GET',    '/api/notifications',              'handleNotificationList');
            $r->addRoute('PATCH',  '/api/notifications/read-all',     'handleNotificationReadAll');
            $r->addRoute('PATCH',  '/api/notifications/{id:\d+}/read','handleNotificationRead');
            $r->addRoute('DELETE', '/api/notifications/{id:\d+}',     'handleNotificationDelete');

            // ── Notification preferences ─────────────────────────────────────
            $r->addRoute('GET', '/api/auth/notification-preferences', 'handleNotificationPrefsGet');
            $r->addRoute('PUT', '/api/auth/notification-preferences', 'handleNotificationPrefsSave');

            // ── Customer loyalty stats ────────────────────────────────────────
            $r->addRoute('GET', '/api/customers/{userId:\d+}/stats', 'handleCustomerStats');

            // ── Client vehicle garage ─────────────────────────────────────────
            $r->addRoute('GET',    '/api/client/vehicles',          'handleClientVehicleList');
            $r->addRoute('POST',   '/api/client/vehicles',          'handleClientVehicleCreate');
            $r->addRoute('POST',   '/api/client/vehicles/media',    'handleClientVehicleMediaUpload');
            $r->addRoute('PUT',    '/api/client/vehicles/{id:\d+}', 'handleClientVehicleUpdate');
            $r->addRoute('DELETE', '/api/client/vehicles/{id:\d+}', 'handleClientVehicleDelete');

            // ── Blog posts (public read, admin write) ───────────────────────
            $r->addRoute('GET',    '/api/blog',              'handleBlogList');
            $r->addRoute('GET',    '/api/blog/{id:\d+}',     'handleBlogGet');
            $r->addRoute('POST',   '/api/blog',              'handleBlogCreate');
            $r->addRoute('PUT',    '/api/blog/{id:\d+}',     'handleBlogUpdate');
            $r->addRoute('DELETE', '/api/blog/{id:\d+}',     'handleBlogDelete');

            // ── Products (public read, admin write) ─────────────────────────
            $r->addRoute('GET',    '/api/products',          'handleProductList');
            $r->addRoute('GET',    '/api/products/{id:[A-Za-z0-9-]+}', 'handleProductGet');
            $r->addRoute('POST',   '/api/products',          'handleProductCreate');
            $r->addRoute('PUT',    '/api/products/{id:[A-Za-z0-9-]+}', 'handleProductUpdate');
            $r->addRoute('DELETE', '/api/products/{id:[A-Za-z0-9-]+}', 'handleProductDelete');
            // Product variations (admin write, public read via parent product)
            $r->addRoute('GET',    '/api/products/{id:[A-Za-z0-9-]+}/variations',              'handleProductVariationList');
            $r->addRoute('POST',   '/api/products/{id:[A-Za-z0-9-]+}/variations',              'handleProductVariationCreate');
            $r->addRoute('PUT',    '/api/products/{id:[A-Za-z0-9-]+}/variations/{vid:\d+}',    'handleProductVariationUpdate');
            $r->addRoute('DELETE', '/api/products/{id:[A-Za-z0-9-]+}/variations/{vid:\d+}',    'handleProductVariationDelete');
            // Product orders (public checkout, authenticated tracking, admin fulfillment)
            $r->addRoute('POST',   '/api/orders',                         'handleOrderCreate');
            $r->addRoute('GET',    '/api/orders/mine',                    'handleOrderMine');
            $r->addRoute('GET',    '/api/orders/{id:\d+}',               'handleOrderGet');
            $r->addRoute('GET',    '/api/admin/orders',                   'handleAdminOrderList');
            $r->addRoute('PATCH',  '/api/admin/orders/{id:\d+}/status',  'handleAdminOrderStatusUpdate');
            $r->addRoute('PATCH',  '/api/admin/orders/{id:\d+}/tracking','handleAdminOrderTrackingUpdate');
            $r->addRoute('PATCH',  '/api/admin/orders/{id:\d+}/payment', 'handleAdminOrderPaymentUpdate');

            // ── Portfolio (public read, admin write) ─────────────────────────
            $r->addRoute('GET',    '/api/portfolio',                          'handlePortfolioList');
            $r->addRoute('GET',    '/api/portfolio/{id:\d+}',                 'handlePortfolioGet');
            $r->addRoute('GET',    '/api/portfolio/slug/{slug}',               'handlePortfolioGetBySlug');
            $r->addRoute('POST',   '/api/portfolio',                          'handlePortfolioCreate');
            $r->addRoute('PUT',    '/api/portfolio/{id:\d+}',                 'handlePortfolioUpdate');
            $r->addRoute('DELETE', '/api/portfolio/{id:\d+}',                 'handlePortfolioDelete');

            // ── Portfolio Categories (public read, admin write) ───────────────
            $r->addRoute('GET',    '/api/portfolio-categories',               'handlePortfolioCategoryList');
            $r->addRoute('GET',    '/api/portfolio-categories/{id:\d+}',      'handlePortfolioCategoryGet');
            $r->addRoute('POST',   '/api/portfolio-categories',               'handlePortfolioCategoryCreate');
            $r->addRoute('PUT',    '/api/portfolio-categories/{id:\d+}',      'handlePortfolioCategoryUpdate');
            $r->addRoute('DELETE', '/api/portfolio-categories/{id:\d+}',      'handlePortfolioCategoryDelete');

            // ── Shop hours ──────────────────────────────────────────────────
            $r->addRoute('GET', '/api/shop/hours',                       'handleShopHoursGet');
            $r->addRoute('PUT', '/api/shop/hours',                       'handleShopHoursPut');
            $r->addRoute('GET',    '/api/shop/closed-dates',             'handleShopClosedDatesGet');
            $r->addRoute('POST',   '/api/shop/closed-dates',             'handleShopClosedDatesAdd');
            $r->addRoute('DELETE', '/api/shop/closed-dates/{date}',      'handleShopClosedDatesRemove');

            // ── Site settings (public read, admin write) ─────────────────────
            $r->addRoute('GET', '/api/site-settings', 'handleSiteSettingsGet');
            $r->addRoute('PUT', '/api/site-settings', 'handleSiteSettingsPut');
            $r->addRoute('POST', '/api/integrations/google-sheets/inbound', 'handleGoogleSheetsInbound');
            $r->addRoute('GET',  '/api/integrations/google-sheets/all-inquiries', 'handleGoogleSheetsAllInquiries');
            $r->addRoute('POST', '/api/admin/site-settings/test-sheets-webhook', 'handleTestSheetsWebhook');
            $r->addRoute('POST', '/api/admin/site-settings/sync-all-sheets', 'handleSyncAllSheets');
            $r->addRoute('POST', '/api/admin/site-settings/pull-sheets', 'handlePullSheets');
            $r->addRoute('GET',  '/api/admin/site-settings/sheets-script', 'handleGetSheetsScript');

            // ── Team members (public read, admin write) ──────────────────────
            $r->addRoute('GET',    '/api/team-members',          'handleTeamMemberList');
            $r->addRoute('POST',   '/api/team-members',          'handleTeamMemberCreate');
            $r->addRoute('PUT',    '/api/team-members/{id:\d+}', 'handleTeamMemberUpdate');
            $r->addRoute('DELETE', '/api/team-members/{id:\d+}', 'handleTeamMemberDelete');

            // ── Booking Waitlist ─────────────────────────────────────────────
            $r->addRoute('POST',   '/api/waitlist',          'handleWaitlistJoin');
            $r->addRoute('GET',    '/api/waitlist',          'handleWaitlistList');
            $r->addRoute('DELETE', '/api/waitlist/{id:\d+}', 'handleWaitlistRemove');
            $r->addRoute('GET',    '/api/waitlist/claim/{token:[A-Za-z0-9]+}', 'handleWaitlistClaimGet');

            // ── Testimonials (public read, admin write) ──────────────────────
            $r->addRoute('GET',    '/api/testimonials',          'handleTestimonialList');
            $r->addRoute('POST',   '/api/testimonials',          'handleTestimonialCreate');
            $r->addRoute('PUT',    '/api/testimonials/{id:\d+}', 'handleTestimonialUpdate');
            $r->addRoute('DELETE', '/api/testimonials/{id:\d+}', 'handleTestimonialDelete');

            // ── FAQ ──────────────────────────────────────────────────────────────
            $r->addRoute('GET',    '/api/faq',              'handleFaqList');
            $r->addRoute('POST',   '/api/faq',              'handleFaqCreate');
            $r->addRoute('PUT',    '/api/faq/{id:\d+}',     'handleFaqUpdate');
            $r->addRoute('DELETE', '/api/faq/{id:\d+}',     'handleFaqDelete');

            // ── Offers (public read, admin write) ────────────────────────────
            $r->addRoute('GET',    '/api/offers',          'handleOfferList');
            $r->addRoute('GET',    '/api/offers/{id:\d+}', 'handleOfferGet');
            $r->addRoute('POST',   '/api/offers',          'handleOfferCreate');
            $r->addRoute('PUT',    '/api/offers/{id:\d+}', 'handleOfferUpdate');
            $r->addRoute('DELETE', '/api/offers/{id:\d+}', 'handleOfferDelete');

            // ── Before/After (public read, admin write) ─────────────────────
            $r->addRoute('GET',    '/api/before-after',          'handleBeforeAfterList');
            $r->addRoute('GET',    '/api/before-after/{id:\d+}', 'handleBeforeAfterGet');
            $r->addRoute('POST',   '/api/before-after',          'handleBeforeAfterCreate');
            $r->addRoute('PUT',    '/api/before-after/{id:\d+}', 'handleBeforeAfterUpdate');
            $r->addRoute('DELETE', '/api/before-after/{id:\d+}', 'handleBeforeAfterDelete');

            // ── Contact message (public) ─────────────────────────────────────
            $r->addRoute('POST', '/api/contact', 'handleContactMessage');

            // ── Vehicle catalog admin CRUD ─────────────────────────────────
            $r->addRoute('GET',    '/api/admin/vehicle-makes',          'handleAdminVehicleMakesList');
            $r->addRoute('POST',   '/api/admin/vehicle-makes',          'handleAdminVehicleMakesCreate');
            $r->addRoute('PUT',    '/api/admin/vehicle-makes/{id:\d+}', 'handleAdminVehicleMakesUpdate');
            $r->addRoute('DELETE', '/api/admin/vehicle-makes/{id:\d+}', 'handleAdminVehicleMakesDelete');

            $r->addRoute('GET',    '/api/admin/vehicle-models',          'handleAdminVehicleModelsList');
            $r->addRoute('POST',   '/api/admin/vehicle-models',          'handleAdminVehicleModelsCreate');
            $r->addRoute('PUT',    '/api/admin/vehicle-models/{id:\d+}', 'handleAdminVehicleModelsUpdate');
            $r->addRoute('DELETE', '/api/admin/vehicle-models/{id:\d+}', 'handleAdminVehicleModelsDelete');

            // ── Admin utilities ─────────────────────────────────────────────
            $r->addRoute('POST', '/api/admin/migrate', 'handleMigrateRun');
            $r->addRoute('GET',  '/api/admin/migrate', 'handleMigrateStatus');
            $r->addRoute('POST', '/api/admin/cron/notification-queue', 'handleAdminCronNotificationQueue');
            $r->addRoute('POST', '/api/admin/cron/waitlist-autofill', 'handleAdminCronWaitlistAutofill');
            $r->addRoute('POST', '/api/admin/cron/appointment-reminders', 'handleAdminCronAppointmentReminders');
            $r->addRoute('POST', '/api/admin/inquiries/link-guests', 'handleAdminInquiryLinkGuests');
            $r->addRoute('GET',  '/api/admin/stats',   'handleAdminStats');
            $r->addRoute('GET',  '/api/admin/activity','handleAdminActivity');
            $r->addRoute('POST', '/api/admin/upload',  'handleAdminMediaUpload');
            $r->addRoute('GET',    '/api/admin/users',            'handleAdminUserList');
            $r->addRoute('GET',    '/api/admin/users/assignable',  'handleAdminAssignableUsers');
            $r->addRoute('POST',   '/api/admin/users',            'handleAdminUserCreate');
            $r->addRoute('PATCH',  '/api/admin/users/{id:\d+}/role',   'handleAdminUserRoleUpdate');
            $r->addRoute('PATCH',  '/api/admin/users/{id:\d+}/status', 'handleAdminUserStatusUpdate');
            $r->addRoute('PATCH',  '/api/admin/users/{id:\d+}/info',   'handleAdminUserInfoUpdate');
            $r->addRoute('DELETE', '/api/admin/users/{id:\d+}',        'handleAdminUserDelete');
            $r->addRoute('GET',  '/api/admin/clients', 'handleAdminClientList');
            $r->addRoute('GET',  '/api/admin/clients/{id:\d+}/bookings', 'handleAdminClientBookings');
            $r->addRoute('GET',  '/api/admin/clients/{id:\d+}/vehicles', 'handleAdminClientVehicles');
            $r->addRoute('GET',  '/api/admin/customers/{id:\d+}/360', 'handleAdminCustomer360');
            $r->addRoute('GET',  '/api/admin/roles',   'handleAdminRoleList');
            $r->addRoute('GET',  '/api/admin/roles/audit', 'handleAdminRoleAuditList');
            $r->addRoute('GET',  '/api/admin/security/audit', 'handleAdminSecurityAuditList');
            $r->addRoute('GET',  '/api/admin/security/audit/export', 'handleAdminSecurityAuditExport');
            $r->addRoute('GET',  '/api/admin/activity-logs/users', 'handleAdminActivityLogUsers');
            $r->addRoute('GET',  '/api/admin/activity-logs', 'handleAdminActivityLogList');
            $r->addRoute('GET',  '/api/admin/semaphore/account', 'handleAdminSemaphoreAccount');
            $r->addRoute('GET',  '/api/admin/semaphore/messages', 'handleAdminSemaphoreMessages');
            $r->addRoute('GET',  '/api/admin/notification-queue', 'handleAdminNotificationQueue');
            $r->addRoute('GET',  '/api/admin/notification-queue/health', 'handleAdminNotificationQueueHealth');
            $r->addRoute('POST', '/api/admin/notification-queue/replay-failed', 'handleAdminNotificationQueueReplayFailed');
            $r->addRoute('POST', '/api/admin/notification-queue/{id:\d+}/replay', 'handleAdminNotificationQueueReplayOne');
            $r->addRoute('GET',  '/api/admin/campaigns', 'handleAdminCampaignList');
            $r->addRoute('POST', '/api/admin/campaigns', 'handleAdminCampaignCreate');
            $r->addRoute('GET',  '/api/admin/campaigns/{id:\d+}', 'handleAdminCampaignGet');
            $r->addRoute('PATCH', '/api/admin/campaigns/{id:\d+}', 'handleAdminCampaignUpdate');
            $r->addRoute('DELETE', '/api/admin/campaigns/{id:\d+}', 'handleAdminCampaignDelete');
            $r->addRoute('POST', '/api/admin/campaigns/{id:\d+}/run', 'handleAdminCampaignRun');
            $r->addRoute('POST', '/api/admin/campaigns/{id:\d+}/dry-run', 'handleAdminCampaignDryRun');
            $r->addRoute('POST', '/api/admin/campaigns/run-scheduled', 'handleAdminCampaignRunScheduled');
            $r->addRoute('GET',  '/api/admin/campaigns/{id:\d+}/analytics', 'handleAdminCampaignAnalytics');
            $r->addRoute('GET',  '/api/admin/campaign-audiences/{type:[a-z0-9_-]+}', 'handleAdminCampaignAudience');
            $r->addRoute('GET',  '/api/admin/inventory/items', 'handleAdminInventoryItemList');
            $r->addRoute('POST', '/api/admin/inventory/items', 'handleAdminInventoryItemCreate');
            $r->addRoute('PATCH', '/api/admin/inventory/items/{id:\d+}', 'handleAdminInventoryItemUpdate');
            $r->addRoute('GET',  '/api/admin/inventory/movements', 'handleAdminInventoryMovementList');
            $r->addRoute('POST', '/api/admin/inventory/adjust', 'handleAdminInventoryAdjust');
            $r->addRoute('GET',  '/api/admin/inventory/alerts', 'handleAdminInventoryAlertList');
            $r->addRoute('GET',  '/api/admin/inventory/suppliers', 'handleAdminInventorySupplierList');
            $r->addRoute('POST', '/api/admin/inventory/suppliers', 'handleAdminInventorySupplierCreate');
            $r->addRoute('GET',  '/api/admin/inventory/purchase-orders', 'handleAdminInventoryPurchaseOrderList');
            $r->addRoute('POST', '/api/admin/inventory/purchase-orders', 'handleAdminInventoryPurchaseOrderCreate');
            $r->addRoute('PATCH', '/api/admin/inventory/purchase-orders/{id:\d+}/status', 'handleAdminInventoryPurchaseOrderStatus');
            $r->addRoute('POST', '/api/admin/roles',   'handleAdminRoleCreate');
            $r->addRoute('PUT',  '/api/admin/roles/{id:\d+}', 'handleAdminRoleUpdate');
            $r->addRoute('DELETE', '/api/admin/roles/{id:\d+}', 'handleAdminRoleDelete');

            // ── Admin Checklist Management ──────────────────────────────────
            $r->addRoute('POST', '/api/admin/checklist/resend-email',     'handleAdminChecklistResendEmail');
            $r->addRoute('POST', '/api/admin/checklist/submission/delete', 'handleAdminChecklistDelete');
            $r->addRoute('POST', '/api/admin/checklist/submission/update', 'handleAdminChecklistUpdate');

            // ── Admin Backups & Restoration ─────────────────────────────────
            $r->addRoute('GET',    '/api/admin/backups',                     'handleAdminBackupList');
            $r->addRoute('POST',   '/api/admin/backups/create',              'handleAdminBackupCreate');
            $r->addRoute('GET',    '/api/admin/backups/download/{filename}', 'handleAdminBackupDownload');
            $r->addRoute('DELETE', '/api/admin/backups/{filename}',          'handleAdminBackupDelete');
            $r->addRoute('POST',   '/api/admin/backups/inspect',             'handleAdminBackupInspect');
            $r->addRoute('POST',   '/api/admin/backups/restore',             'handleAdminBackupRestore');

            // ── Vehicle data (API Ninjas proxy) ──────────────────────────────
            $r->addRoute('GET', '/api/vehicles/makes',  'handleVehicleMakes');
            $r->addRoute('GET', '/api/vehicles/models', 'handleVehicleModels');
            $r->addRoute('GET', '/api/vehicles/trims',  'handleVehicleTrims');
        });

        $path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        $routeInfo = $dispatcher->dispatch($method, $path);

        try {
            switch ($routeInfo[0]) {
                case Dispatcher::FOUND:
                    $this->{$routeInfo[1]}($routeInfo[2]);
                    break;

                case Dispatcher::METHOD_NOT_ALLOWED:
                    http_response_code(405);
                    echo json_encode(['detail' => 'Method Not Allowed']);
                    break;

                default:
                    http_response_code(404);
                    echo json_encode(['detail' => 'Not Found']);
            }
        } catch (RuntimeException $e) {
            http_response_code((int) $e->getCode() ?: 500);
            echo json_encode(['detail' => $e->getMessage()]);
        } catch (\Throwable $e) {
            // Catches PDOException and any other uncaught error so PHP never
            // emits a fatal-error HTML page inside a JSON API.
            http_response_code(500);
            echo json_encode(['detail' => 'Internal server error: ' . $e->getMessage()]);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input') ?: '{}';
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Validate a Cloudflare Turnstile token via the Siteverify API.
     * Throws a 422 RuntimeException if the token is missing or invalid.
     *
     * @param array<string, mixed> $data  The decoded JSON body; should contain 'cf-turnstile-response'
     */
    private function validateTurnstile(array $data): void
    {
        if (defined('TURNSTILE_BYPASS') && TURNSTILE_BYPASS) {
            return;
        }

        $secret = defined('TURNSTILE_SECRET_KEY') ? TURNSTILE_SECRET_KEY : ($_ENV['TURNSTILE_SECRET_KEY'] ?? '');

        // Skip validation when no secret is configured (dev / test environments)
        if ($secret === '') {
            return;
        }

        $token = trim((string) ($data['cf-turnstile-response'] ?? ''));

        if ($token === '') {
            throw new RuntimeException('CAPTCHA token is required. Please complete the challenge.', 422);
        }

        $ip = $this->getClientIp();

        $postData = ['secret' => $secret, 'response' => $token];
        if ($ip !== '') {
            $postData['remoteip'] = $ip;
        }

        $options = [
            'http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($postData),
                'timeout' => 5,
            ],
        ];

        $context  = stream_context_create($options);
        $response = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $context);

        if ($response === false) {
            // Network failure – fail open to avoid blocking real users
            error_log('Turnstile: siteverify request failed (network).');
            return;
        }

        /** @var array<string, mixed>|null $result */
        $result = json_decode($response, true);

        if (!is_array($result) || empty($result['success'])) {
            $codes = implode(', ', (array) ($result['error-codes'] ?? ['unknown']));
            error_log('Turnstile validation failed: ' . $codes);
            throw new RuntimeException('CAPTCHA verification failed. Please try again.', 422);
        }
    }

    /**
     * Require a valid Bearer JWT and return its payload.
     * Optionally enforce a specific role.
     *
     * @return array<string, mixed>
     */
    private function requireAuth(?string $role = null): array
    {
        $payload = Auth::user();

        if ($role !== null && ($payload['role'] ?? '') !== $role) {
            throw new RuntimeException('Forbidden.', 403);
        }

        return $payload;
    }

    /**
     * Require one of the provided roles.
     *
     * @param string[] $roles
     * @return array<string, mixed>
     */
    private function requireRoles(array $roles): array
    {
        $payload = Auth::user();
        $role = (string) ($payload['role'] ?? '');

        if (!in_array($role, $roles, true)) {
            throw new RuntimeException('Forbidden.', 403);
        }

        return $payload;
    }

    private function isBackofficeRole(string $role): bool
    {
        return in_array($role, ['admin', 'manager', 'staff'], true);
    }

    private function getClientIp(): string
    {
        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $first = trim(explode(',', $candidate)[0]);
            if ($first !== '') {
                return substr($first, 0, 64);
            }
        }

        return '';
    }

    private function getUserAgent(): string
    {
        $ua = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if ($ua === '') {
            return '';
        }

        return mb_substr($ua, 0, 500);
    }

    private function formatRetryAfterHuman(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $minutes = intdiv($seconds, 60);
        $remain = $seconds % 60;

        if ($minutes <= 0) {
            return $remain . ' second' . ($remain === 1 ? '' : 's');
        }

        if ($remain === 0) {
            return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
        }

        return $minutes . ' minute' . ($minutes === 1 ? '' : 's')
            . ' ' . $remain . ' second' . ($remain === 1 ? '' : 's');
    }

    /** @return array<string, string[]> */
    private function getPermissionMap(): array
    {
        if ($this->rolePermissionsCache !== null) {
            return $this->rolePermissionsCache;
        }

        $map = self::FALLBACK_ROLE_PERMISSIONS;

        try {
            $roles = (new UserService())->listRoles();
            if (!empty($roles)) {
                $dynamic = [];
                foreach ($roles as $role) {
                    $key = strtolower(trim((string) ($role['key'] ?? '')));
                    if ($key === '') {
                        continue;
                    }

                    $permissions = is_array($role['permissions'] ?? null)
                        ? array_values(array_filter(array_map('strval', $role['permissions']), static fn (string $v): bool => $v !== ''))
                        : [];

                    $dynamic[$key] = $permissions;
                }

                if (!empty($dynamic)) {
                    $map = $dynamic;
                }
            }
        } catch (\Throwable) {
            // Keep static fallback map when role records are unavailable.
        }

        $this->rolePermissionsCache = $map;
        return $map;
    }

    private function hasPermissionByRole(string $role, string $permission): bool
    {
        $role = strtolower(trim($role));
        if ($role === '') {
            return false;
        }

        $permissions = $this->getPermissionMap()[$role] ?? [];
        if ($role === 'admin' || $role === 'owner') {
            return true;
        }

        return in_array($permission, $permissions, true);
    }

    /** @return string[] */
    private function getRolePermissions(string $role): array
    {
        $role = strtolower(trim($role));
        if ($role === '') {
            return [];
        }

        $permissions = $this->getPermissionMap()[$role] ?? [];
        if ($role === 'admin' || $role === 'owner') {
            $permissions = array_values(array_unique(array_merge($permissions, ['chatbot:manage'])));
        }

        return array_values(array_unique(array_map('strval', $permissions)));
    }

    /** @param array<string, mixed> $payload */
    private function hasPermission(array $payload, string $permission): bool
    {
        return $this->hasPermissionByRole((string) ($payload['role'] ?? ''), $permission);
    }

    /**
     * @param array<string, mixed> $payload
     * @param string[] $permissions
     */
    private function hasAnyPermission(array $payload, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($payload, $permission)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function requirePermission(string $permission): array
    {
        $payload = Auth::user();
        if (!$this->hasPermission($payload, $permission)) {
            throw new RuntimeException('Forbidden.', 403);
        }

        return $payload;
    }

    private function getSiteSettingFlag(string $key, bool $default = false): bool
    {
        $settings = (new SiteSettingsService())->getAll();
        $raw = strtolower(trim((string) ($settings[$key] ?? ($default ? '1' : '0'))));

        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    /** @param array<string, mixed> $payload */
    private function isStaffRole(array $payload): bool
    {
        return strtolower(trim((string) ($payload['role'] ?? ''))) === 'staff';
    }

    private function staffCanViewAllBookings(): bool
    {
        return $this->getSiteSettingFlag('staff_can_view_all_bookings', false);
    }

    private function staffCanManageAllBookings(): bool
    {
        return $this->getSiteSettingFlag('staff_can_manage_all_bookings', false);
    }

    /** @param array<string, mixed> $booking */
    private function bookingAssignedToUser(array $booking, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $assignedTech = $booking['assignedTech'] ?? null;
        if (!is_array($assignedTech)) {
            return false;
        }

        $assignedUserId = isset($assignedTech['userId']) && $assignedTech['userId'] !== null
            ? (int) $assignedTech['userId']
            : 0;

        return $assignedUserId > 0 && $assignedUserId === $userId;
    }

    /** @param array<string, mixed> $payload
     *  @return array<int, array<string, mixed>>
     */
    private function getAccessibleBookingsForPayload(array $payload): array
    {
        $bookings = (new BookingService())->getAll();
        if (!$this->isStaffRole($payload) || $this->staffCanViewAllBookings()) {
            return $bookings;
        }

        $userId = (int) ($payload['sub'] ?? 0);
        return array_values(array_filter(
            $bookings,
            fn(array $booking): bool => $this->bookingAssignedToUser($booking, $userId)
        ));
    }

    /** @param array<string, mixed> $payload
     *  @return array<string, mixed>
     */
    private function requireBookingVisibilityForPayload(array $payload, string $bookingId): array
    {
        $booking = (new BookingService())->adminFindById($bookingId);
        if ($booking === null) {
            throw new RuntimeException('Booking not found.', 404);
        }

        if (!$this->isStaffRole($payload) || $this->staffCanViewAllBookings()) {
            return $booking;
        }

        if ($this->bookingAssignedToUser($booking, (int) ($payload['sub'] ?? 0))) {
            return $booking;
        }

        throw new RuntimeException('Forbidden. Staff can only view assigned bookings.', 403);
    }

    /** @param array<string, mixed> $payload
     *  @return array<string, mixed>
     */
    private function requireBookingMutationForPayload(array $payload, string $bookingId): array
    {
        $booking = (new BookingService())->adminFindById($bookingId);
        if ($booking === null) {
            throw new RuntimeException('Booking not found.', 404);
        }

        if (!$this->isStaffRole($payload) || $this->staffCanManageAllBookings()) {
            return $booking;
        }

        if ($this->bookingAssignedToUser($booking, (int) ($payload['sub'] ?? 0))) {
            return $booking;
        }

        throw new RuntimeException('Forbidden. Staff can only manage assigned bookings.', 403);
    }

    // -------------------------------------------------------------------------
    // Public handlers
    // -------------------------------------------------------------------------

    /** @param array<string, string> $vars */
    private function handleHealth(array $vars = []): void
    {
        echo json_encode(['status' => 'ok']);
    }

    /** @param array<string, string> $vars */
    private function handlePosts(array $vars = []): void
    {
        if (FB_ACCESS_TOKEN === '') {
            throw new RuntimeException('FB_ACCESS_TOKEN is not configured on the server.', 500);
        }

        $limit = max(1, min(100, (int) ($_GET['limit'] ?? 10)));
        $after = (isset($_GET['after']) && $_GET['after'] !== '') ? (string) $_GET['after'] : null;

        $cursorKey = $after !== null ? hash('sha256', $after) : 'first';
        $cacheKey = 'apollo_posts_' . $limit . '_' . $cursorKey;

        if (CACHE_TTL_SECONDS > 0) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                echo json_encode($cached);
                return;
            }
        }

        $service = new FacebookService();
        $result  = $service->getPosts($limit, $after);

        if (CACHE_TTL_SECONDS > 0) {
            Cache::set($cacheKey, $result, CACHE_TTL_SECONDS);
        }

        echo json_encode($result);
    }

    // -------------------------------------------------------------------------
    // Auth handlers
    // -------------------------------------------------------------------------

    /** @param array<string, string> $vars */
    private function handleAuthRegister(array $vars = []): void
    {
        $siteSettings = (new SiteSettingsService())->getAll();
        if (($siteSettings['disable_registration'] ?? '0') === '1') {
            http_response_code(403);
            echo json_encode([
                'error' => 'Registration is currently unavailable.',
                'detail' => 'User registration is currently disabled.'
            ]);
            return;
        }

        $data   = $this->jsonBody();
        $this->validateTurnstile($data);
        $result = (new UserService())->register($data);
        if (isset($result['user']) && is_array($result['user'])) {
            $result['user']['permissions'] = $this->getRolePermissions((string) ($result['user']['role'] ?? ''));

            // Record consent if the client sent the consent flag
            if (!empty($data['consentGiven']) && isset($result['user']['id'])) {
                try {
                    (new PrivacyService())->recordConsent((int) $result['user']['id'], '1.0');
                } catch (\Throwable) { /* non-blocking */ }
            }

            // Link existing guest inquiries
            if (isset($result['user']['id'], $result['user']['email'])) {
                try {
                    if (DB_NAME !== '') {
                        $db = Database::getInstance();
                        $stmt = $db->prepare('UPDATE customer_inquiries SET user_id = :uid WHERE email_address = :email AND user_id IS NULL');
                        $stmt->execute([
                            ':uid' => (string) $result['user']['id'],
                            ':email' => (string) $result['user']['email']
                        ]);
                    }
                } catch (\Throwable $e) { /* non-blocking */ }
            }
        }
        http_response_code(201);
        echo json_encode($result);
    }

    /** @param array<string, string> $vars */
    private function handleAuthLogin(array $vars = []): void
    {
        $data   = $this->jsonBody();
        $this->validateTurnstile($data);
        $email  = (string) ($data['email']    ?? '');
        $pass   = (string) ($data['password'] ?? '');
        $security = null;
        $ip = $this->getClientIp();
        $ua = $this->getUserAgent();

        if (DB_NAME !== '') {
            $security = new AuthSecurityService();
            $retryAfter = $security->getRetryAfterSeconds($email, $ip);
            if ($retryAfter > 0) {
                header('Retry-After: ' . (string) $retryAfter);
                $security->recordBlockedLoginAttempt(
                    $email,
                    $ip,
                    $ua,
                    $retryAfter,
                    'Blocked before credential verification.'
                );

                if ($security->isSuspiciousLogin($email, $ip) && $security->shouldSendSuspiciousAlert($email, $ip)) {
                    (new NotificationJobQueueService())->dispatch('admin_security_alert', [
                        'email' => strtolower(trim($email)),
                        'ipAddress' => $ip,
                    ]);
                    $security->markSuspiciousAlertSent($email, $ip, $ua, 'Admin suspicious login alert sent.');
                }

                throw new RuntimeException('Too many failed attempts. Try again in ' . $this->formatRetryAfterHuman($retryAfter) . '.', 429);
            }
        }

        try {
            $rememberMe = !empty($data['remember_me']);
            $result = (new UserService())->login($email, $pass, $rememberMe);
            if (isset($result['user']) && is_array($result['user'])) {
                $result['user']['permissions'] = $this->getRolePermissions((string) ($result['user']['role'] ?? ''));
            }

            if ($security !== null) {
                $payload = Auth::decodeToken((string) ($result['token'] ?? ''));
                $uid = (int) ($payload['sub'] ?? 0);
                $exp = (int) ($payload['exp'] ?? (time() + JWT_TTL));

                $security->recordLoginAttempt($email, true, $uid > 0 ? $uid : null, $ip, $ua, 'Login successful.');
                if ($uid > 0) {
                    $security->createSession($uid, (string) $result['token'], $exp, $ip, $ua);
                }

                if ($security->isSuspiciousLogin($email, $ip) && $security->shouldSendSuspiciousAlert($email, $ip)) {
                    (new NotificationJobQueueService())->dispatch('admin_security_alert', [
                        'email' => strtolower(trim($email)),
                        'ipAddress' => $ip,
                    ]);
                    $security->markSuspiciousAlertSent($email, $ip, $ua, 'Admin suspicious login alert sent.');
                }
            }

            echo json_encode($result);
        } catch (RuntimeException $e) {
            if ($security !== null) {
                $security->recordLoginAttempt($email, false, null, $ip, $ua, $e->getMessage());

                $retryAfter = $security->getRetryAfterSeconds($email, $ip);
                if ($retryAfter > 0) {
                    header('Retry-After: ' . (string) $retryAfter);
                }

                if ($security->isSuspiciousLogin($email, $ip) && $security->shouldSendSuspiciousAlert($email, $ip)) {
                    (new NotificationJobQueueService())->dispatch('admin_security_alert', [
                        'email' => strtolower(trim($email)),
                        'ipAddress' => $ip,
                    ]);
                    $security->markSuspiciousAlertSent($email, $ip, $ua, 'Admin suspicious login alert sent.');
                }
            }

            throw $e;
        }
    }

    /** @param array<string, string> $vars */
    private function handleAuthLogout(array $vars = []): void
    {
        $token = Auth::tokenFromHeader();
        if ($token !== null) {
            if (DB_NAME !== '') {
                (new AuthSecurityService())->endSessionByToken($token, 'logout');
            }
            Auth::logout($token);
        }
        echo json_encode(['message' => 'Logged out successfully.']);
    }

    /** @param array<string, string> $vars */
    private function handleAuthRefresh(array $vars = []): void
    {
        $data = $this->jsonBody();
        $refreshToken = (string) ($data['refresh_token'] ?? '');

        if ($refreshToken === '') {
            throw new RuntimeException('refresh_token is required.', 400);
        }

        try {
            $payload = Auth::decodeToken($refreshToken);
            
            // Verify it's actually a refresh token
            if (($payload['type'] ?? '') !== 'refresh') {
                throw new RuntimeException('Invalid token type.', 401);
            }

            $userId = (int) ($payload['sub'] ?? 0);
            if ($userId <= 0) {
                throw new RuntimeException('Invalid token payload.', 401);
            }

            // Issue a new access token
            $newToken = Auth::issueToken([
                'sub' => $userId,
                'type' => 'access',
            ]);

            // Issue a new refresh token (rolling refresh for security)
            $newRefreshToken = Auth::issueToken([
                'sub' => $userId,
                'type' => 'refresh',
            ], 7 * 24 * 60 * 60);

            echo json_encode([
                'token' => $newToken,
                'refresh_token' => $newRefreshToken,
            ]);
        } catch (RuntimeException $e) {
            throw $e;
        }
    }

    private function handleAuthSessionList(array $vars = []): void
    {
        if (DB_NAME === '') {
            echo json_encode(['sessions' => []]);
            return;
        }

        $payload = $this->requireAuth();
        $userId = (int) ($payload['sub'] ?? 0);
        $token = Auth::tokenFromHeader();
        $currentHash = $token !== null ? hash('sha256', $token) : null;

        $sessions = (new AuthSecurityService())->listSessions($userId, $currentHash);
        echo json_encode(['sessions' => $sessions]);
    }

    /** @param array<string, string> $vars */
    private function handleAuthSessionRevoke(array $vars = []): void
    {
        if (DB_NAME === '') {
            echo json_encode(['ok' => true]);
            return;
        }

        $payload = $this->requireAuth();
        $userId = (int) ($payload['sub'] ?? 0);
        $sessionId = (int) ($vars['id'] ?? 0);
        if ($sessionId <= 0) {
            throw new RuntimeException('Invalid session id.', 422);
        }

        $ok = (new AuthSecurityService())->revokeSessionById($userId, $sessionId);
        if (!$ok) {
            throw new RuntimeException('Session not found.', 404);
        }

        echo json_encode(['ok' => true]);
    }

    /** @param array<string, string> $vars */
    private function handleAuthSessionRevokeOthers(array $vars = []): void
    {
        if (DB_NAME === '') {
            echo json_encode(['revoked' => 0]);
            return;
        }

        $payload = $this->requireAuth();
        $userId = (int) ($payload['sub'] ?? 0);
        $token = Auth::tokenFromHeader();
        if ($token === null) {
            throw new RuntimeException('Unauthenticated.', 401);
        }

        $currentHash = hash('sha256', $token);
        $revoked = (new AuthSecurityService())->revokeOtherSessions($userId, $currentHash);

        echo json_encode(['revoked' => $revoked]);
    }

    /** @param array<string, string> $vars */
    private function handleAuthMe(array $vars = []): void
    {
        $payload = $this->requireAuth();
        $user    = (new UserService())->findById((int) $payload['sub']);
        $user['permissions'] = $this->getRolePermissions((string) ($user['role'] ?? ''));
        echo json_encode(['user' => $user]);
    }

    /** @param array<string, string> $vars */
    private function handleAuthProfile(array $vars = []): void
    {
        $payload = $this->requireAuth();
        $data    = $this->jsonBody();
        $user    = (new UserService())->updateProfile((int) $payload['sub'], $data);
        $user['permissions'] = $this->getRolePermissions((string) ($user['role'] ?? ''));
        echo json_encode(['user' => $user]);
    }

    /** @param array<string, string> $vars */
    private function handleAuthAvatarUpload(array $vars = []): void
    {
        $this->requireAuth();

        if (empty($_FILES['file'])) {
            throw new RuntimeException('No file provided.', 422);
        }

        $file     = $_FILES['file'];
        $tmpName  = $file['tmp_name'];
        $mime     = $file['type'];
        $size     = $file['size'];
        $error    = $file['error'];

        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException("File upload error (code $error).", 422);
        }
        $allowed_mimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!in_array($mime, $allowed_mimes, true) || !@getimagesize($tmpName)) {
            throw new RuntimeException('Only JPEG, PNG, WebP and GIF images are accepted.', 422);
        }
        $maxBytes = UPLOAD_MAX_MB * 1024 * 1024;
        if ($size > $maxBytes) {
            throw new RuntimeException('File must be under ' . UPLOAD_MAX_MB . ' MB.', 422);
        }

        $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;

        $storage = new UploadStorage();
        $url     = $storage->upload($tmpName, $filename, $mime, 'users/');

        echo json_encode(['url' => $url]);
    }

    /** @param array<string, string> $vars */
    private function handleAuthForgotPassword(array $vars = []): void
    {
        $data  = $this->jsonBody();
        $this->validateTurnstile($data);
        $email = strtolower(trim((string) ($data['email'] ?? '')));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid email address is required.', 422);
        }

        $token = Auth::generatePasswordResetToken($email);

        if ($token !== null) {
            $resetUrl = APP_URL . '/reset-password?token=' . urlencode($token);
            (new NotificationJobQueueService())->dispatch('password_reset', [
                'email' => $email,
                'resetUrl' => $resetUrl,
            ]);
        }

        // Always return 200 to prevent email enumeration
        echo json_encode(['message' => 'If that email is registered, a reset link has been sent.']);
    }

    /** @param array<string, string> $vars */
    private function handleAuthResetPassword(array $vars = []): void
    {
        $data     = $this->jsonBody();
        $this->validateTurnstile($data);
        $token    = trim((string) ($data['token']    ?? ''));
        $password = (string) ($data['password'] ?? '');
        $confirm  = (string) ($data['password_confirmation'] ?? '');

        if ($token === '') {
            throw new RuntimeException('Reset token is required.', 422);
        }
        if ($password !== $confirm) {
            throw new RuntimeException('Passwords do not match.', 422);
        }

        Auth::resetPassword($token, $password);
        echo json_encode(['message' => 'Your password has been updated. You can now sign in.']);
    }

    /** @param array<string, string> $vars */
    private function handleAuthVerifyEmail(array $vars = []): void
    {
        $token = trim((string) ($_GET['token'] ?? ''));
        $user = (new UserService())->verifyEmail($token);
        $user['permissions'] = $this->getRolePermissions((string) ($user['role'] ?? ''));

        echo json_encode([
            'message' => 'Email verified successfully. You can now sign in.',
            'user' => $user,
        ]);
    }

    /** @param array<string, string> $vars */
    private function handleAuthResendVerification(array $vars = []): void
    {
        $data = $this->jsonBody();
        $email = strtolower(trim((string) ($data['email'] ?? '')));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid email address is required.', 422);
        }

        try {
            $db = Database::getInstance();
            $stmt = $db->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $email]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row && isset($row['id'])) {
                (new UserService())->resendEmailVerification((int) $row['id']);
            }
        } catch (Throwable $e) {
            error_log('[Router] Resend verification failed: ' . $e->getMessage());
        }

        // Always return success to prevent email enumeration.
        echo json_encode(['message' => 'If your account exists and is not yet verified, a new verification email has been sent.']);
    }

    // -------------------------------------------------------------------------
    // Booking handlers
    // -------------------------------------------------------------------------

    /** @param array<string, string> $vars */
    private function handleBookingCreate(array $vars = []): void
    {
        $data = $this->jsonBody();
        $this->validateTurnstile($data);

        // Allow both guest and authenticated booking submission.
        // However, authenticated non-client roles (manager, staff, etc.) without
        // the client:self permission cannot submit bookings through the public form.
        $userId = null;
        try {
            $payload = Auth::user();
            $userId = (int) ($payload['sub'] ?? 0);
            if ($userId <= 0) {
                $userId = null;
            } else {
                $role = strtolower(trim((string) ($payload['role'] ?? '')));
                // admin and owner bypass all permission checks; everyone else
                // must have the client:self permission to submit a booking.
                if ($role !== 'admin' && $role !== 'owner') {
                    if (!$this->hasPermissionByRole($role, 'client:self')) {
                        throw new RuntimeException('Only client accounts may submit bookings through this form.', 403);
                    }
                }
            }
        } catch (RuntimeException $e) {
            if ((int) $e->getCode() !== 401) {
                throw $e;
            }
        }

        if (trim((string) ($data['source'] ?? '')) === '') {
            $data['source'] = 'website';
        }

        $booking = (new BookingService())->create($data, $userId);
        http_response_code(201);
        echo json_encode(['booking' => $booking]);
    }

    /** @param array<string, string> $vars */
    private function handleInquiryCreate(array $vars = []): void
    {
        $data = $this->jsonBody();

        // If the request includes a valid auth token, link this inquiry to the
        // authenticated user immediately (so it appears on their dashboard right away).
        // Guest submissions (no token) fall through silently — user_id stays null
        // and gets linked at registration time via email-match.
        if (!isset($data['userId']) || $data['userId'] === null) {
            try {
                $authPayload = Auth::user();
                $authUserId = (int) ($authPayload['sub'] ?? 0);
                if ($authUserId > 0) {
                    $data['userId'] = $authUserId;
                }
            } catch (\Throwable) {
                // No valid token — treat as guest submission, which is expected.
            }
        }

        $inquiry = (new InquiryService())->create($data);
        GoogleSheetsSyncService::syncInquiry($inquiry);
        $queue = new NotificationJobQueueService();
        $queue->dispatch('customer_inquiry', ['data' => $inquiry]);

        $date = (string) ($inquiry['appointmentDate'] ?? $data['appointmentDate'] ?? '');
        $time = (string) ($inquiry['appointmentTime'] ?? $data['appointmentTime'] ?? '');
        $runAfter = NotificationJobQueueService::calculateReminderRunAfter($date, $time, 3);
        if ($runAfter !== null) {
            $queue->dispatch('appointment_reminder_3h', ['data' => $inquiry], $runAfter);
        }

        http_response_code(201);
        echo json_encode(['inquiry' => $inquiry]);
    }

    /** @param array<string, string> $vars */
    private function handleInquiryList(array $vars = []): void
    {
        $this->requirePermission('bookings:manage');
        $inquiries = (new InquiryService())->getAll();
        echo json_encode(['inquiries' => $inquiries]);
    }

    /** @param array<string, string> $vars */
    private function handleInquiryMine(array $vars = []): void
    {
        $session = $this->requireAuth();
        $inquiries = (new InquiryService())->getAllForUser($session['sub']);
        echo json_encode(['inquiries' => $inquiries]);
    }

    /** @param array<string, string> $vars */
    private function handleInquiryGet(array $vars = []): void
    {
        $this->requirePermission('bookings:manage');
        $id = $vars['id'] ?? '';
        if ($id === '') throw new RuntimeException('Inquiry ID is required.', 400);

        $inquiry = (new InquiryService())->getById($id);
        if (!$inquiry) throw new RuntimeException('Inquiry not found.', 404);

        echo json_encode(['inquiry' => $inquiry]);
    }

    /** @param array<string, string> $vars */
    private function handleInquiryActivity(array $vars = []): void
    {
        $this->requirePermission('bookings:manage');
        $id = $vars['id'] ?? '';
        if ($id === '') throw new RuntimeException('Inquiry ID is required.', 400);

        $activities = (new InquiryActivityService())->getForInquiry($id);
        echo json_encode($activities);
    }

    /** @param array<string, string> $vars */
    private function handleInquiryCalendar(array $vars = []): void
    {
        $inquiries = (new InquiryService())->getAll();
        $events = array_map(static fn (array $inquiry): array => [
            'id' => (string) ($inquiry['id'] ?? ''),
            'fullName' => (string) ($inquiry['fullName'] ?? ''),
            'contactNumber' => (string) ($inquiry['contactNumber'] ?? ''),
            'emailAddress' => (string) ($inquiry['emailAddress'] ?? ''),
            'facebookName' => (string) ($inquiry['facebookName'] ?? ''),
            'plateNumber' => (string) ($inquiry['plateNumber'] ?? ''),
            'appointmentDate' => (string) ($inquiry['appointmentDate'] ?? ''),
            'appointmentTime' => (string) ($inquiry['appointmentTime'] ?? ''),
            'make' => (string) ($inquiry['make'] ?? ''),
            'model' => (string) ($inquiry['model'] ?? ''),
            'productToPurchase' => (string) ($inquiry['productToPurchase'] ?? ''),
            'status' => (string) ($inquiry['status'] ?? 'pending'),
        ], $inquiries);

        echo json_encode(['events' => $events]);
    }

    /** @param array<string, string> $vars */
    private function handleInquiryAvailability(array $vars = []): void
    {
        $date = trim((string) ($_GET['date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new RuntimeException('A valid date parameter (YYYY-MM-DD) is required.', 422);
        }

        $svc = new ShopHoursService();
        $dayHours = $svc->getForDate($date);
        $allSlots = $svc->generateSlots($dayHours);

        $inquirySvc = new InquiryService();
        $availability = $dayHours['isOpen']
            ? $inquirySvc->getAvailabilityForDate($date, $allSlots)
            : [
                'availableSlots' => [],
                'bookedSlots' => [],
                'slotCounts' => [],
                'slotCapacity' => 2,
            ];

        echo json_encode([
            'isOpen'         => $dayHours['isOpen'],
            'openTime'       => $dayHours['openTime'],
            'closeTime'      => $dayHours['closeTime'],
            'slotIntervalH'  => $dayHours['slotIntervalH'],
            'closureReason'  => $dayHours['closureReason'] ?? null,
            'availableSlots' => $availability['availableSlots'],
            'bookedSlots'    => $availability['bookedSlots'],
            'slotCapacity'   => $availability['slotCapacity'],
            'slotCounts'     => $availability['slotCounts'],
        ]);
    }

    private function handleChecklistLookup(array $vars = []): void
    {
        $q = trim((string) ($_GET['q'] ?? $_GET['ref'] ?? ''));

        $token = Auth::tokenFromHeader();
        $isAdmin = false;
        if ($token !== null) {
            try {
                $payload = Auth::decodeToken($token);
                $isAdmin = $this->hasPermissionByRole((string) ($payload['role'] ?? ''), 'bookings:manage');
            } catch (\Throwable) {}
        }

        $db = Database::getInstance();
        $results = [];

        if ($q !== '') {
            $likeQuery = '%' . $q . '%';
            $alphanumericOnly = preg_replace('/[^A-Za-z0-9]/', '', $q);
            $cleanQuery = '%' . ($alphanumericOnly !== '' ? $alphanumericOnly : $q) . '%';

            // 1. Search customer inquiries by plate_number (raw & stripped), reference_number, name, email, phone, or make/model
            $stmtInq = $db->prepare('
                SELECT ci.id, ci.reference_number, ci.full_name, ci.email_address, ci.contact_number,
                       ci.make, ci.model, ci.year_model, ci.plate_number, ci.product_to_purchase,
                       ci.service_id, ci.appointment_date, s.title AS service_title
                FROM customer_inquiries ci
                LEFT JOIN services s ON s.id = ci.service_id
                WHERE ci.plate_number LIKE :q1
                   OR REPLACE(REPLACE(REPLACE(COALESCE(ci.plate_number, ""), " ", ""), "-", ""), "_", "") LIKE :q2
                   OR ci.reference_number LIKE :q3
                   OR REPLACE(REPLACE(COALESCE(ci.reference_number, ""), "-", ""), "_", "") LIKE :q4
                   OR ci.full_name LIKE :q5
                   OR ci.email_address LIKE :q6
                   OR ci.contact_number LIKE :q7
                   OR ci.make LIKE :q8
                   OR ci.model LIKE :q9
                ORDER BY ci.created_at DESC
                LIMIT 10
            ');
            $stmtInq->execute([
                ':q1' => $likeQuery,
                ':q2' => $cleanQuery,
                ':q3' => $likeQuery,
                ':q4' => $cleanQuery,
                ':q5' => $likeQuery,
                ':q6' => $likeQuery,
                ':q7' => $likeQuery,
                ':q8' => $likeQuery,
                ':q9' => $likeQuery,
            ]);
            $rawRows = $stmtInq->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            // 2. Search bookings by vehicle_info (raw & stripped), plate_number, reference_number, name, email, phone, or make/model
            $stmtBk = $db->prepare('
                SELECT b.id, b.reference_number, b.name, b.email, b.phone,
                       b.vehicle_make, b.vehicle_model, b.vehicle_year, b.vehicle_info,
                       b.service_id, s.title AS service_name, b.appointment_date
                FROM bookings b
                LEFT JOIN services s ON s.id = b.service_id
                WHERE b.vehicle_info LIKE :b1
                   OR REPLACE(REPLACE(REPLACE(COALESCE(b.vehicle_info, ""), " ", ""), "-", ""), "_", "") LIKE :b2
                   OR b.reference_number LIKE :b3
                   OR REPLACE(REPLACE(COALESCE(b.reference_number, ""), "-", ""), "_", "") LIKE :b4
                   OR b.name LIKE :b5
                   OR b.email LIKE :b6
                   OR b.phone LIKE :b7
                   OR b.vehicle_make LIKE :b8
                   OR b.vehicle_model LIKE :b9
                ORDER BY b.created_at DESC
                LIMIT 10
            ');
            $stmtBk->execute([
                ':b1' => $likeQuery,
                ':b2' => $cleanQuery,
                ':b3' => $likeQuery,
                ':b4' => $cleanQuery,
                ':b5' => $likeQuery,
                ':b6' => $likeQuery,
                ':b7' => $likeQuery,
                ':b8' => $likeQuery,
                ':b9' => $likeQuery,
            ]);
            $rawBookings = $stmtBk->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } else {
            // When query is empty (recent list overview for admin)
            $stmtInq = $db->query('
                SELECT ci.id, ci.reference_number, ci.full_name, ci.email_address, ci.contact_number,
                       ci.make, ci.model, ci.year_model, ci.plate_number, ci.product_to_purchase,
                       ci.service_id, ci.appointment_date, s.title AS service_title
                FROM customer_inquiries ci
                LEFT JOIN services s ON s.id = ci.service_id
                ORDER BY ci.created_at DESC
                LIMIT 10
            ');
            $rawRows = $stmtInq->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            $stmtBk = $db->query('
                SELECT b.id, b.reference_number, b.name, b.email, b.phone,
                       b.vehicle_make, b.vehicle_model, b.vehicle_year, b.vehicle_info,
                       b.service_id, s.title AS service_name, b.appointment_date
                FROM bookings b
                LEFT JOIN services s ON s.id = b.service_id
                ORDER BY b.created_at DESC
                LIMIT 10
            ');
            $rawBookings = $stmtBk->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        }

        foreach ($rawRows as $row) {
            $results[] = [
                'type' => 'inquiry',
                'id' => (string) $row['id'],
                'referenceNumber' => (string) ($row['reference_number'] ?? $row['id']),
                'customerName' => (string) ($row['full_name'] ?? ''),
                'customerEmail' => (string) ($row['email_address'] ?? ''),
                'contactNumber' => (string) ($row['contact_number'] ?? ''),
                'vehicleMake' => (string) ($row['make'] ?? ''),
                'vehicleModel' => (string) ($row['model'] ?? ''),
                'vehicleYear' => (string) ($row['year_model'] ?? ''),
                'plateNumber' => (string) ($row['plate_number'] ?? ''),
                'productToPurchase' => (string) ($row['product_to_purchase'] ?? ''),
                'serviceId' => $row['service_id'] ? (int) $row['service_id'] : null,
                'serviceName' => (string) ($row['service_title'] ?? $row['product_to_purchase'] ?? ''),
                'appointmentDate' => (string) ($row['appointment_date'] ?? ''),
            ];
        }

        foreach ($rawBookings as $row) {
            $plate = '';
            $info = (string) ($row['vehicle_info'] ?? '');
            if (preg_match('/[A-Z0-9]{2,4}[-\s]?[0-9]{3,4}/i', $info, $m)) {
                $plate = strtoupper($m[0]);
            }
            $results[] = [
                'type' => 'booking',
                'id' => (string) $row['id'],
                'referenceNumber' => (string) ($row['reference_number'] ?? $row['id']),
                'customerName' => (string) ($row['name'] ?? ''),
                'customerEmail' => (string) ($row['email'] ?? ''),
                'contactNumber' => (string) ($row['phone'] ?? ''),
                'vehicleMake' => (string) ($row['vehicle_make'] ?? ''),
                'vehicleModel' => (string) ($row['vehicle_model'] ?? ''),
                'vehicleYear' => (string) ($row['vehicle_year'] ?? ''),
                'plateNumber' => $plate ?: $info,
                'productToPurchase' => (string) ($row['service_name'] ?? ''),
                'serviceId' => $row['service_id'] ? (int) $row['service_id'] : null,
                'serviceName' => (string) ($row['service_name'] ?? ''),
                'appointmentDate' => (string) ($row['appointment_date'] ?? ''),
            ];
        }

        echo json_encode(['results' => $results]);
    }

    /** @param array<string, string> $vars */
    private function handleInquiryUpdate(array $vars = []): void
    {
        $payload = $this->requirePermission('bookings:manage');
        $id = $vars['id'] ?? '';
        $data = $this->jsonBody();

        if (empty($data)) {
            throw new RuntimeException('No changes were provided.', 422);
        }

        $inquiry = (new InquiryService())->updateFullInquiry(
            $id,
            $data,
            (int) ($payload['sub'] ?? 0) ?: null
        );

        $status = isset($data['status']) ? trim((string) $data['status']) : '';
        $appointmentDate = array_key_exists('appointmentDate', $data) || array_key_exists('appointment_date', $data) ? trim((string) ($data['appointmentDate'] ?? $data['appointment_date'])) : null;
        $appointmentTime = array_key_exists('appointmentTime', $data) || array_key_exists('appointment_time', $data) ? trim((string) ($data['appointmentTime'] ?? $data['appointment_time'])) : null;

        // Re-queue the 3-hour appointment reminder when the schedule or status changes.
        try {
            $queue = new NotificationJobQueueService();
            $newStatus = strtolower(trim((string) ($inquiry['status'] ?? '')));
            $isTerminal = in_array($newStatus, ['cancelled', 'completed'], true);

            // Cancel any existing queued/retry reminder for this inquiry
            if (DB_NAME !== '') {
                $db = Database::getInstance();
                // Mark old pending reminder jobs for this inquiry as done so they are not fired
                $cancelStmt = $db->prepare(
                    "UPDATE notification_jobs
                        SET status = 'done',
                            last_error = 'superseded by inquiry update',
                            processed_at = NOW()
                      WHERE event = 'appointment_reminder_3h'
                        AND status IN ('queued', 'retry')
                        AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.data.id')) = :inquiry_id"
                );
                $cancelStmt->execute([':inquiry_id' => $id]);
            }

            // Dispatch a fresh reminder only if the appointment is still upcoming
            if (!$isTerminal) {
                $date = trim((string) ($inquiry['appointmentDate'] ?? ''));
                $time = trim((string) ($inquiry['appointmentTime'] ?? ''));
                $runAfter = NotificationJobQueueService::calculateReminderRunAfter($date, $time, 3);
                if ($runAfter !== null) {
                    $queue->dispatch('appointment_reminder_3h', ['data' => $inquiry], $runAfter);
                }
            }
        } catch (\Throwable $e) {
            error_log('[handleInquiryUpdate] Failed to re-queue appointment reminder: ' . $e->getMessage());
        }

        // Notify the customer via email/sms when the status has changed.
        if ($status !== '') {
            try {
                (new NotificationJobQueueService())->dispatch('inquiry_status_changed', [
                    'inquiry' => $inquiry,
                ]);
            } catch (\Throwable $e) {
                error_log('[handleInquiryUpdate] Failed to dispatch inquiry_status_changed notification: ' . $e->getMessage());
            }
        }

        // Notify the customer and admins if the appointment was rescheduled
        if ($appointmentDate !== null || $appointmentTime !== null) {
            try {
                (new NotificationJobQueueService())->dispatch('inquiry_rescheduled', [
                    'inquiry' => $inquiry,
                ]);
            } catch (\Throwable $e) {
                error_log('[handleInquiryUpdate] Failed to dispatch inquiry_rescheduled notification: ' . $e->getMessage());
            }
        }

        // Sync updated inquiry details (including date, time, and status) to Google Sheets
        GoogleSheetsSyncService::syncInquiry($inquiry);

        echo json_encode(['inquiry' => $inquiry]);
    }

    /** @param array<string, string> $vars */
    private function handleInquiryDelete(array $vars = []): void
    {
        $this->requirePermission('bookings:manage');
        $id = $vars['id'] ?? '';

        if ($id === '') {
            throw new RuntimeException('Inquiry ID is required.', 400);
        }

        (new InquiryService())->delete($id);
        echo json_encode(['deleted' => true]);
    }

    /** @param array<string, string> $vars */
    private function handleInquiryInternalNotes(array $vars = []): void
    {
        $payload = $this->requirePermission('bookings:manage');
        $id = $vars['id'] ?? '';

        if ($id === '') {
            throw new RuntimeException('Inquiry ID is required.', 400);
        }

        $input = $this->jsonBody();
        $notes = mb_substr((string) ($input['internalNotes'] ?? ''), 0, 5000);

        $userId = (int) ($payload['sub'] ?? 0) ?: null;
        if ($userId === null) {
            throw new RuntimeException('User not authenticated.', 401);
        }
        $userRole = $payload['role'] ?? 'unknown';

        $inquiry = (new InquiryService())->updateInternalNotes(
            $id,
            $notes,
            $userId,
            $userRole
        );
        GoogleSheetsSyncService::syncInquiry($inquiry);
        echo json_encode(['inquiry' => $inquiry]);
    }

    private function handleInquiryUpdateService(array $vars = []): void
    {
        $this->requirePermission('bookings:manage');
        $id = trim((string) ($vars['id'] ?? ''));
        if ($id === '') {
            throw new RuntimeException('Inquiry ID is required.', 400);
        }
        $data = $this->jsonBody();
        $serviceId = isset($data['serviceId']) && $data['serviceId'] !== null ? (int) $data['serviceId'] : null;

        $userId = null;
        $token = Auth::tokenFromHeader();
        if ($token !== null) {
            try {
                $payload = Auth::decodeToken($token);
                $userId = (int) ($payload['sub'] ?? 0);
            } catch (RuntimeException) {
                // Ignore
            }
        }

        $inquiry = (new InquiryService())->updateServiceId(
            $id,
            $serviceId,
            $userId
        );

        GoogleSheetsSyncService::syncInquiry($inquiry);

        echo json_encode(['inquiry' => $inquiry]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminInquiryLinkGuests(array $vars = []): void
    {
        $this->requirePermission('bookings:manage');

        if (DB_NAME === '') {
            throw new RuntimeException('Database not configured.', 500);
        }

        $db = Database::getInstance();

        // Find all guest inquiries that have a matching registered user by email
        $select = $db->query(
            "SELECT ci.id AS inquiry_id, u.id AS user_id
               FROM customer_inquiries ci
               INNER JOIN users u
                  ON LOWER(TRIM(ci.email_address)) = LOWER(TRIM(u.email))
              WHERE ci.user_id IS NULL"
        );
        $matches = $select->fetchAll(PDO::FETCH_ASSOC);

        $linked  = 0;
        $skipped = 0;

        foreach ($matches as $row) {
            $update = $db->prepare(
                'UPDATE customer_inquiries SET user_id = :uid WHERE id = :id AND user_id IS NULL'
            );
            $update->execute([':uid' => (string) $row['user_id'], ':id' => (string) $row['inquiry_id']]);
            if ($update->rowCount() > 0) {
                $linked++;
            } else {
                $skipped++;
            }
        }

        echo json_encode([
            'linked'          => $linked,
            'skipped'         => $skipped,
            'totalCandidates' => count($matches),
            'message'         => "{$linked} guest inquiry/inquiries successfully linked to user accounts.",
        ]);
    }

    /**
     * Handle external booking creation (chatbot, integrations, etc).
     * Accepts a JSON payload and sets source to 'chatbot'.
     * No authentication required.
     * @param array<string, string> $vars
     */
    private function handleBookingExternalCreate(array $vars = []): void
    {
        try {
            $data = $this->jsonBody();

            // Accept common integration aliases (snake_case and alternate key names).
            $aliases = [
                'service_id'       => 'serviceId',
                'service_ids'      => 'serviceIds',
                'service_name'     => 'serviceName',
                'vehicle_info'     => 'vehicleInfo',
                'vehicle_make'     => 'vehicleMake',
                'vehicle_model'    => 'vehicleModel',
                'vehicle_year'     => 'vehicleYear',
                'appointment_date' => 'appointmentDate',
                'appointment_time' => 'appointmentTime',
                'signature_data'   => 'signatureData',
            ];
            foreach ($aliases as $from => $to) {
                if (!isset($data[$to]) && isset($data[$from])) {
                    $data[$to] = $data[$from];
                }
            }

            // Preserve note/message style fields as booking notes.
            if (!isset($data['notes'])) {
                foreach (['note', 'message', 'customer_note', 'customer_notes'] as $key) {
                    if (isset($data[$key]) && trim((string) $data[$key]) !== '') {
                        $data['notes'] = (string) $data[$key];
                        break;
                    }
                }
            }

            // Normalize media aliases to mediaUrls expected by BookingService.
            if (!isset($data['mediaUrls'])) {
                foreach (['images', 'imageUrls', 'image_urls', 'photoUrls', 'photo_urls'] as $key) {
                    if (!isset($data[$key])) {
                        continue;
                    }
                    if (is_array($data[$key])) {
                        $data['mediaUrls'] = $data[$key];
                    } elseif (is_string($data[$key]) && trim($data[$key]) !== '') {
                        $data['mediaUrls'] = [trim($data[$key])];
                    }
                    break;
                }
            }

            if (isset($data['mediaUrls']) && is_array($data['mediaUrls'])) {
                $data['mediaUrls'] = array_values(array_filter(
                    array_map(static fn ($v) => is_string($v) ? trim($v) : '', $data['mediaUrls']),
                    static fn (string $v): bool => $v !== ''
                ));
            }

            if (trim((string) ($data['source'] ?? '')) === '') {
                $data['source'] = 'chatbot';
            }

            // Return structured field validation errors before service call.
            $fieldErrors = [];
            if (trim((string) ($data['name'] ?? '')) === '') {
                $fieldErrors['name'] = 'Name is required.';
            }
            if (trim((string) ($data['phone'] ?? '')) === '') {
                $fieldErrors['phone'] = 'Phone is required.';
            }
            if (trim((string) ($data['appointmentDate'] ?? '')) === '') {
                $fieldErrors['appointmentDate'] = 'Appointment date is required.';
            }

            $hasServiceId = isset($data['serviceId']) && trim((string) $data['serviceId']) !== '';
            $hasServiceIds = isset($data['serviceIds']) && is_array($data['serviceIds']) && count($data['serviceIds']) > 0;
            $hasServiceName = isset($data['serviceName']) && trim((string) $data['serviceName']) !== '';
            if (!$hasServiceId && !$hasServiceIds && !$hasServiceName) {
                $fieldErrors['service'] = 'Provide serviceId, serviceIds, or serviceName.';
            }

            if ($fieldErrors !== []) {
                http_response_code(422);
                echo json_encode([
                    'error' => 'validation_error',
                    'message' => 'External booking validation failed.',
                    'fields' => $fieldErrors,
                ]);
                return;
            }

            $booking = (new BookingService())->create($data, null);
            http_response_code(201);
            echo json_encode(['booking' => $booking]);
        } catch (RuntimeException $e) {
            $status = (int) $e->getCode();
            if ($status < 400 || $status > 599) {
                $status = 400;
            }

            http_response_code($status);
            echo json_encode([
                'error' => $status >= 500 ? 'internal_error' : 'request_error',
                'message' => $status >= 500 ? 'Unable to create external booking.' : $e->getMessage(),
                'detail' => $status >= 500 ? null : $e->getMessage(),
            ]);
        } catch (\Throwable) {
            http_response_code(500);
            echo json_encode([
                'error' => 'internal_error',
                'message' => 'Unable to create external booking.',
            ]);
        }
    }

    /** @param array<string, string> $vars */
    private function handleBookingList(array $vars = []): void
    {
        $payload = $this->requirePermission('bookings:manage');
        $bookings = $this->getAccessibleBookingsForPayload($payload);
        echo json_encode(['bookings' => $bookings]);
    }

    /** @param array<string, string> $vars */
    private function handleBookingMine(array $vars = []): void
    {
        $payload  = $this->requireAuth();
        $bookings = (new BookingService())->getByUserId((int) $payload['sub']);
        echo json_encode(['bookings' => $bookings]);
    }

    /** @param array<string, string> $vars */
    private function handleBookingGet(array $vars = []): void
    {
        $payload = $this->requireAuth();
        $id      = $vars['id'] ?? '';
        $userId  = (int) ($payload['sub'] ?? 0);

        if ($this->hasPermission($payload, 'bookings:manage')) {
            $booking = $this->requireBookingVisibilityForPayload($payload, $id);
            echo json_encode(['booking' => $booking]);
            return;
        }

        $booking = (new BookingService())->getById($id, $userId);
        echo json_encode(['booking' => $booking]);
    }

    /** @param array<string, string> $vars */
    private function handleBookingActivityList(array $vars = []): void
    {
        if (DB_NAME === '') {
            echo json_encode(['logs' => []]);
            return;
        }

        $payload = $this->requireAuth();
        $id      = $vars['id'] ?? '';
        $userId  = (int) ($payload['sub'] ?? 0);

        if ($this->hasPermission($payload, 'bookings:manage')) {
            $this->requireBookingVisibilityForPayload($payload, $id);
        } else {
            (new BookingService())->getById($id, $userId);
        }

        $logs = (new BookingActivityService())->getForBooking($id);
        echo json_encode(['logs' => $logs]);
    }

    /** @param array<string, string> $vars */
    private function handleBookingReschedule(array $vars = []): void
    {
        $payload = $this->requireAuth();
        $id      = $vars['id'] ?? '';
        $userId  = (int) ($payload['sub'] ?? 0);
        $data    = $this->jsonBody();

        $date = trim((string) ($data['appointmentDate'] ?? ''));
        $time = trim((string) ($data['appointmentTime'] ?? ''));

        if ($date === '') {
            throw new RuntimeException('appointmentDate is required.', 422);
        }
        if ($time === '') {
            throw new RuntimeException('appointmentTime is required.', 422);
        }

        $booking = (new BookingService())->reschedule($id, $userId, $date, $time);
        echo json_encode(['booking' => $booking]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminBookingReschedule(array $vars = []): void
    {
        $payload = $this->requirePermission('bookings:manage');
        $id   = $vars['id'] ?? '';
        $data = $this->jsonBody();
        $this->requireBookingMutationForPayload($payload, $id);

        $date = trim((string) ($data['appointmentDate'] ?? ''));
        $time = trim((string) ($data['appointmentTime'] ?? ''));

        if ($date === '') {
            throw new RuntimeException('appointmentDate is required.', 422);
        }
        if ($time === '') {
            throw new RuntimeException('appointmentTime is required.', 422);
        }

        $booking = (new BookingService())->adminReschedule(
            $id,
            $date,
            $time,
            (int) ($payload['sub'] ?? 0) ?: null,
            'admin'
        );
        echo json_encode(['booking' => $booking]);
    }

    /** @param array<string, string> $vars */
    private function handleBookingUpdate(array $vars = []): void
    {
        $payload = $this->requirePermission('bookings:manage');
        $id     = $vars['id'] ?? '';
        $data   = $this->jsonBody();
        $status = (string) ($data['status'] ?? '');

        $this->requireBookingMutationForPayload($payload, $id);

        $booking = (new BookingService())->updateStatus(
            $id,
            $status,
            (int) ($payload['sub'] ?? 0) ?: null,
            'admin'
        );
        echo json_encode(['booking' => $booking]);
    }

    /** @param array<string, string> $vars */
    private function handleBookingDelete(array $vars = []): void
    {
        $payload = $this->requirePermission('bookings:manage');
        $id = $vars['id'] ?? '';

        $this->requireBookingMutationForPayload($payload, $id);

        (new BookingService())->delete($id);
        echo json_encode(['deleted' => true]);
    }

    /** @param array<string, string> $vars */
    private function handleBookingAssignTech(array $vars = []): void
    {
        $payload = $this->requirePermission('bookings:assign-tech');
        $id   = $vars['id'] ?? '';
        $data = $this->jsonBody();

        $this->requireBookingMutationForPayload($payload, $id);

        $rawUserId = $data['assignedUserId'] ?? ($data['assigned_user_id'] ?? null);
        $rawTechId = $data['assignedTechId'] ?? ($data['assigned_tech_id'] ?? null);
        $assignedTechId = null;
        if ($rawUserId !== null && $rawUserId !== '') {
            $assignedUserId = (int) $rawUserId;
            if ($assignedUserId <= 0) {
                throw new RuntimeException('assignedUserId must be a positive integer or null.', 422);
            }

            $teamMemberService = new TeamMemberService();
            $existing = $teamMemberService->findByUserId($assignedUserId);

            if ($existing) {
                $assignedTechId = (int) ($existing['id'] ?? 0);
            } else {
                $created = $teamMemberService->create([
                    'userId' => $assignedUserId,
                    'sortOrder' => 999,
                    'isActive' => true,
                ]);
                $assignedTechId = (int) ($created['id'] ?? 0);
            }

            if ($assignedTechId <= 0) {
                throw new RuntimeException('Unable to link selected user to a technician profile.', 422);
            }
        } elseif ($rawTechId !== null && $rawTechId !== '') {
            $assignedTechId = (int) $rawTechId;
            if ($assignedTechId <= 0) {
                throw new RuntimeException('assignedTechId must be a positive integer or null.', 422);
            }
        }

        $booking = (new BookingService())->assignTechnician(
            $id,
            $assignedTechId,
            (int) ($payload['sub'] ?? 0) ?: null,
            'admin'
        );
        echo json_encode(['booking' => $booking]);
    }

    /** @param array<string, string> $vars */
    private function handleBookingAvailability(array $vars = []): void
    {
        $date = trim((string) ($_GET['date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new RuntimeException('A valid date parameter (YYYY-MM-DD) is required.', 422);
        }

        $svc      = new ShopHoursService();
        $dayHours = $svc->getForDate($date);
        $allSlots = $svc->generateSlots($dayHours);

        $bookingSvc  = new BookingService();
        $bookedSlots = $dayHours['isOpen']
            ? $bookingSvc->getBookedSlots($date)
            : [];
        $slotCounts  = $dayHours['isOpen']
            ? $bookingSvc->getSlotCounts($date)
            : [];

        echo json_encode([
            'isOpen'         => $dayHours['isOpen'],
            'openTime'       => $dayHours['openTime'],
            'closeTime'      => $dayHours['closeTime'],
            'slotIntervalH'  => $dayHours['slotIntervalH'],
            'closureReason'  => $dayHours['closureReason'] ?? null,
            'availableSlots' => $allSlots,
            'bookedSlots'    => $bookedSlots,
            'slotCapacity'   => $bookingSvc->getSlotCapacity(),
            'slotCounts'     => $slotCounts,
        ]);
    }

    /** @param array<string, string> $vars */
    private function handleShopHoursGet(array $vars = []): void
    {
        $hours = (new ShopHoursService())->getAll();
        echo json_encode(['hours' => $hours]);
    }

    /** @param array<string, string> $vars */
    private function handleShopHoursPut(array $vars = []): void
    {
        $this->requirePermission('shop-hours:manage');
        $data  = $this->jsonBody();
        $input = $data['hours'] ?? $data; // accept both {hours:[...]} and [...] directly

        if (!is_array($input)) {
            throw new RuntimeException('Expected an array of day-hour objects.', 422);
        }

        $updated = (new ShopHoursService())->updateAll(array_values($input));
        echo json_encode(['hours' => $updated]);
    }

    /** @param array<string, string> $vars */
    private function handleShopClosedDatesGet(array $vars = []): void
    {
        $dates = (new ShopHoursService())->getClosedDates();
        echo json_encode(['closedDates' => $dates]);
    }

    /** @param array<string, string> $vars */
    private function handleShopClosedDatesAdd(array $vars = []): void
    {
        $this->requirePermission('shop-hours:manage');
        $data     = $this->jsonBody();
        $date     = trim((string) ($data['date']   ?? ''));
        $reason   = isset($data['reason']) ? trim((string) $data['reason']) : null;
        $isYearly = (bool) ($data['isYearly'] ?? false);
        if ($reason === '') $reason = null;

        (new ShopHoursService())->addClosedDate($date, $reason, $isYearly);
        $dates = (new ShopHoursService())->getClosedDates();
        echo json_encode(['closedDates' => $dates]);
    }

    /** @param array<string, string> $vars */
    private function handleShopClosedDatesRemove(array $vars = []): void
    {
        $this->requirePermission('shop-hours:manage');
        $date = trim($vars['date'] ?? '');
        (new ShopHoursService())->removeClosedDate($date);
        $dates = (new ShopHoursService())->getClosedDates();
        echo json_encode(['closedDates' => $dates]);
    }

    /** @param array<string, string> $vars */
    private function handleTestSheetsWebhook(array $vars = []): void
    {
        $auth = $this->requireAuth();
        if (($auth['role'] ?? '') === 'client') {
            throw new RuntimeException('Permission denied.', 403);
        }
        $data = $this->jsonBody();
        $url  = trim((string) ($data['url'] ?? ''));

        if ($url === '') {
            $settings = (new SiteSettingsService())->getAll();
            $url = trim((string) ($settings['google_sheets_webhook_url'] ?? ''));
        }

        if ($url === '') {
            throw new RuntimeException('Google Sheets Webhook URL is not configured.', 422);
        }

        $result = GoogleSheetsSyncService::testWebhook($url);
        echo json_encode($result);
    }

    /** @param array<string, string> $vars */
    private function handleSyncAllSheets(array $vars = []): void
    {
        $auth = $this->requireAuth();
        if (($auth['role'] ?? '') === 'client') {
            throw new RuntimeException('Permission denied.', 403);
        }

        @set_time_limit(180);

        $inquiries = (new InquiryService())->getAll();
        $result = GoogleSheetsSyncService::pushAllToSheets($inquiries);

        echo json_encode([
            'success' => true,
            'syncedCount' => $result['syncedCount'] ?? count($inquiries),
        ]);
    }

    /** @param array<string, string> $vars */
    private function handleGoogleSheetsInbound(array $vars = []): void
    {
        $data = $this->jsonBody();
        $secret = $_SERVER['HTTP_X_SHEETS_SECRET'] ?? null;
        $result = GoogleSheetsSyncService::processInboundSync($data, $secret);
        echo json_encode($result);
    }

    /** @param array<string, string> $vars */
    private function handleGoogleSheetsAllInquiries(array $vars = []): void
    {
        $settings = (new SiteSettingsService())->getAll();
        $configuredSecret = (defined('GOOGLE_SHEETS_SYNC_SECRET') && GOOGLE_SHEETS_SYNC_SECRET !== '')
            ? GOOGLE_SHEETS_SYNC_SECRET
            : trim((string) ($settings['google_sheets_sync_secret'] ?? ''));
        if ($configuredSecret !== '') {
            $candidate = trim((string) (
                ($_SERVER['HTTP_X_SHEETS_SECRET'] ?? '')
                ?? ($_GET['secret'] ?? '')
                ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '')
            ));
            if (str_starts_with($candidate, 'Bearer ')) {
                $candidate = substr($candidate, 7);
            }
            if ($candidate === '' || !hash_equals($configuredSecret, $candidate)) {
                throw new RuntimeException('Invalid or missing Google Sheets webhook secret key.', 401);
            }
        }

        $inquiries = (new InquiryService())->getAll();
        echo json_encode(['success' => true, 'inquiries' => $inquiries]);
    }

    /** @param array<string, string> $vars */
    private function handlePullSheets(array $vars = []): void
    {
        $auth = $this->requireAuth();
        if (($auth['role'] ?? '') === 'client') {
            throw new RuntimeException('Permission denied.', 403);
        }

        $result = GoogleSheetsSyncService::pullAllFromSheets();
        echo json_encode($result);
    }

    /** @param array<string, string> $vars */
    private function handleGetSheetsScript(array $vars = []): void
    {
        $auth = $this->requireAuth();
        if (($auth['role'] ?? '') === 'client') {
            throw new RuntimeException('Permission denied.', 403);
        }

        $script = GoogleSheetsSyncService::getAppsScriptTemplate();
        echo json_encode(['success' => true, 'script' => $script]);
    }

    /** @param array<string, string> $vars */
    private function handleBookingMediaUpload(array $vars = []): void
    {
        if (empty($_FILES['files'])) {
            throw new RuntimeException('No files provided.', 422);
        }

        $allowed  = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $maxBytes = UPLOAD_MAX_MB * 1024 * 1024;
        $urls     = [];
        $storage  = new UploadStorage();

        // $_FILES['files'] may be a single file or multiple (files[])
        $files = $_FILES['files'];
        $count = is_array($files['name']) ? count($files['name']) : 1;

        for ($i = 0; $i < $count; $i++) {
            $tmpName = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
            $mime    = is_array($files['type'])     ? $files['type'][$i]     : $files['type'];
            $size    = is_array($files['size'])     ? $files['size'][$i]     : $files['size'];
            $error   = is_array($files['error'])    ? $files['error'][$i]    : $files['error'];

            if ($error !== UPLOAD_ERR_OK) {
                throw new RuntimeException("File upload error (code $error).", 422);
            }
            if (!in_array($mime, $allowed, true) || !@getimagesize($tmpName)) {
                throw new RuntimeException("Only JPEG, PNG, WebP and GIF images are accepted.", 422);
            }
            if ($size > $maxBytes) {
                throw new RuntimeException("Each file must be under " . UPLOAD_MAX_MB . " MB.", 422);
            }

            $ext      = pathinfo((is_array($files['name']) ? $files['name'][$i] : $files['name']), PATHINFO_EXTENSION);
            $filename = bin2hex(random_bytes(16)) . '.' . strtolower($ext);

            $urls[] = $storage->upload($tmpName, $filename, $mime, 'bookings/');
        }

        echo json_encode(['urls' => $urls]);
    }

    /** @param array<string, string> $vars */
    private function handleBookingPartsUpdate(array $vars = []): void
    {
        $payload = $this->requirePermission('bookings:manage');
        $id          = $vars['id'] ?? '';
        $data        = $this->jsonBody();
        $waiting     = (bool) ($data['awaitingParts'] ?? false);
        $partsNotes  = trim((string) ($data['partsNotes'] ?? ''));

        $this->requireBookingMutationForPayload($payload, $id);

        $booking = (new BookingService())->updatePartsStatus(
            $id,
            $waiting,
            $partsNotes,
            (int) ($payload['sub'] ?? 0) ?: null,
            'admin'
        );
        echo json_encode(['booking' => $booking]);
    }

    /** @param array<string, string> $vars */
    private function handleBookingPartRequirementList(array $vars = []): void
    {
        $payload = $this->requirePermission('bookings:manage');
        $bookingId = (string) ($vars['id'] ?? '');
        $this->requireBookingVisibilityForPayload($payload, $bookingId);
        $requirements = (new InventoryService())->listBookingPartRequirements($bookingId);
        echo json_encode(['requirements' => $requirements]);
    }

    /** @param array<string, string> $vars */
    private function handleBookingPartRequirementCreate(array $vars = []): void
    {
        $payload = $this->requirePermission('bookings:manage');
        $bookingId = (string) ($vars['id'] ?? '');
        $data = $this->jsonBody();
        $this->requireBookingMutationForPayload($payload, $bookingId);
        $requirement = (new InventoryService())->createBookingPartRequirement($bookingId, $data, (int) ($payload['sub'] ?? 0) ?: null);
        http_response_code(201);
        echo json_encode(['requirement' => $requirement]);
    }

    /** @param array<string, string> $vars */
    private function handleBookingPartRequirementUpdate(array $vars = []): void
    {
        $payload = $this->requirePermission('bookings:manage');
        $bookingId = (string) ($vars['id'] ?? '');
        $reqId = (int) ($vars['rid'] ?? 0);
        $data = $this->jsonBody();
        $this->requireBookingMutationForPayload($payload, $bookingId);
        $requirement = (new InventoryService())->updateBookingPartRequirement($bookingId, $reqId, $data);
        echo json_encode(['requirement' => $requirement]);
    }

    /** @param array<string, string> $vars */
    private function handleBookingQaPhotosUpdate(array $vars = []): void
    {
        $payload = $this->requirePermission('bookings:manage');
        $id      = $vars['id'] ?? '';
        $data    = $this->jsonBody();

        $this->requireBookingMutationForPayload($payload, $id);

        $stage = trim((string) ($data['stage'] ?? ''));
        $photoUrls = array_values(array_filter(
            is_array($data['photoUrls'] ?? null) ? $data['photoUrls'] : [],
            fn($v) => is_string($v) && trim($v) !== ''
        ));

        $booking = (new BookingService())->updateQaPhotos(
            $id,
            $stage,
            $photoUrls,
            (int) ($payload['sub'] ?? 0) ?: null
        );

        echo json_encode(['booking' => $booking]);
    }

    /** @param array<string, string> $vars */
    private function handleBookingCancel(array $vars = []): void
    {
        $payload = $this->requireAuth();
        $id      = $vars['id'] ?? '';
        $userId  = (int) ($payload['sub'] ?? 0);

        $booking = (new BookingService())->cancelByUser($id, $userId);
        echo json_encode(['booking' => $booking]);
    }

    // -------------------------------------------------------------------------
    // Build-update handlers
    // -------------------------------------------------------------------------

    /** @param array<string, string> $vars */
    private function handleBuildUpdateList(array $vars = []): void
    {
        $payload = $this->requireAuth();
        $id      = $vars['id'] ?? '';
        $userId  = (int) ($payload['sub'] ?? 0);

        // Users with build update permission see any booking; clients only their own.
        if ($this->hasAnyPermission($payload, ['build-updates:manage', 'bookings:manage'])) {
            $this->requireBookingVisibilityForPayload($payload, $id);
        } else {
            // Verify this booking belongs to the authenticated user
            (new BookingService())->getById($id, $userId);
        }

        $updates = (new BuildUpdateService())->getByBookingId($id);
        echo json_encode(['updates' => $updates]);
    }

    /** @param array<string, string> $vars */
    private function handleBuildUpdateCreate(array $vars = []): void
    {
        $payload   = $this->requirePermission('build-updates:manage');
        $actorId   = (int) ($payload['sub'] ?? 0);
        $id        = $vars['id'] ?? '';
        $data      = $this->jsonBody();
        $this->requireBookingMutationForPayload($payload, $id);
        $note      = trim((string) ($data['note'] ?? ''));
        $photoUrls = array_values(array_filter(
            is_array($data['photoUrls'] ?? null) ? $data['photoUrls'] : [],
            fn($v) => is_string($v) && $v !== ''
        ));

        if ($note === '' && count($photoUrls) === 0) {
            throw new RuntimeException('A note or at least one photo is required.', 422);
        }

        $update = (new BuildUpdateService())->create($id, $note, $photoUrls);

        if (DB_NAME !== '') {
            $photoCount = count($photoUrls);
            $detail = $note !== '' ? $note : null;
            if ($detail === null && $photoCount > 0) {
                $detail = $photoCount . ' photo(s)';
            }
            (new BookingActivityService())->add(
                $id,
                ActivityEvents::BOOKING_BUILD_UPDATE_POSTED,
                'Build update posted',
                $detail,
                $actorId > 0 ? $actorId : null,
                'admin'
            );

            try {
                $logger = activity()
                    ->forSubject('bookings', $id)
                    ->withProperties([
                        'eventType' => ActivityEvents::BOOKING_BUILD_UPDATE_POSTED,
                        'action' => 'Build update posted',
                        'detail' => $detail,
                        'actorRole' => 'admin',
                        'photoCount' => $photoCount,
                        'buildUpdateId' => (string) ($update['id'] ?? ''),
                    ]);

                if ($actorId > 0) {
                    $logger->byUser($actorId);
                }

                $logger->log(ActivityEvents::BOOKING_BUILD_UPDATE_POSTED, 'bookings');
            } catch (\Throwable $e) {
                error_log('[Router] Failed to write build update activity log: ' . $e->getMessage());
            }
        }

        // Notify the customer that a new build progress update was posted
        $booking = (new BookingService())->adminFindById($id);
        if ($booking !== null) {
            (new NotificationJobQueueService())->dispatch('build_update_created', [
                'booking' => $booking,
                'update' => $update,
            ]);
        }

        http_response_code(201);
        echo json_encode(['update' => $update]);
    }

    /** @param array<string, string> $vars */
    private function handleBuildUpdateMediaUpload(array $vars = []): void
    {
        $this->requirePermission('build-updates:manage');

        if (empty($_FILES['files'])) {
            throw new RuntimeException('No files provided.', 422);
        }

        $allowed  = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $maxBytes = UPLOAD_MAX_MB * 1024 * 1024;
        $urls     = [];
        $storage  = new UploadStorage();

        $files = $_FILES['files'];
        $count = is_array($files['name']) ? count($files['name']) : 1;

        for ($i = 0; $i < $count; $i++) {
            $tmpName = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
            $mime    = is_array($files['type'])     ? $files['type'][$i]     : $files['type'];
            $size    = is_array($files['size'])     ? $files['size'][$i]     : $files['size'];
            $error   = is_array($files['error'])    ? $files['error'][$i]    : $files['error'];

            if ($error !== UPLOAD_ERR_OK) {
                throw new RuntimeException("File upload error (code $error).", 422);
            }
            if (!in_array($mime, $allowed, true) || !@getimagesize($tmpName)) {
                throw new RuntimeException('Only JPEG, PNG, WebP and GIF images are accepted.', 422);
            }
            if ($size > $maxBytes) {
                throw new RuntimeException('Each file must be under ' . UPLOAD_MAX_MB . ' MB.', 422);
            }

            $origName = is_array($files['name']) ? $files['name'][$i] : $files['name'];
            $ext      = strtolower(pathinfo((string) $origName, PATHINFO_EXTENSION));
            $filename = bin2hex(random_bytes(16)) . '.' . $ext;

            $urls[] = $storage->upload($tmpName, $filename, $mime, 'builds/');
        }

        echo json_encode(['urls' => $urls]);
    }

    // -------------------------------------------------------------------------
    // Notification handlers
    // -------------------------------------------------------------------------

    /** @param array<string, string> $vars */
    private function handleNotificationList(array $vars = []): void
    {
        if (DB_NAME === '') {
            echo json_encode(['notifications' => [], 'unreadCount' => 0]);
            return;
        }

        $payload   = $this->requireAuth();
        $isAdmin   = $this->hasAnyPermission($payload, ['bookings:manage', 'clients:manage', 'analytics:view', 'users:manage', 'roles:view']);
        $userId    = (int) ($payload['sub'] ?? 0);
        $svc       = new UserNotificationService();

        $notifications = $svc->getForViewer($isAdmin, $userId);
        $unreadCount   = $svc->getUnreadCount($isAdmin, $userId);

        echo json_encode(['notifications' => $notifications, 'unreadCount' => $unreadCount]);
    }

    /** @param array<string, string> $vars */
    private function handleNotificationRead(array $vars = []): void
    {
        if (DB_NAME === '') {
            echo json_encode(['ok' => true]);
            return;
        }

        $payload = $this->requireAuth();
    $isAdmin = $this->hasAnyPermission($payload, ['bookings:manage', 'clients:manage', 'analytics:view', 'users:manage', 'roles:view']);
        $userId  = (int) ($payload['sub'] ?? 0);
        $id      = (int) ($vars['id'] ?? 0);

        (new UserNotificationService())->markRead($id, $isAdmin, $userId);
        echo json_encode(['ok' => true]);
    }

    /** @param array<string, string> $vars */
    private function handleNotificationReadAll(array $vars = []): void
    {
        if (DB_NAME === '') {
            echo json_encode(['ok' => true]);
            return;
        }

        $payload = $this->requireAuth();
    $isAdmin = $this->hasAnyPermission($payload, ['bookings:manage', 'clients:manage', 'analytics:view', 'users:manage', 'roles:view']);
        $userId  = (int) ($payload['sub'] ?? 0);

        (new UserNotificationService())->markAllRead($isAdmin, $userId);
        echo json_encode(['ok' => true]);
    }

    /** @param array<string, string> $vars */
    private function handleNotificationDelete(array $vars = []): void
    {
        if (DB_NAME === '') {
            echo json_encode(['ok' => true]);
            return;
        }

        $payload = $this->requireAuth();
    $isAdmin = $this->hasAnyPermission($payload, ['bookings:manage', 'clients:manage', 'analytics:view', 'users:manage', 'roles:view']);
        $userId  = (int) ($payload['sub'] ?? 0);
        $id      = (int) ($vars['id'] ?? 0);

        (new UserNotificationService())->delete($id, $isAdmin, $userId);
        echo json_encode(['ok' => true]);
    }

    // -------------------------------------------------------------------------
    // Review handlers
    // -------------------------------------------------------------------------

    /** @param array<string, string> $vars */
    private function handlePublishedReviewList(array $vars = []): void
    {
        $serviceId = isset($_GET['service_id']) ? (int) $_GET['service_id'] : null;
        $reviews   = (new ReviewService())->getPublished($serviceId);
        echo json_encode(['reviews' => $reviews]);
    }

    /** @param array<string, string> $vars */
    private function handleBookingReviewGet(array $vars = []): void
    {
        $id      = $vars['id'] ?? '';
        $review  = (new ReviewService())->getForBooking($id);
        echo json_encode(['review' => $review]);
    }

    /** @param array<string, string> $vars */
    private function handleBookingReviewCreate(array $vars = []): void
    {
        $payload = $this->requireAuth('client');
        $userId  = (int) ($payload['sub'] ?? 0);
        $id      = $vars['id'] ?? '';

        $data    = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $review  = (new ReviewService())->create($id, $userId, $data);
        http_response_code(201);
        echo json_encode(['review' => $review]);
    }

    /** @param array<string, string> $vars */
    private function handleReviewList(array $vars = []): void
    {
        $this->requirePermission('reviews:manage');
        $reviews = (new ReviewService())->getAll();
        echo json_encode(['reviews' => $reviews]);
    }

    /** @param array<string, string> $vars */
    private function handleReviewApprove(array $vars = []): void
    {
        $this->requirePermission('reviews:manage');
        (new ReviewService())->approve((int) ($vars['id'] ?? 0));
        echo json_encode(['ok' => true]);
    }

    /** @param array<string, string> $vars */
    private function handleReviewReject(array $vars = []): void
    {
        $this->requirePermission('reviews:manage');
        (new ReviewService())->reject((int) ($vars['id'] ?? 0));
        echo json_encode(['ok' => true]);
    }

    /** @param array<string, string> $vars */
    private function handleReviewDelete(array $vars = []): void
    {
        $this->requirePermission('reviews:manage');
        (new ReviewService())->delete((int) ($vars['id'] ?? 0));
        echo json_encode(['ok' => true]);
    }

    // -------------------------------------------------------------------------
    // Internal notes handler
    // -------------------------------------------------------------------------

    /** @param array<string, string> $vars */
    private function handleBookingInternalNotes(array $vars = []): void
    {
        $payload = $this->requirePermission('bookings:notes');
        $id   = $vars['id'] ?? '';
        $data = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $notes = mb_substr((string) ($data['internalNotes'] ?? ''), 0, 5000);
        $this->requireBookingMutationForPayload($payload, $id);
        $booking = (new BookingService())->updateInternalNotes(
            $id,
            $notes,
            (int) ($payload['sub'] ?? 0) ?: null,
            'admin'
        );
        echo json_encode(['booking' => $booking]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminUserList(array $vars = []): void
    {
        $this->requirePermission('users:manage');
        $filters = [
            'search' => (string) ($_GET['search'] ?? ''),
            'role'   => (string) ($_GET['role'] ?? ''),
        ];
        $users = (new UserService())->listUsers($filters);
        echo json_encode(['users' => $users]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminAssignableUsers(array $vars = []): void
    {
        $this->requirePermission('bookings:assign-tech');
        $users = (new UserService())->listUsers(['search' => '', 'role' => '']);
        $assignable = array_values(array_filter($users, static fn (array $u): bool => ($u['role'] ?? '') !== 'client'));
        echo json_encode(['users' => $assignable]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminUserCreate(array $vars = []): void
    {
        $this->requirePermission('users:manage');
        $data = $this->jsonBody();
        $user = (new UserService())->createByAdmin($data);
        http_response_code(201);
        echo json_encode(['user' => $user]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminUserRoleUpdate(array $vars = []): void
    {
        $payload = $this->requirePermission('users:manage');
        $id = (int) ($vars['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Invalid user id.', 422);
        }

        $data = $this->jsonBody();
        $role = (string) ($data['role'] ?? '');
        if ($role === '') {
            throw new RuntimeException('role is required.', 422);
        }

        $actorRole = strtolower(trim((string) ($payload['role'] ?? '')));
        $actorId   = (int) ($payload['sub'] ?? 0);
        $actorName = (string) ($payload['name'] ?? '');

        // Admins can only manage users who are NOT admin or owner.
        // Only owners can manage admin/owner accounts.
        if ($actorRole !== 'owner') {
            $target = (new UserService())->findById($id);
            $targetRole = strtolower(trim((string) ($target['role'] ?? '')));
            if ($targetRole === 'admin' || $targetRole === 'owner') {
                throw new RuntimeException('You do not have permission to change the role of an admin or owner account.', 403);
            }
            // Also block promoting someone TO admin or owner.
            $newRole = strtolower(trim($role));
            if ($newRole === 'admin' || $newRole === 'owner') {
                throw new RuntimeException('You do not have permission to assign the admin or owner role.', 403);
            }
        }

        $user = (new UserService())->updateRole($id, $role, $actorId > 0 ? $actorId : null, $actorName);
        echo json_encode(['user' => $user]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminUserStatusUpdate(array $vars = []): void
    {
        $payload = $this->requirePermission('users:manage');
        $id = (int) ($vars['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Invalid user id.', 422);
        }

        $actorRole = strtolower(trim((string) ($payload['role'] ?? '')));

        if ($actorRole !== 'owner') {
            $target = (new UserService())->findById($id);
            $targetRole = strtolower(trim((string) ($target['role'] ?? '')));
            if ($targetRole === 'admin' || $targetRole === 'owner') {
                throw new RuntimeException('You do not have permission to change the status of an admin or owner account.', 403);
            }
        }

        $data     = $this->jsonBody();
        $isActive = (bool) ($data['is_active'] ?? true);
        $user     = (new UserService())->updateUserStatus($id, $isActive);
        echo json_encode(['user' => $user]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminUserInfoUpdate(array $vars = []): void
    {
        $payload = $this->requirePermission('users:manage');
        $id = (int) ($vars['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Invalid user id.', 422);
        }

        $actorRole = strtolower(trim((string) ($payload['role'] ?? '')));
        if ($actorRole !== 'owner') {
            $target = (new UserService())->findById($id);
            $targetRole = strtolower(trim((string) ($target['role'] ?? '')));
            if ($targetRole === 'admin' || $targetRole === 'owner') {
                throw new RuntimeException('You do not have permission to edit an admin or owner account.', 403);
            }
        }

        $data = $this->jsonBody();
        $user = (new UserService())->updateUserInfo($id, $data);
        echo json_encode(['user' => $user]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminUserDelete(array $vars = []): void
    {
        $payload = $this->requirePermission('users:manage');
        $id = (int) ($vars['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Invalid user id.', 422);
        }

        $actorId = (int) ($payload['sub'] ?? 0);
        if ($actorId > 0 && $actorId === $id) {
            throw new RuntimeException('You cannot delete your own account from this screen.', 422);
        }

        $actorRole = strtolower(trim((string) ($payload['role'] ?? '')));
        $actorName = (string) ($payload['name'] ?? '');

        if ($actorRole !== 'owner') {
            $target = (new UserService())->findById($id);
            $targetRole = strtolower(trim((string) ($target['role'] ?? '')));
            if ($targetRole === 'admin' || $targetRole === 'owner') {
                throw new RuntimeException('You do not have permission to delete an admin or owner account.', 403);
            }
        }

        (new UserService())->deleteByAdmin($id, $actorId > 0 ? $actorId : null, $actorName);
        echo json_encode(['deleted' => true]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminClientList(array $vars = []): void
    {
        $this->requirePermission('clients:manage');
        $filters = ['search' => (string) ($_GET['search'] ?? '')];
        $clients = (new UserService())->listClients($filters);
        echo json_encode(['clients' => $clients]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminClientBookings(array $vars = []): void
    {
        $payload = $this->requirePermission('clients:manage');
        $id = (int) ($vars['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Invalid client id.', 422);
        }
        $bookings = (new BookingService())->getByUserId($id);
        if ($this->isStaffRole($payload) && !$this->staffCanViewAllBookings()) {
            $viewerId = (int) ($payload['sub'] ?? 0);
            $bookings = array_values(array_filter(
                $bookings,
                fn(array $booking): bool => $this->bookingAssignedToUser($booking, $viewerId)
            ));
        }
        echo json_encode(['bookings' => $bookings]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminClientVehicles(array $vars = []): void
    {
        $this->requirePermission('clients:manage');
        $id = (int) ($vars['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Invalid client id.', 422);
        }
        $vehicles = (new VehicleCrudService())->getByUserId($id);
        echo json_encode(['vehicles' => $vehicles]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminCustomer360(array $vars = []): void
    {
        $payload = $this->requirePermission('clients:manage');
        $id = (int) ($vars['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Invalid customer id.', 422);
        }

        if ($this->isStaffRole($payload) && !$this->staffCanViewAllBookings()) {
            throw new RuntimeException('Forbidden. Staff cannot open full customer records unless booking visibility is enabled in system settings.', 403);
        }

        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 25;
        $customer360 = (new Customer360Service())->getByUserId($id, $limit);
        echo json_encode(['customer360' => $customer360]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminCampaignList(array $vars = []): void
    {
        $this->requirePermission('settings:manage');
        echo json_encode(['campaigns' => (new MarketingCampaignService())->listCampaigns()]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminCampaignGet(array $vars = []): void
    {
        $this->requirePermission('settings:manage');
        $id = (int) ($vars['id'] ?? 0);
        $campaign = (new MarketingCampaignService())->getCampaign($id);
        echo json_encode(['campaign' => $campaign]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminCampaignCreate(array $vars = []): void
    {
        $payload = $this->requirePermission('settings:manage');
        $data = $this->jsonBody();
        $campaign = (new MarketingCampaignService())->createCampaign($data, (int) ($payload['sub'] ?? 0) ?: null);
        http_response_code(201);
        echo json_encode(['campaign' => $campaign]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminCampaignUpdate(array $vars = []): void
    {
        $this->requirePermission('settings:manage');
        $id = (int) ($vars['id'] ?? 0);
        $data = $this->jsonBody();
        $campaign = (new MarketingCampaignService())->updateCampaign($id, $data);
        echo json_encode(['campaign' => $campaign]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminCampaignDelete(array $vars = []): void
    {
        $this->requirePermission('settings:manage');
        $id = (int) ($vars['id'] ?? 0);
        (new MarketingCampaignService())->deleteCampaign($id);
        echo json_encode(['ok' => true]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminCampaignRun(array $vars = []): void
    {
        $this->requirePermission('settings:manage');
        $id = (int) ($vars['id'] ?? 0);
        $result = (new MarketingCampaignService())->runCampaign($id, false);
        echo json_encode(['result' => $result]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminCampaignDryRun(array $vars = []): void
    {
        $this->requirePermission('settings:manage');
        $id = (int) ($vars['id'] ?? 0);
        $result = (new MarketingCampaignService())->runCampaign($id, true);
        echo json_encode(['result' => $result]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminCampaignRunScheduled(array $vars = []): void
    {
        $this->requirePermission('settings:manage');
        $body = $this->jsonBody();
        $limit = isset($body['limit']) ? (int) $body['limit'] : 20;
        $result = (new MarketingCampaignService())->runScheduledDue($limit);
        echo json_encode(['result' => $result]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminCampaignAnalytics(array $vars = []): void
    {
        $this->requirePermission('settings:manage');
        $id = (int) ($vars['id'] ?? 0);
        echo json_encode((new MarketingCampaignService())->analytics($id));
    }

    /** @param array<string, string> $vars */
    private function handleAdminCampaignAudience(array $vars = []): void
    {
        $this->requirePermission('settings:manage');
        $type = (string) ($vars['type'] ?? '');
        echo json_encode(['audience' => (new MarketingCampaignService())->getAudience($type)]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminInventoryItemList(array $vars = []): void
    {
        $this->requirePermission('products:manage');
        $filters = [
            'search' => (string) ($_GET['search'] ?? ''),
            'lowStockOnly' => ((string) ($_GET['lowStockOnly'] ?? 'false')) === 'true',
        ];
        echo json_encode(['items' => (new InventoryService())->listItems($filters)]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminInventoryItemCreate(array $vars = []): void
    {
        $payload = $this->requirePermission('products:manage');
        $data = $this->jsonBody();
        $item = (new InventoryService())->createItem($data, (int) ($payload['sub'] ?? 0) ?: null);
        http_response_code(201);
        echo json_encode(['item' => $item]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminInventoryItemUpdate(array $vars = []): void
    {
        $this->requirePermission('products:manage');
        $id = (int) ($vars['id'] ?? 0);
        $data = $this->jsonBody();
        $item = (new InventoryService())->updateItem($id, $data);
        echo json_encode(['item' => $item]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminInventoryMovementList(array $vars = []): void
    {
        $this->requirePermission('products:manage');
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 100;
        echo json_encode(['movements' => (new InventoryService())->listMovements($limit)]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminInventoryAdjust(array $vars = []): void
    {
        $payload = $this->requirePermission('products:manage');
        $data = $this->jsonBody();
        $item = (new InventoryService())->adjustStock($data, (int) ($payload['sub'] ?? 0) ?: null);
        echo json_encode(['item' => $item]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminInventoryAlertList(array $vars = []): void
    {
        $this->requirePermission('products:manage');
        $status = (string) ($_GET['status'] ?? 'open');
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 100;
        echo json_encode(['alerts' => (new InventoryService())->listLowStockAlerts($status, $limit)]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminInventorySupplierList(array $vars = []): void
    {
        $this->requirePermission('products:manage');
        echo json_encode(['suppliers' => (new InventoryService())->listSuppliers()]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminInventorySupplierCreate(array $vars = []): void
    {
        $this->requirePermission('products:manage');
        $data = $this->jsonBody();
        $supplier = (new InventoryService())->createSupplier($data);
        http_response_code(201);
        echo json_encode(['supplier' => $supplier]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminInventoryPurchaseOrderList(array $vars = []): void
    {
        $this->requirePermission('products:manage');
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 100;
        echo json_encode(['purchaseOrders' => (new InventoryService())->listPurchaseOrders($limit)]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminInventoryPurchaseOrderCreate(array $vars = []): void
    {
        $payload = $this->requirePermission('products:manage');
        $data = $this->jsonBody();
        $purchaseOrder = (new InventoryService())->createPurchaseOrder($data, (int) ($payload['sub'] ?? 0) ?: null);
        http_response_code(201);
        echo json_encode(['purchaseOrder' => $purchaseOrder]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminInventoryPurchaseOrderStatus(array $vars = []): void
    {
        $payload = $this->requirePermission('products:manage');
        $id = (int) ($vars['id'] ?? 0);
        $data = $this->jsonBody();
        $status = (string) ($data['status'] ?? '');
        $purchaseOrder = (new InventoryService())->updatePurchaseOrderStatus($id, $status, (int) ($payload['sub'] ?? 0) ?: null);
        echo json_encode(['purchaseOrder' => $purchaseOrder]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminRoleList(array $vars = []): void
    {
        $this->requirePermission('roles:view');
        $roles = (new UserService())->listRoles();
        echo json_encode(['roles' => $roles]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminRoleAuditList(array $vars = []): void
    {
        $this->requirePermission('roles:view');
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 120;
        $logs = (new UserService())->listRoleAuditLogs($limit);

        $format = strtolower(trim((string) ($_GET['format'] ?? 'json')));
        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="role-audit-logs.csv"');
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                throw new RuntimeException('Unable to stream CSV.', 500);
            }

            fputcsv($out, ['id', 'action', 'roleKey', 'targetUserEmail', 'actorName', 'actorEmail', 'createdAt']);
            foreach ($logs as $log) {
                fputcsv($out, [
                    (string) ($log['id'] ?? ''),
                    (string) ($log['action'] ?? ''),
                    (string) ($log['roleKey'] ?? ''),
                    (string) ($log['targetUserEmail'] ?? ''),
                    (string) ($log['actorName'] ?? ''),
                    (string) ($log['actorEmail'] ?? ''),
                    (string) ($log['created_at'] ?? ''),
                ]);
            }
            fclose($out);
            return;
        }

        echo json_encode(['logs' => $logs]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminSecurityAuditList(array $vars = []): void
    {
        $this->requirePermission('security:audit:view');

        if (DB_NAME === '') {
            echo json_encode(['logs' => []]);
            return;
        }

        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 200;
        $logs = (new AuthSecurityService())->listAuthAuditLogs($limit);
        echo json_encode(['logs' => $logs]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminSecurityAuditExport(array $vars = []): void
    {
        $this->requirePermission('security:audit:view');

        if (DB_NAME === '') {
            throw new RuntimeException('Database is required for audit export.', 503);
        }

        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 1000;
        $logs = (new AuthSecurityService())->listAuthAuditLogs($limit);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="auth-security-audit.csv"');
        $out = fopen('php://output', 'wb');
        if ($out === false) {
            throw new RuntimeException('Unable to stream CSV.', 500);
        }

        fputcsv($out, ['id', 'eventType', 'outcome', 'email', 'userName', 'ipAddress', 'userAgent', 'detail', 'createdAt']);
        foreach ($logs as $log) {
            fputcsv($out, [
                (string) ($log['id'] ?? ''),
                (string) ($log['eventType'] ?? ''),
                (string) ($log['outcome'] ?? ''),
                (string) ($log['email'] ?? ''),
                (string) ($log['userName'] ?? ''),
                (string) ($log['ipAddress'] ?? ''),
                (string) ($log['userAgent'] ?? ''),
                (string) ($log['detail'] ?? ''),
                (string) ($log['createdAt'] ?? ''),
            ]);
        }
        fclose($out);
    }

    /** @param array<string, string> $vars */
    private function handleAdminActivityLogUsers(array $vars = []): void
    {
        $this->requireRoles(['owner']);

        if (DB_NAME === '') {
            echo json_encode(['users' => []]);
            return;
        }

        $sort = (string) ($_GET['sort'] ?? 'most_recent');
        $users = Activity::summarizeByUsers($sort);
        echo json_encode(['users' => $users]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminActivityLogList(array $vars = []): void
    {
        $this->requireRoles(['owner']);

        if (DB_NAME === '') {
            echo json_encode(['logs' => []]);
            return;
        }

        $userId = isset($_GET['userId']) ? (int) $_GET['userId'] : null;
        if ($userId !== null && $userId <= 0) {
            $userId = null;
        }

        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 300;
        $records = Activity::listByCauserUser($userId, $limit);
        $logs = array_map(static fn (ActivityRecord $record): array => $record->toArray(), $records);

        echo json_encode(['logs' => $logs]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminSemaphoreAccount(array $vars = []): void
    {
        $this->requirePermission('settings:manage');
        $refresh = filter_var($_GET['refresh'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        echo json_encode((new SemaphoreService())->getAccount($refresh));
    }

    /** @param array<string, string> $vars */
    private function handleAdminSemaphoreMessages(array $vars = []): void
    {
        $this->requirePermission('settings:manage');
        $refresh = filter_var($_GET['refresh'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        $filters = [
            'page' => isset($_GET['page']) ? (int) $_GET['page'] : 1,
            'limit' => isset($_GET['limit']) ? (int) $_GET['limit'] : 20,
            'status' => (string) ($_GET['status'] ?? ''),
            'network' => (string) ($_GET['network'] ?? ''),
            'startDate' => (string) ($_GET['startDate'] ?? ''),
            'endDate' => (string) ($_GET['endDate'] ?? ''),
        ];

        echo json_encode((new SemaphoreService())->getMessages($filters, $refresh));
    }

    /** @param array<string, string> $vars */
    private function handleAdminNotificationQueue(array $vars = []): void
    {
        $this->requirePermission('settings:manage');

        $status = trim((string) ($_GET['status'] ?? ''));
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 100;
        $svc = new NotificationJobQueueService();

        echo json_encode([
            'summary' => $svc->getSummary(),
            'jobs' => $svc->listJobs($status, $limit),
        ]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminNotificationQueueHealth(array $vars = []): void
    {
        $this->requirePermission('settings:manage');

        $warnAfterSeconds = isset($_GET['warnAfterSeconds'])
            ? (int) $_GET['warnAfterSeconds']
            : null;
        $health = (new NotificationJobQueueService())->getHealth($warnAfterSeconds);
        echo json_encode(['health' => $health]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminNotificationQueueReplayFailed(array $vars = []): void
    {
        $this->requirePermission('settings:manage');

        $data = $this->jsonBody();
        $limit = isset($data['limit']) ? (int) $data['limit'] : 50;
        $result = (new NotificationJobQueueService())->replayFailed(null, $limit);
        echo json_encode($result);
    }

    /** @param array<string, string> $vars */
    private function handleAdminNotificationQueueReplayOne(array $vars = []): void
    {
        $this->requirePermission('settings:manage');

        $id = (int) ($vars['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Invalid job id.', 422);
        }

        $result = (new NotificationJobQueueService())->replayFailed($id, 1);
        echo json_encode($result);
    }

    /** @param array<string, string> $vars */
    private function handleAdminRoleCreate(array $vars = []): void
    {
        $payload = $this->requirePermission('roles:manage');
        $data = $this->jsonBody();
        $actorId = (int) ($payload['sub'] ?? 0);
        $actorName = (string) ($payload['name'] ?? '');
        $role = (new UserService())->createRole($data, $actorId > 0 ? $actorId : null, $actorName);
        http_response_code(201);
        echo json_encode(['role' => $role]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminRoleUpdate(array $vars = []): void
    {
        $payload = $this->requirePermission('roles:manage');
        $id = (int) ($vars['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Invalid role id.', 422);
        }

        $data = $this->jsonBody();
        $actorId = (int) ($payload['sub'] ?? 0);
        $actorName = (string) ($payload['name'] ?? '');
        $role = (new UserService())->updateRoleDefinition($id, $data, $actorId > 0 ? $actorId : null, $actorName);
        echo json_encode(['role' => $role]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminRoleDelete(array $vars = []): void
    {
        $payload = $this->requirePermission('roles:manage');
        $id = (int) ($vars['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Invalid role id.', 422);
        }

        $actorId = (int) ($payload['sub'] ?? 0);
        $actorName = (string) ($payload['name'] ?? '');
        (new UserService())->deleteRole($id, $actorId > 0 ? $actorId : null, $actorName);
        echo json_encode(['message' => 'Role deleted.']);
    }

    // -------------------------------------------------------------------------
    // Notification preferences handlers
    // -------------------------------------------------------------------------

    /** @param array<string, string> $vars */
    private function handleNotificationPrefsGet(array $vars = []): void
    {
        if (DB_NAME === '') {
            echo json_encode(['preferences' => null]);
            return;
        }
        $payload = $this->requireAuth();
        $userId  = (int) ($payload['sub'] ?? 0);
        $prefs   = (new NotificationPreferencesService())->getForUser($userId);
        echo json_encode(['preferences' => $prefs]);
    }

    /** @param array<string, string> $vars */
    private function handleNotificationPrefsSave(array $vars = []): void
    {
        if (DB_NAME === '') {
            echo json_encode(['preferences' => null]);
            return;
        }
        $payload = $this->requireAuth();
        $userId  = (int) ($payload['sub'] ?? 0);
        $data    = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $prefs   = (new NotificationPreferencesService())->save($userId, $data);
        echo json_encode(['preferences' => $prefs]);
    }

    // -------------------------------------------------------------------------
    // Customer loyalty stats handler
    // -------------------------------------------------------------------------

    /** @param array<string, string> $vars */
    private function handleCustomerStats(array $vars = []): void
    {
        $this->requireAuth('admin');
        $userId = (int) ($vars['userId'] ?? 0);
        $stats  = (new BookingService())->getCustomerStats($userId);
        echo json_encode(['stats' => $stats]);
    }

    /** @param array<string, string> $vars */
    private function handleClientVehicleList(array $vars = []): void
    {
        $payload = $this->requireAuth();
        $userId  = (int) ($payload['sub'] ?? 0);
        $vehicles = (new VehicleCrudService())->getByUserId($userId);
        echo json_encode(['vehicles' => $vehicles]);
    }

    /** @param array<string, string> $vars */
    private function handleClientVehicleCreate(array $vars = []): void
    {
        $payload = $this->requireAuth();
        $userId  = (int) ($payload['sub'] ?? 0);
        $data    = $this->jsonBody();
        $vehicle = (new VehicleCrudService())->create($userId, $data);
        http_response_code(201);
        echo json_encode(['vehicle' => $vehicle]);
    }

    /** @param array<string, string> $vars */
    private function handleClientVehicleMediaUpload(array $vars = []): void
    {
        $this->requireAuth();

        if (empty($_FILES['file'])) {
            throw new RuntimeException('No file provided.', 422);
        }

        $file     = $_FILES['file'];
        $tmpName  = $file['tmp_name'];
        $mime     = $file['type'];
        $size     = $file['size'];
        $error    = $file['error'];

        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException("File upload error (code $error).", 422);
        }
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!in_array($mime, $allowedMimes, true) || !@getimagesize($tmpName)) {
            throw new RuntimeException('Only JPEG, PNG, WebP and GIF images are accepted.', 422);
        }
        $maxBytes = UPLOAD_MAX_MB * 1024 * 1024;
        if ($size > $maxBytes) {
            throw new RuntimeException('File must be under ' . UPLOAD_MAX_MB . ' MB.', 422);
        }

        $ext      = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;

        $storage = new UploadStorage();
        $url     = $storage->upload($tmpName, $filename, $mime, 'vehicles/');

        echo json_encode(['url' => $url]);
    }

    /** @param array<string, string> $vars */
    private function handleClientVehicleUpdate(array $vars = []): void
    {
        $payload = $this->requireAuth();
        $userId  = (int) ($payload['sub'] ?? 0);
        $id      = (int) ($vars['id'] ?? 0);
        $data    = $this->jsonBody();
        $vehicle = (new VehicleCrudService())->update($id, $userId, $data);
        echo json_encode(['vehicle' => $vehicle]);
    }

    /** @param array<string, string> $vars */
    private function handleClientVehicleDelete(array $vars = []): void
    {
        $payload = $this->requireAuth();
        $userId  = (int) ($payload['sub'] ?? 0);
        $id      = (int) ($vars['id'] ?? 0);
        (new VehicleCrudService())->delete($id, $userId);
        echo json_encode(['ok' => true]);
    }

    /** @param array<string, string> $vars */
    private function handleServiceList(array $vars = []): void
    {
        // Service managers see inactive entries; public sees active only.
        $includeInactive = false;
        $token = Auth::tokenFromHeader();
        if ($token !== null) {
            try {
                $payload = Auth::decodeToken($token);
                $includeInactive = $this->hasPermissionByRole((string) ($payload['role'] ?? ''), 'services:manage');
            } catch (RuntimeException) { /* treat as public */ }
        }

        $services = (new ServiceCrudService())->getAll($includeInactive);
        echo json_encode(['services' => $services]);
    }

    /**
     * Tier 1: Fetches parent services and formats them as a ManyChat Gallery.
     *
     * @param array<string, string> $vars
     */
    private function getManyChatMenu(array $vars = []): void
    {
        $serviceService = new ServiceCrudService();
        $services = $serviceService->getAll();

        $elements = array_map(function ($service) {
            return [
                'title' => $service['title'],
                'image_url' => $service['imageUrl'] ?? '',
                'subtitle' => strip_tags($service['description'] ?? ''),
                'buttons' => [
                    [
                        'type' => 'node',
                        'caption' => 'View Options',
                        'target' => 'Drill Down Trigger',
                        'payload' => json_encode(['service_id' => $service['id']])
                    ]
                ]
            ];
        }, $services);

        echo json_encode([
            'version' => 'v2',
            'content' => [
                'type' => 'gallery',
                'elements' => array_slice($elements, 0, 10) // ManyChat gallery limit
            ]
        ]);
    }

    /**
     * Tier 2: Fetches variations for a specific service ID passed in the request body.
     *
     * @param array<string, string> $vars
     */
    private function getManyChatDrillDown(array $vars = []): void
    {
        $body = $this->jsonBody();
        $serviceId = (int) ($body['service_id'] ?? 0);

        if ($serviceId <= 0) {
            throw new RuntimeException('Missing or invalid service_id.', 400);
        }

        $serviceService = new ServiceCrudService();
        $service = $serviceService->getById($serviceId, false);
        $variations = $service['variations'] ?? [];

        if (empty($variations)) {
            echo json_encode([
                'version' => 'v2',
                'content' => ['messages' => [['type' => 'text', 'text' => 'No variations available for this selection.']]]
            ]);
            return;
        }

        $bookingBaseUrl = rtrim(APP_URL, '/') . '/booking';

        $elements = array_map(function ($variant) use ($bookingBaseUrl, $serviceId) {
            $images = $variant['images'] ?? [];
            $description = trim((string) ($variant['description'] ?? ''));
            $specs = is_array($variant['specs'] ?? null) ? $variant['specs'] : [];

            $specChunks = [];
            foreach ($specs as $spec) {
                if (!is_array($spec)) {
                    continue;
                }
                $label = trim((string) ($spec['label'] ?? ''));
                $value = trim((string) ($spec['value'] ?? ''));
                if ($label === '' || $value === '') {
                    continue;
                }
                $specChunks[] = $label . ': ' . $value;
            }

            $parts = [];
            $price = trim((string) ($variant['price'] ?? ''));
            if ($price !== '') {
                $parts[] = 'Price: ' . $price;
            }
            if ($description !== '') {
                $parts[] = $description;
            }
            if (!empty($specChunks)) {
                $parts[] = 'Specs: ' . implode(', ', $specChunks);
            }

            $subtitle = implode(' | ', $parts);
            if ($subtitle === '') {
                $subtitle = 'Contact us for variant details.';
            }

            $bookingUrl = $bookingBaseUrl
                . '?serviceId=' . urlencode((string) $serviceId)
                . '&variationId=' . urlencode((string) ($variant['id'] ?? ''));

            return [
                'title' => $variant['name'],
                'subtitle' => $subtitle,
                'service_id' => $serviceId,
                'variant_id' => $variant['id'] ?? null,
                'specs_summary' => implode(', ', $specChunks),
                'image_url' => $images[0] ?? '',
                'buttons' => [
                    [
                        'type' => 'url',
                        'caption' => 'Book Now',
                        'url' => $bookingUrl
                    ]
                ]
            ];
        }, $variations);

        echo json_encode([
            'version' => 'v2',
            'content' => [
                'type' => 'gallery',
                'elements' => array_slice($elements, 0, 10)
            ]
        ]);
    }

    /** @param array<string, string> $vars */
    private function handleServiceGet(array $vars = []): void
    {
        $id           = (int) ($vars['id'] ?? 0);
        $requireActive = true;
        $token = Auth::tokenFromHeader();
        if ($token !== null) {
            try {
                $payload = Auth::decodeToken($token);
                $requireActive = !$this->hasPermissionByRole((string) ($payload['role'] ?? ''), 'services:manage');
            } catch (RuntimeException) { /* stay public */ }
        }

        $service = (new ServiceCrudService())->getById($id, $requireActive);
        echo json_encode(['service' => $service]);
    }

    /** @param array<string, string> $vars */
    private function handleServiceGetBySlug(array $vars = []): void
    {
        $slug          = $vars['slug'] ?? '';
        $requireActive = true;
        $token = Auth::tokenFromHeader();
        if ($token !== null) {
            try {
                $payload = Auth::decodeToken($token);
                $requireActive = !$this->hasPermissionByRole((string) ($payload['role'] ?? ''), 'services:manage');
            } catch (RuntimeException) { /* stay public */ }
        }

        $service = (new ServiceCrudService())->getBySlug($slug, $requireActive);
        echo json_encode(['service' => $service]);
    }

    /** @param array<string, string> $vars */
    private function handleServiceCreate(array $vars = []): void
    {
        $this->requirePermission('services:manage');
        $data    = $this->jsonBody();
        $service = (new ServiceCrudService())->create($data);
        http_response_code(201);
        echo json_encode(['service' => $service]);
    }

    /** @param array<string, string> $vars */
    private function handleServiceUpdate(array $vars = []): void
    {
        $this->requirePermission('services:manage');
        $id      = (int) ($vars['id'] ?? 0);
        $data    = $this->jsonBody();
        $service = (new ServiceCrudService())->update($id, $data);
        echo json_encode(['service' => $service]);
    }

    /** @param array<string, string> $vars */
    private function handleServiceDelete(array $vars = []): void
    {
        $this->requirePermission('services:manage');
        $id = (int) ($vars['id'] ?? 0);
        (new ServiceCrudService())->delete($id);
        echo json_encode(['message' => 'Service deleted.']);
    }

    // -------------------------------------------------------------------------
    // Service variation handlers
    // -------------------------------------------------------------------------

    /** @param array<string, string> $vars */
    private function handleServiceVariationList(array $vars = []): void
    {
        $id      = (int) ($vars['id'] ?? 0);
        $service = (new ServiceCrudService())->getById($id, false);
        echo json_encode(['variations' => $service['variations'] ?? []]);
    }

    /** @param array<string, string> $vars */
    private function handleServiceVariationCreate(array $vars = []): void
    {
        $this->requirePermission('services:manage');
        $id        = (int) ($vars['id'] ?? 0);
        $data      = $this->jsonBody();
        $variation = (new ServiceCrudService())->createVariation($id, $data);
        http_response_code(201);
        echo json_encode(['variation' => $variation]);
    }

    /** @param array<string, string> $vars */
    private function handleServiceVariationUpdate(array $vars = []): void
    {
        $this->requirePermission('services:manage');
        $id        = (int) ($vars['id']  ?? 0);
        $vid       = (int) ($vars['vid'] ?? 0);
        $data      = $this->jsonBody();
        $variation = (new ServiceCrudService())->updateVariation($id, $vid, $data);
        echo json_encode(['variation' => $variation]);
    }

    /** @param array<string, string> $vars */
    private function handleServiceVariationDelete(array $vars = []): void
    {
        $this->requirePermission('services:manage');
        $id  = (int) ($vars['id']  ?? 0);
        $vid = (int) ($vars['vid'] ?? 0);
        (new ServiceCrudService())->deleteVariation($id, $vid);
        echo json_encode(['message' => 'Variation deleted.']);
    }

    // -------------------------------------------------------------------------
    // Blog post handlers
    // -------------------------------------------------------------------------

    /** @param array<string, string> $vars */
    private function handleBlogList(array $vars = []): void
    {
        $publishedOnly = true;
        $token = Auth::tokenFromHeader();
        if ($token !== null) {
            try {
                $payload = Auth::decodeToken($token);
                $publishedOnly = !$this->hasPermissionByRole((string) ($payload['role'] ?? ''), 'content:manage');
            } catch (RuntimeException) { /* treat as public */ }
        }

        $posts = (new BlogPostService())->getAll($publishedOnly);
        echo json_encode(['posts' => $posts]);
    }

    /** @param array<string, string> $vars */
    private function handleBlogGet(array $vars = []): void
    {
        $id            = (int) ($vars['id'] ?? 0);
        $publishedOnly = true;
        $token = Auth::tokenFromHeader();
        if ($token !== null) {
            try {
                $payload = Auth::decodeToken($token);
                $publishedOnly = !$this->hasPermissionByRole((string) ($payload['role'] ?? ''), 'content:manage');
            } catch (RuntimeException) { /* treat as public */ }
        }

        $post = (new BlogPostService())->getById($id, $publishedOnly);
        echo json_encode(['post' => $post]);
    }

    /** @param array<string, string> $vars */
    private function handleBlogCreate(array $vars = []): void
    {
        $this->requirePermission('content:manage');
        $data = $this->jsonBody();
        $post = (new BlogPostService())->create($data);
        http_response_code(201);
        echo json_encode(['post' => $post]);
    }

    /** @param array<string, string> $vars */
    private function handleBlogUpdate(array $vars = []): void
    {
        $this->requirePermission('content:manage');
        $id   = (int) ($vars['id'] ?? 0);
        $data = $this->jsonBody();
        $post = (new BlogPostService())->update($id, $data);
        echo json_encode(['post' => $post]);
    }

    /** @param array<string, string> $vars */
    private function handleBlogDelete(array $vars = []): void
    {
        $this->requirePermission('content:manage');
        $id = (int) ($vars['id'] ?? 0);
        (new BlogPostService())->delete($id);
        echo json_encode(['message' => 'Blog post deleted.']);
    }

    /** @param array<string, string> $vars */
    private function handleAdminStats(array $vars = []): void
    {
        $this->requirePermission('analytics:view');
        
        $timeframe = $_GET['timeframe'] ?? null;
        $from = $_GET['from'] ?? null;
        $to = $_GET['to'] ?? null;
        
        $stats = (new BookingService())->getStats($timeframe, $from, $to);
        $inquiryStats = (new InquiryService())->getStats($timeframe, $from, $to);
        
        $stats = array_merge($stats, $inquiryStats);
        
        $combinedPeakHours = [];
        foreach ($stats['peakHours'] ?? [] as $ph) {
            $combinedPeakHours[$ph['time']] = ['time' => $ph['time'], 'bookingsCount' => $ph['count'], 'inquiriesCount' => 0];
        }
        foreach ($stats['peakInquiryHours'] ?? [] as $ph) {
            if (!isset($combinedPeakHours[$ph['time']])) {
                $combinedPeakHours[$ph['time']] = ['time' => $ph['time'], 'bookingsCount' => 0, 'inquiriesCount' => 0];
            }
            $combinedPeakHours[$ph['time']]['inquiriesCount'] = $ph['count'];
        }
        
        usort($combinedPeakHours, function($a, $b) {
            return strtotime($a['time']) <=> strtotime($b['time']);
        });

        $stats['peakHours'] = array_values($combinedPeakHours);
        unset($stats['peakInquiryHours']);

        // Merge top services from bookings and inquiries
        $combinedTopServices = [];
        foreach ($stats['topServices'] ?? [] as $s) {
            $name = (string) ($s['name'] ?? '');
            if ($name === '') continue;
            if (!isset($combinedTopServices[$name])) {
                $combinedTopServices[$name] = [
                    'name' => $name,
                    'count' => 0,
                    'bookingsCount' => 0,
                    'inquiriesCount' => 0,
                ];
            }
            $cnt = (int) ($s['count'] ?? 0);
            $combinedTopServices[$name]['bookingsCount'] += $cnt;
            $combinedTopServices[$name]['count'] += $cnt;
        }

        foreach ($inquiryStats['topInquiryServices'] ?? [] as $s) {
            $name = (string) ($s['name'] ?? '');
            if ($name === '') continue;
            if (!isset($combinedTopServices[$name])) {
                $combinedTopServices[$name] = [
                    'name' => $name,
                    'count' => 0,
                    'bookingsCount' => 0,
                    'inquiriesCount' => 0,
                ];
            }
            $cnt = (int) ($s['count'] ?? 0);
            $combinedTopServices[$name]['inquiriesCount'] += $cnt;
            $combinedTopServices[$name]['count'] += $cnt;
        }

        usort($combinedTopServices, function($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        $stats['topServices'] = array_slice(array_values($combinedTopServices), 0, 6);
        unset($stats['topInquiryServices']);
        
        $stats['totalAppointments'] = $stats['totalBookings'] + $stats['totalInquiries'];
        $stats['activeAppointments'] = $stats['activeBookings'] + $stats['activeInquiries'];
        $stats['todayAppointments'] = $stats['todayBookings'] + $stats['todayInquiries'];
        $stats['todayActiveAppointments'] = $stats['todayPending'] + $stats['todayPendingInquiries'];
        
        echo json_encode($stats);
    }

    /** @param array<string, string> $vars */
    private function handleAdminActivity(array $vars = []): void
    {
        $this->requirePermission('analytics:view');
        
        $db = Database::getInstance();
        $limit = 20;

        $bookingLogs = [];
        try {
            $bookingLogs = $db->query("
                SELECT 
                    l.id, 'booking' as source, l.booking_id as reference_id,
                    l.actor_role, l.event_type, l.action, l.detail, l.created_at,
                    u.name as actor_name,
                    b.first_name, b.last_name
                FROM booking_activity_logs l
                LEFT JOIN users u ON u.id = l.actor_user_id
                LEFT JOIN bookings b ON b.id = l.booking_id
                ORDER BY l.created_at DESC
                LIMIT $limit
            ")->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {}

        $inquiryLogs = [];
        try {
            $inquiryLogs = $db->query("
                SELECT 
                    l.id, 'inquiry' as source, l.inquiry_id as reference_id,
                    l.actor_role, l.event_type, l.action, l.detail, l.created_at,
                    u.name as actor_name,
                    i.full_name as client_name
                FROM inquiry_activity_logs l
                LEFT JOIN users u ON u.id = l.actor_user_id
                LEFT JOIN customer_inquiries i ON i.id = l.inquiry_id
                ORDER BY l.created_at DESC
                LIMIT $limit
            ")->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {}

        $all = array_merge($bookingLogs, $inquiryLogs);
        usort($all, function($a, $b) {
            return strtotime($b['created_at']) <=> strtotime($a['created_at']);
        });

        $all = array_slice($all, 0, $limit);

        echo json_encode(['logs' => $all]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminMediaUpload(array $vars = []): void
    {
        $this->requirePermission('media:upload');

        $allowed_types = ['services', 'products', 'blog', 'team', 'testimonials', 'portfolio', 'before-after'];
        $type = $_POST['type'] ?? '';
        if (!in_array($type, $allowed_types, true)) {
            throw new RuntimeException('Invalid upload type. Must be one of: ' . implode(', ', $allowed_types) . '.', 422);
        }

        if (empty($_FILES['file'])) {
            throw new RuntimeException('No file provided.', 422);
        }

        $file     = $_FILES['file'];
        $tmpName  = $file['tmp_name'];
        $mime     = $file['type'];
        $size     = $file['size'];
        $error    = $file['error'];

        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException("File upload error (code $error).", 422);
        }
        $allowed_mimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!in_array($mime, $allowed_mimes, true) || !@getimagesize($tmpName)) {
            throw new RuntimeException('Only JPEG, PNG, WebP and GIF images are accepted.', 422);
        }
        $maxBytes = UPLOAD_MAX_MB * 1024 * 1024;
        if ($size > $maxBytes) {
            throw new RuntimeException('File must be under ' . UPLOAD_MAX_MB . ' MB.', 422);
        }

        $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $subdir   = $type . '/';

        $storage = new UploadStorage();
        $url     = $storage->upload($tmpName, $filename, $mime, $subdir);

        echo json_encode(['url' => $url]);
    }

    // -------------------------------------------------------------------------
    // Product handlers
    // -------------------------------------------------------------------------

    /** @param array<string, string> $vars */
    private function handleProductList(array $vars = []): void
    {
        $includeInactive = false;
        $token = Auth::tokenFromHeader();
        if ($token !== null) {
            try {
                $payload = Auth::decodeToken($token);
                $includeInactive = $this->hasPermissionByRole((string) ($payload['role'] ?? ''), 'products:manage');
            } catch (RuntimeException) { /* treat as public */ }
        }

        $products = (new ProductService())->getAll($includeInactive);
        echo json_encode(['products' => $products]);
    }

    /** @param array<string, string> $vars */
    private function handleProductGet(array $vars = []): void
    {
        $id            = (string) ($vars['id'] ?? '');
        $requireActive = true;
        $token = Auth::tokenFromHeader();
        if ($token !== null) {
            try {
                $payload = Auth::decodeToken($token);
                $requireActive = !$this->hasPermissionByRole((string) ($payload['role'] ?? ''), 'products:manage');
            } catch (RuntimeException) { /* stay public */ }
        }

        $product = (new ProductService())->getByIdentifier($id, $requireActive);
        echo json_encode(['product' => $product]);
    }

    /** @param array<string, string> $vars */
    private function handleProductCreate(array $vars = []): void
    {
        $this->requirePermission('products:manage');
        $data    = $this->jsonBody();
        $product = (new ProductService())->create($data);
        http_response_code(201);
        echo json_encode(['product' => $product]);
    }

    /** @param array<string, string> $vars */
    private function handleProductUpdate(array $vars = []): void
    {
        $this->requirePermission('products:manage');
        $id      = (string) ($vars['id'] ?? '');
        $data    = $this->jsonBody();
        $svc     = new ProductService();
        $product = $svc->update($svc->resolveId($id), $data);
        echo json_encode(['product' => $product]);
    }

    /** @param array<string, string> $vars */
    private function handleProductDelete(array $vars = []): void
    {
        $this->requirePermission('products:manage');
        $id = (string) ($vars['id'] ?? '');
        $svc = new ProductService();
        $svc->delete($svc->resolveId($id));
        echo json_encode(['message' => 'Product deleted.']);
    }

    // -------------------------------------------------------------------------
    // Product variation handlers
    // -------------------------------------------------------------------------

    /** @param array<string, string> $vars */
    private function handleProductVariationList(array $vars = []): void
    {
        $id      = (string) ($vars['id'] ?? '');
        $product = (new ProductService())->getByIdentifier($id, false);
        echo json_encode(['variations' => $product['variations'] ?? []]);
    }

    /** @param array<string, string> $vars */
    private function handleProductVariationCreate(array $vars = []): void
    {
        $this->requirePermission('products:manage');
        $id        = (string) ($vars['id'] ?? '');
        $data      = $this->jsonBody();
        $svc       = new ProductService();
        $variation = $svc->createVariation($svc->resolveId($id), $data);
        http_response_code(201);
        echo json_encode(['variation' => $variation]);
    }

    /** @param array<string, string> $vars */
    private function handleProductVariationUpdate(array $vars = []): void
    {
        $this->requirePermission('products:manage');
        $id        = (string) ($vars['id']  ?? '');
        $vid       = (int) ($vars['vid'] ?? 0);
        $data      = $this->jsonBody();
        $svc       = new ProductService();
        $variation = $svc->updateVariation($svc->resolveId($id), $vid, $data);
        echo json_encode(['variation' => $variation]);
    }

    /** @param array<string, string> $vars */
    private function handleProductVariationDelete(array $vars = []): void
    {
        $this->requirePermission('products:manage');
        $id  = (string) ($vars['id']  ?? '');
        $vid = (int) ($vars['vid'] ?? 0);
        $svc = new ProductService();
        $svc->deleteVariation($svc->resolveId($id), $vid);
        echo json_encode(['message' => 'Variation deleted.']);
    }

    // -------------------------------------------------------------------------
    // Order handlers
    // -------------------------------------------------------------------------

    /** @param array<string, string> $vars */
    private function handleOrderCreate(array $vars = []): void
    {
        $data = $this->jsonBody();
        $token = Auth::tokenFromHeader();
        $userId = null;
        if ($token !== null) {
            try {
                $payload = Auth::decodeToken($token);
                $userId = (int) ($payload['sub'] ?? 0);
                if ($userId <= 0) {
                    $userId = null;
                }
            } catch (RuntimeException) {
                // continue as guest checkout
            }
        }

        $order = (new OrderService())->create($data, $userId);
        http_response_code(201);
        echo json_encode(['order' => $order]);
    }

    /** @param array<string, string> $vars */
    private function handleOrderMine(array $vars = []): void
    {
        $payload = $this->requireAuth();
        $userId  = (int) ($payload['sub'] ?? 0);
        $orders  = (new OrderService())->listMine($userId);
        echo json_encode(['orders' => $orders]);
    }

    /** @param array<string, string> $vars */
    private function handleOrderGet(array $vars = []): void
    {
        $orderId = (int) ($vars['id'] ?? 0);
        if ($orderId <= 0) {
            throw new RuntimeException('Order not found.', 404);
        }

        $payload = $this->requireAuth();
        $userId = (int) ($payload['sub'] ?? 0);
        $isAdmin = $this->hasPermissionByRole((string) ($payload['role'] ?? ''), 'products:manage');

        $order = (new OrderService())->getById(
            $orderId,
            $isAdmin ? null : $userId,
            $isAdmin ? null : true
        );
        echo json_encode(['order' => $order]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminOrderList(array $vars = []): void
    {
        $this->requirePermission('products:manage');
        $filters = [
            'status' => trim((string) ($_GET['status'] ?? '')),
            'paymentStatus' => trim((string) ($_GET['paymentStatus'] ?? '')),
            'fulfillmentType' => trim((string) ($_GET['fulfillmentType'] ?? '')),
            'query' => trim((string) ($_GET['query'] ?? '')),
            'createdFrom' => trim((string) ($_GET['createdFrom'] ?? '')),
            'createdTo' => trim((string) ($_GET['createdTo'] ?? '')),
        ];
        $pageSize = (int) ($_GET['pageSize'] ?? 25);
        $page = (int) ($_GET['page'] ?? 1);
        $result = (new OrderService())->listAll($filters, $pageSize, $page);
        echo json_encode($result);
    }

    /** @param array<string, string> $vars */
    private function handleAdminOrderStatusUpdate(array $vars = []): void
    {
        $this->requirePermission('products:manage');
        $orderId = (int) ($vars['id'] ?? 0);
        $data = $this->jsonBody();
        $status = trim((string) ($data['status'] ?? ''));
        $order = (new OrderService())->updateStatus($orderId, $status);
        echo json_encode(['order' => $order]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminOrderTrackingUpdate(array $vars = []): void
    {
        $this->requirePermission('products:manage');
        $orderId = (int) ($vars['id'] ?? 0);
        $data = $this->jsonBody();
        $courierName = (string) ($data['courierName'] ?? '');
        $trackingNumber = (string) ($data['trackingNumber'] ?? '');
        $order = (new OrderService())->updateTracking($orderId, $courierName, $trackingNumber);
        echo json_encode(['order' => $order]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminOrderPaymentUpdate(array $vars = []): void
    {
        $this->requirePermission('products:manage');
        $orderId = (int) ($vars['id'] ?? 0);
        $data = $this->jsonBody();
        $paymentStatus = (string) ($data['paymentStatus'] ?? '');
        $order = (new OrderService())->updatePaymentStatus($orderId, $paymentStatus);
        echo json_encode(['order' => $order]);
    }

    // -------------------------------------------------------------------------
    // Portfolio handlers
    // -------------------------------------------------------------------------

    /** @param array<string, string> $vars */
    private function handlePortfolioList(array $vars = []): void
    {
        $includeInactive = false;
        $token = Auth::tokenFromHeader();
        if ($token !== null) {
            try {
                $payload = Auth::decodeToken($token);
                $includeInactive = $this->hasPermissionByRole((string) ($payload['role'] ?? ''), 'content:manage');
            } catch (RuntimeException) { /* treat as public */ }
        }

        $items = (new PortfolioService())->getAll($includeInactive);
        echo json_encode(['portfolio' => $items]);
    }

    /** @param array<string, string> $vars */
    private function handlePortfolioGet(array $vars = []): void
    {
        $id           = (int) ($vars['id'] ?? 0);
        $requireActive = true;
        $token = Auth::tokenFromHeader();
        if ($token !== null) {
            try {
                $payload = Auth::decodeToken($token);
                $requireActive = !$this->hasPermissionByRole((string) ($payload['role'] ?? ''), 'content:manage');
            } catch (RuntimeException) { /* stay public */ }
        }

        $item = (new PortfolioService())->getById($id, $requireActive);
        echo json_encode(['portfolioItem' => $item]);
    }

    /** @param array<string, string> $vars */
    private function handlePortfolioCreate(array $vars = []): void
    {
        $this->requirePermission('content:manage');
        $data = $this->jsonBody();
        $item = (new PortfolioService())->create($data);
        http_response_code(201);
        echo json_encode(['portfolioItem' => $item]);
    }

    /** @param array<string, string> $vars */
    private function handlePortfolioUpdate(array $vars = []): void
    {
        $this->requirePermission('content:manage');
        $id   = (int) ($vars['id'] ?? 0);
        $data = $this->jsonBody();
        $item = (new PortfolioService())->update($id, $data);
        echo json_encode(['portfolioItem' => $item]);
    }

    /** @param array<string, string> $vars */
    private function handlePortfolioDelete(array $vars = []): void
    {
        $this->requirePermission('content:manage');
        $id = (int) ($vars['id'] ?? 0);
        (new PortfolioService())->delete($id);
        echo json_encode(['message' => 'Portfolio item deleted.']);
    }

    // -------------------------------------------------------------------------
    // Portfolio Category handlers
    // -------------------------------------------------------------------------

    /** @param array<string, string> $vars */
    private function handlePortfolioCategoryList(array $vars = []): void
    {
        $categories = (new PortfolioCategoryService())->getAll();
        echo json_encode(['categories' => $categories]);
    }

    /** @param array<string, string> $vars */
    private function handlePortfolioCategoryGet(array $vars = []): void
    {
        $id       = (int) ($vars['id'] ?? 0);
        $category = (new PortfolioCategoryService())->getById($id);
        echo json_encode(['category' => $category]);
    }

    /** @param array<string, string> $vars */
    private function handlePortfolioCategoryCreate(array $vars = []): void
    {
        $this->requirePermission('content:manage');
        $data     = $this->jsonBody();
        $category = (new PortfolioCategoryService())->create($data);
        http_response_code(201);
        echo json_encode(['category' => $category]);
    }

    /** @param array<string, string> $vars */
    private function handlePortfolioCategoryUpdate(array $vars = []): void
    {
        $this->requirePermission('content:manage');
        $id       = (int) ($vars['id'] ?? 0);
        $data     = $this->jsonBody();
        $category = (new PortfolioCategoryService())->update($id, $data);
        echo json_encode(['category' => $category]);
    }

    /** @param array<string, string> $vars */
    private function handlePortfolioCategoryDelete(array $vars = []): void
    {
        $this->requirePermission('content:manage');
        $id = (int) ($vars['id'] ?? 0);
        (new PortfolioCategoryService())->delete($id);
        echo json_encode(['message' => 'Portfolio category deleted.']);
    }

    // -------------------------------------------------------------------------
    // Migration handlers
    // -------------------------------------------------------------------------

    /** @param array<string, string> $vars */
    private function handleMigrateRun(array $vars = []): void
    {
        $this->requirePermission('settings:manage');
        $result = (new MigrationRunner())->run();
        echo json_encode($result);
    }

    /** @param array<string, string> $vars */
    private function handleMigrateStatus(array $vars = []): void
    {
        $this->requirePermission('settings:manage');
        $status = (new MigrationRunner())->status();

        $total = count($status);
        $ranCount = count(array_filter($status, static fn(array $m): bool => (($m['status'] ?? '') === 'ran')));
        $pendingCount = $total - $ranCount;

        $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        $pageSize = isset($_GET['pageSize']) ? max(1, min(200, (int) $_GET['pageSize'])) : 25;
        $offset = ($page - 1) * $pageSize;
        $rows = array_slice($status, $offset, $pageSize);
        $totalPages = $total > 0 ? (int) ceil($total / $pageSize) : 1;

        echo json_encode([
            'migrations' => $rows,
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'totalPages' => $totalPages,
            'counts' => [
                'ran' => $ranCount,
                'pending' => $pendingCount,
                'total' => $total,
            ],
        ]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminCronNotificationQueue(array $vars = []): void
    {
        $this->requirePermission('settings:manage');

        if (DB_NAME === '') {
            throw new RuntimeException('Database is required to process notification queue jobs.', 503);
        }

        $data = $this->jsonBody();
        $limit = isset($data['limit']) ? (int) $data['limit'] : null;

        $stats = (new NotificationJobQueueService())->processPending($limit);
        echo json_encode(['stats' => $stats]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminCronWaitlistAutofill(array $vars = []): void
    {
        $this->requirePermission('settings:manage');

        if (DB_NAME === '') {
            throw new RuntimeException('Database is required to process waitlist autofill jobs.', 503);
        }

        $stats = (new WaitlistService())->processAutoFill();
        echo json_encode(['stats' => $stats]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminCronAppointmentReminders(array $vars = []): void
    {
        $this->requirePermission('settings:manage');

        if (DB_NAME === '') {
            throw new RuntimeException('Database is required to process appointment reminders.', 503);
        }

        $data = $this->jsonBody();
        $dryRun = (bool) ($data['dryRun'] ?? false);
        $date = trim((string) ($data['date'] ?? ''));
        if ($date === '') {
            $date = date('Y-m-d', strtotime('+1 day'));
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new RuntimeException('date must be in YYYY-MM-DD format.', 422);
        }

        $stmt = Database::getInstance()->prepare(
            "SELECT b.id, b.name, b.phone, s.title AS service_name, b.appointment_date, b.appointment_time
               FROM bookings b
               JOIN services s ON b.service_id = s.id
              WHERE b.appointment_date = :date
                AND b.status IN ('confirmed', 'pending')
              ORDER BY b.appointment_time ASC"
        );
        $stmt->execute([':date' => $date]);
        $bookings = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $attempted = 0;
        $skipped = 0;
        $errors = 0;
        $sms = new SmsService();

        foreach ($bookings as $booking) {
            $phone = trim((string) ($booking['phone'] ?? ''));
            if ($phone === '') {
                $skipped++;
                continue;
            }

            $attempted++;
            if ($dryRun) {
                continue;
            }

            try {
                $sms->appointmentReminder($booking);
            } catch (\Throwable) {
                $errors++;
            }
        }

        echo json_encode([
            'stats' => [
                'date' => $date,
                'dryRun' => $dryRun,
                'totalBookings' => count($bookings),
                'attempted' => $attempted,
                'skipped' => $skipped,
                'errors' => $errors,
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Vehicle data handlers (API Ninjas proxy)
    // -------------------------------------------------------------------------

    /** @param array<string, string> $vars */
    private function handleVehicleMakes(array $vars = []): void
    {
        $year  = isset($_GET['year']) && ctype_digit($_GET['year'])
            ? (int) $_GET['year']
            : null;

        // Prefer local DB catalog when available
        try {
            $catalog = new VehicleCatalogService();
            $makes = $catalog->listMakes();
            if (!empty($makes)) {
                echo json_encode(['makes' => $makes]);
                return;
            }
        } catch (\Throwable $e) {
            // Fall back to external VehicleService
        }

        $makes = (new VehicleService())->getMakes($year);
        echo json_encode(['makes' => $makes]);
    }

    /** @param array<string, string> $vars */
    private function handleVehicleModels(array $vars = []): void
    {
        $make  = trim((string) ($_GET['make'] ?? ''));
        $year  = isset($_GET['year']) && ctype_digit($_GET['year'])
            ? (int) $_GET['year']
            : null;

        if ($make === '') {
            throw new RuntimeException("Query parameter 'make' is required.", 422);
        }

        // Prefer local DB catalog when available
        try {
            $catalog = new VehicleCatalogService();
            $models = $catalog->listModelsByMakeName($make);
            if (!empty($models)) {
                echo json_encode(['models' => $models]);
                return;
            }
        } catch (\Throwable $e) {
            // Fall back to external VehicleService
        }

        $models = (new VehicleService())->getModels($make, $year);
        echo json_encode(['models' => $models]);
    }

    /** @param array<string, string> $vars */
    private function handleVehicleTrims(array $vars = []): void
    {
        $make  = trim((string) ($_GET['make']  ?? ''));
        $model = trim((string) ($_GET['model'] ?? ''));
        $limit = isset($_GET['limit']) && ctype_digit($_GET['limit']) ? (int) $_GET['limit'] : 50;
        $page  = isset($_GET['page'])  && ctype_digit($_GET['page'])  ? (int) $_GET['page']  : 1;

        if ($make === '' || $model === '') {
            throw new RuntimeException("Query parameters 'make' and 'model' are required.", 422);
        }

        $trims = (new VehicleService())->getTrims($make, $model, $limit, $page);
        echo json_encode(['trims' => $trims]);
    }

    // -------------------------------------------------------------------------
    // Site settings handlers
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // Admin Vehicle catalog handlers (CRUD)
    // -------------------------------------------------------------------------

    private function handleAdminVehicleMakesList(array $vars = []): void
    {
        $this->requirePermission('settings:manage');
        $db = Database::getInstance();
        $stmt = $db->query('SELECT id, name FROM vehicle_makes ORDER BY name ASC');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        echo json_encode(['makes' => $rows]);
    }

    private function handleAdminVehicleMakesCreate(array $vars = []): void
    {
        $this->requirePermission('settings:manage');
        $data = $this->jsonBody();
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Name is required.', 422);
        }
        $svc = new VehicleCatalogService();
        $id = $svc->createMake($name);
        http_response_code(201);
        echo json_encode(['id' => $id, 'name' => $name]);
    }

    private function handleAdminVehicleMakesUpdate(array $vars = []): void
    {
        $this->requirePermission('settings:manage');
        $id = isset($vars['id']) ? (int) $vars['id'] : 0;
        $data = $this->jsonBody();
        $name = trim((string) ($data['name'] ?? ''));
        if ($id <= 0 || $name === '') {
            throw new RuntimeException('Invalid id or name.', 422);
        }
        $svc = new VehicleCatalogService();
        $svc->updateMake($id, $name);
        echo json_encode(['id' => $id, 'name' => $name]);
    }

    private function handleAdminVehicleMakesDelete(array $vars = []): void
    {
        $this->requirePermission('settings:manage');
        $id = isset($vars['id']) ? (int) $vars['id'] : 0;
        if ($id <= 0) {
            throw new RuntimeException('Invalid id.', 422);
        }
        $svc = new VehicleCatalogService();
        $svc->deleteMake($id);
        echo json_encode(['message' => 'deleted']);
    }

    private function handleAdminVehicleModelsList(array $vars = []): void
    {
        $this->requirePermission('settings:manage');
        $make = trim((string) ($_GET['make'] ?? ''));
        if ($make === '') {
            echo json_encode(['models' => []]);
            return;
        }
        $svc = new VehicleCatalogService();
        echo json_encode(['models' => $svc->listModelsByMakeName($make)]);
    }

    private function handleAdminVehicleModelsCreate(array $vars = []): void
    {
        $this->requirePermission('settings:manage');
        $data = $this->jsonBody();
        $makeId = isset($data['makeId']) ? (int) $data['makeId'] : 0;
        $name = trim((string) ($data['name'] ?? ''));
        if ($makeId <= 0 || $name === '') {
            throw new RuntimeException('makeId and name are required.', 422);
        }
        $svc = new VehicleCatalogService();
        $id = $svc->createModel($makeId, $name);
        http_response_code(201);
        echo json_encode(['id' => $id, 'makeId' => $makeId, 'name' => $name]);
    }

    private function handleAdminVehicleModelsUpdate(array $vars = []): void
    {
        $this->requirePermission('settings:manage');
        $id = isset($vars['id']) ? (int) $vars['id'] : 0;
        $data = $this->jsonBody();
        $makeId = isset($data['makeId']) ? (int) $data['makeId'] : 0;
        $name = trim((string) ($data['name'] ?? ''));
        if ($id <= 0 || $makeId <= 0 || $name === '') {
            throw new RuntimeException('Invalid input.', 422);
        }
        $svc = new VehicleCatalogService();
        $svc->updateModel($id, $makeId, $name);
        echo json_encode(['id' => $id, 'makeId' => $makeId, 'name' => $name]);
    }

    private function handleAdminVehicleModelsDelete(array $vars = []): void
    {
        $this->requirePermission('settings:manage');
        $id = isset($vars['id']) ? (int) $vars['id'] : 0;
        if ($id <= 0) {
            throw new RuntimeException('Invalid id.', 422);
        }
        $svc = new VehicleCatalogService();
        $svc->deleteModel($id);
        echo json_encode(['message' => 'deleted']);
    }


    /** @param array<string, string> $vars */
    private function handleSiteSettingsGet(array $vars = []): void
    {
        $settings = (new SiteSettingsService())->getAll();
        echo json_encode(['settings' => $settings]);
    }

    /** @param array<string, string> $vars */
    private function handleSiteSettingsPut(array $vars = []): void
    {
        $auth = $this->requireAuth();
        if (($auth['role'] ?? '') === 'client') {
            throw new RuntimeException('Permission denied.', 403);
        }
        $data     = $this->jsonBody();
        $settings = (new SiteSettingsService())->update($data);
        echo json_encode(['settings' => $settings]);
    }

    // -------------------------------------------------------------------------
    // Team member handlers
    // -------------------------------------------------------------------------

    /** @param array<string, string> $vars */
    private function handleTeamMemberList(array $vars = []): void
    {
        $activeOnly = true;
        $token = Auth::tokenFromHeader();
        if ($token !== null) {
            try {
                $payload    = Auth::decodeToken($token);
                $activeOnly = ($payload['role'] ?? '') !== 'admin';
            } catch (RuntimeException) { /* treat as public */ }
        }

        $members = (new TeamMemberService())->getAll($activeOnly);
        echo json_encode(['members' => $members]);
    }

    /** @param array<string, string> $vars */
    private function handleTeamMemberCreate(array $vars = []): void
    {
        $this->requirePermission('content:manage');
        $data   = $this->jsonBody();
        $member = (new TeamMemberService())->create($data);
        http_response_code(201);
        echo json_encode(['member' => $member]);
    }

    /** @param array<string, string> $vars */
    private function handleTeamMemberUpdate(array $vars = []): void
    {
        $this->requirePermission('content:manage');
        $id     = (int) ($vars['id'] ?? 0);
        $data   = $this->jsonBody();
        $member = (new TeamMemberService())->update($id, $data);
        echo json_encode(['member' => $member]);
    }

    /** @param array<string, string> $vars */
    private function handleTeamMemberDelete(array $vars = []): void
    {
        $this->requirePermission('content:manage');
        $id = (int) ($vars['id'] ?? 0);
        (new TeamMemberService())->delete($id);
        echo json_encode(['message' => 'Team member deleted.']);
    }

    // -------------------------------------------------------------------------
    // Testimonial handlers
    // -------------------------------------------------------------------------

    /** @param array<string, string> $vars */
    private function handleTestimonialList(array $vars = []): void
    {
        $activeOnly = true;
        $token = Auth::tokenFromHeader();
        if ($token !== null) {
            try {
                $payload    = Auth::decodeToken($token);
                $activeOnly = ($payload['role'] ?? '') !== 'admin';
            } catch (RuntimeException) { /* treat as public */ }
        }

        $testimonials = (new TestimonialService())->getAll($activeOnly);
        echo json_encode(['testimonials' => $testimonials]);
    }

    /** @param array<string, string> $vars */
    private function handleTestimonialCreate(array $vars = []): void
    {
        $this->requirePermission('content:manage');
        $data        = $this->jsonBody();
        $testimonial = (new TestimonialService())->create($data);
        http_response_code(201);
        echo json_encode(['testimonial' => $testimonial]);
    }

    /** @param array<string, string> $vars */
    private function handleTestimonialUpdate(array $vars = []): void
    {
        $this->requirePermission('content:manage');
        $id          = (int) ($vars['id'] ?? 0);
        $data        = $this->jsonBody();
        $testimonial = (new TestimonialService())->update($id, $data);
        echo json_encode(['testimonial' => $testimonial]);
    }

    /** @param array<string, string> $vars */
    private function handleTestimonialDelete(array $vars = []): void
    {
        $this->requirePermission('content:manage');
        $id = (int) ($vars['id'] ?? 0);
        (new TestimonialService())->delete($id);
        echo json_encode(['message' => 'Testimonial deleted.']);
    }

    // -------------------------------------------------------------------------
    // FAQ handlers
    // -------------------------------------------------------------------------

    /** @param array<string, string> $vars */
    private function handleFaqList(array $vars = []): void
    {
        $activeOnly = true;
        $token = Auth::tokenFromHeader();
        if ($token !== null) {
            try {
                $payload    = Auth::decodeToken($token);
                $activeOnly = ($payload['role'] ?? '') !== 'admin';
            } catch (RuntimeException) { /* treat as public */ }
        }

        $faqs = (new FaqService())->getAll($activeOnly);
        echo json_encode(['faqs' => $faqs]);
    }

    /** @param array<string, string> $vars */
    private function handleFaqCreate(array $vars = []): void
    {
        $this->requirePermission('content:manage');
        $data = $this->jsonBody();
        $faq  = (new FaqService())->create($data);
        http_response_code(201);
        echo json_encode(['faq' => $faq]);
    }

    /** @param array<string, string> $vars */
    private function handleFaqUpdate(array $vars = []): void
    {
        $this->requirePermission('content:manage');
        $id   = (int) ($vars['id'] ?? 0);
        $data = $this->jsonBody();
        $faq  = (new FaqService())->update($id, $data);
        echo json_encode(['faq' => $faq]);
    }

    /** @param array<string, string> $vars */
    private function handleFaqDelete(array $vars = []): void
    {
        $this->requirePermission('content:manage');
        $id = (int) ($vars['id'] ?? 0);
        (new FaqService())->delete($id);
        echo json_encode(['message' => 'FAQ deleted.']);
    }

    // -------------------------------------------------------------------------
    // Offer handlers
    // -------------------------------------------------------------------------

    /** @param array<string, string> $vars */
    private function handleOfferList(array $vars = []): void
    {
        $includeInactive = false;
        $token = Auth::tokenFromHeader();
        if ($token !== null) {
            try {
                $payload         = Auth::decodeToken($token);
                $includeInactive = $this->hasPermissionByRole((string) ($payload['role'] ?? ''), 'content:manage');
            } catch (RuntimeException) { /* treat as public */ }
        }

        $offers = (new OfferService())->getAll($includeInactive);
        echo json_encode(['offers' => $offers]);
    }

    /** @param array<string, string> $vars */
    private function handleOfferGet(array $vars = []): void
    {
        $id           = (int) ($vars['id'] ?? 0);
        $requireActive = true;
        $token = Auth::tokenFromHeader();
        if ($token !== null) {
            try {
                $payload       = Auth::decodeToken($token);
                $requireActive = !$this->hasPermissionByRole((string) ($payload['role'] ?? ''), 'content:manage');
            } catch (RuntimeException) { /* stay public */ }
        }

        $offer = (new OfferService())->getById($id, $requireActive);
        echo json_encode(['offer' => $offer]);
    }

    /** @param array<string, string> $vars */
    private function handleOfferCreate(array $vars = []): void
    {
        $this->requirePermission('content:manage');
        $data  = $this->jsonBody();
        $offer = (new OfferService())->create($data);
        http_response_code(201);
        echo json_encode(['offer' => $offer]);
    }

    /** @param array<string, string> $vars */
    private function handleOfferUpdate(array $vars = []): void
    {
        $this->requirePermission('content:manage');
        $id    = (int) ($vars['id'] ?? 0);
        $data  = $this->jsonBody();
        $offer = (new OfferService())->update($id, $data);
        echo json_encode(['offer' => $offer]);
    }

    /** @param array<string, string> $vars */
    private function handleOfferDelete(array $vars = []): void
    {
        $this->requirePermission('content:manage');
        $id = (int) ($vars['id'] ?? 0);
        (new OfferService())->delete($id);
        echo json_encode(['message' => 'Offer deleted.']);
    }

    /** @param array<string, string> $vars */
    private function handleBeforeAfterList(array $vars = []): void
    {
        $includeInactive = false;
        $token = Auth::tokenFromHeader();
        if ($token !== null) {
            try {
                $payload = Auth::decodeToken($token);
                $includeInactive = $this->hasPermissionByRole((string) ($payload['role'] ?? ''), 'content:manage');
            } catch (RuntimeException) { /* treat as public */ }
        }

        $make  = trim((string) ($_GET['make']  ?? ''));
        $model = trim((string) ($_GET['model'] ?? ''));

        $items = (new BeforeAfterService())->getAll($includeInactive, $make, $model);
        echo json_encode(['items' => $items]);
    }

    /** @param array<string, string> $vars */
    private function handleBeforeAfterGet(array $vars = []): void
    {
        $id = (int) ($vars['id'] ?? 0);
        $requireActive = true;
        $token = Auth::tokenFromHeader();
        if ($token !== null) {
            try {
                $payload = Auth::decodeToken($token);
                $requireActive = !$this->hasPermissionByRole((string) ($payload['role'] ?? ''), 'content:manage');
            } catch (RuntimeException) { /* stay public */ }
        }

        $item = (new BeforeAfterService())->getById($id, $requireActive);
        echo json_encode(['item' => $item]);
    }

    /** @param array<string, string> $vars */
    private function handleBeforeAfterCreate(array $vars = []): void
    {
        $this->requirePermission('content:manage');
        $data = $this->jsonBody();
        $item = (new BeforeAfterService())->create($data);
        http_response_code(201);
        echo json_encode(['item' => $item]);
    }

    /** @param array<string, string> $vars */
    private function handleBeforeAfterUpdate(array $vars = []): void
    {
        $this->requirePermission('content:manage');
        $id = (int) ($vars['id'] ?? 0);
        $data = $this->jsonBody();
        $item = (new BeforeAfterService())->update($id, $data);
        echo json_encode(['item' => $item]);
    }

    /** @param array<string, string> $vars */
    private function handleBeforeAfterDelete(array $vars = []): void
    {
        $this->requirePermission('content:manage');
        $id = (int) ($vars['id'] ?? 0);
        (new BeforeAfterService())->delete($id);
        echo json_encode(['message' => 'Before/After item deleted.']);
    }

    // -------------------------------------------------------------------------
    // Contact message handler
    // -------------------------------------------------------------------------

    /** @param array<string, string> $vars */
    private function handleContactMessage(array $vars = []): void
    {
        $data    = $this->jsonBody();
        $this->validateTurnstile($data);
        $name    = trim((string) ($data['name']    ?? ''));
        $email   = trim((string) ($data['email']   ?? ''));
        $phone   = trim((string) ($data['phone']   ?? ''));
        $subject = trim((string) ($data['subject'] ?? ''));
        $message = trim((string) ($data['message'] ?? ''));

        if ($name === '' || $email === '' || $subject === '' || $message === '') {
            http_response_code(422);
            echo json_encode(['detail' => 'Name, email, subject, and message are required.']);
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(422);
            echo json_encode(['detail' => 'Invalid email address.']);
            return;
        }

        (new NotificationJobQueueService())->dispatch('contact_message', [
            'data' => [
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'subject' => $subject,
                'message' => $message,
            ],
        ]);

        echo json_encode(['message' => 'Your message has been sent successfully.']);
    }

    // -------------------------------------------------------------------------
    // Customer Inquiry handler
    // -------------------------------------------------------------------------

    /** @param array<string, string> $vars */
    private function handleCustomerInquiry(array $vars = []): void
    {
        $data = $this->jsonBody();
        $this->validateTurnstile($data);
        $name    = trim((string) ($data['fullName']    ?? ''));
        $email   = trim((string) ($data['emailAddress'] ?? ''));

        $phone   = trim((string) ($data['contactNumber'] ?? ''));
        $product = trim((string) ($data['productToPurchase'] ?? ''));
        $make    = trim((string) ($data['make'] ?? ''));
        $model   = trim((string) ($data['model'] ?? ''));
        $year    = trim((string) ($data['yearModel'] ?? ''));

        if ($name === '' || $email === '' || $phone === '' || $product === '') {
            http_response_code(422);
            echo json_encode(['detail' => 'Name, email, phone, and product are required.']);
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(422);
            echo json_encode(['detail' => 'Invalid email address.']);
            return;
        }

        (new NotificationJobQueueService())->dispatchNow('customer_inquiry', [
            'data' => $data
        ]);

        echo json_encode(['message' => 'Inquiry submitted successfully.']);
    }

        /**
     * Public: Get a portfolio/build item by slug
     */
    private function handlePortfolioGetBySlug(array $vars): void
    {
        $slug = $vars['slug'] ?? '';
        $service = new PortfolioService();
        try {
            $item = $service->getBySlug($slug);
            echo json_encode($item);
        } catch (\Throwable $e) {
            http_response_code($e->getCode() ?: 404);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Calibration Certificate
    // ─────────────────────────────────────────────────────────────────────────

    /** @param array<string, string> $vars */
    private function handleBookingCalibrationUpdate(array $vars = []): void
    {
        $payload = $this->requirePermission('bookings:manage');
        $id   = $vars['id'] ?? '';
        $data = $this->jsonBody();
        $this->requireBookingMutationForPayload($payload, $id);
        $svc  = new BookingService();
        $booking = $svc->updateCalibrationData(
            $id,
            $data,
            (int) ($payload['sub'] ?? 0) ?: null,
            'admin'
        );
        echo json_encode(['booking' => $booking]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GDPR / Data Privacy
    // ─────────────────────────────────────────────────────────────────────────

    /** @param array<string, string> $vars */
    private function handleAuthDataExport(array $vars = []): void
    {
        $payload = $this->requireAuth();
        $userId  = (int) ($payload['sub'] ?? 0);
        $data    = (new PrivacyService())->exportData($userId);

        $filename = 'my-data-' . date('Y-m-d') . '.json';
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string, string> $vars */
    private function handleAuthAccountDelete(array $vars = []): void
    {
        $payload  = $this->requireAuth();
        $userId   = (int) ($payload['sub'] ?? 0);
        $body     = $this->jsonBody();

        // Require the user to confirm with their password before deletion
        $password = trim((string) ($body['password'] ?? ''));
        if ($password === '') {
            throw new RuntimeException('Password confirmation is required to delete your account.', 422);
        }

        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT password FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($password, (string) ($row['password'] ?? ''))) {
            throw new RuntimeException('Incorrect password. Account not deleted.', 401);
        }

        (new PrivacyService())->deleteAccount($userId);
        echo json_encode(['message' => 'Your account and all associated data have been permanently deleted.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Booking Waitlist
    // ─────────────────────────────────────────────────────────────────────────

    /** @param array<string, string> $vars */
    private function handleWaitlistJoin(array $vars = []): void
    {
        $data = $this->jsonBody();

        // Attach logged-in user ID if available
        $token = Auth::tokenFromHeader();
        if ($token !== null) {
            try {
                $payload = Auth::decodeToken($token);
                $data['userId'] = (int) ($payload['sub'] ?? 0);
                if (empty($data['name'])) {
                    $data['name'] = (string) ($payload['name'] ?? '');
                }
                if (empty($data['email'])) {
                    $data['email'] = (string) ($payload['email'] ?? '');
                }
            } catch (RuntimeException) { /* anonymous */ }
        }

        $entry = (new WaitlistService())->join($data);
        http_response_code(201);
        echo json_encode(['entry' => $entry]);
    }

    /** @param array<string, string> $vars */
    private function handleWaitlistList(array $vars = []): void
    {
        $this->requirePermission('bookings:manage');
        $status = trim((string) ($_GET['status'] ?? ''));
        $entries = (new WaitlistService())->getAll($status);
        echo json_encode(['entries' => $entries]);
    }

    /** @param array<string, string> $vars */
    private function handleWaitlistRemove(array $vars = []): void
    {
        $id = (int) ($vars['id'] ?? 0);

        // Admin can remove any entry; authenticated clients can remove their own
        $token = Auth::tokenFromHeader();
        $requestingUserId = null;
        if ($token !== null) {
            try {
                $payload = Auth::decodeToken($token);
                $isAdmin = $this->hasPermissionByRole((string) ($payload['role'] ?? ''), 'bookings:manage');
                if (!$isAdmin) {
                    $requestingUserId = (int) ($payload['sub'] ?? 0);
                }
            } catch (RuntimeException) {
                throw new RuntimeException('Authentication required.', 401);
            }
        } else {
            throw new RuntimeException('Authentication required.', 401);
        }

        (new WaitlistService())->remove($id, $requestingUserId);
        echo json_encode(['message' => 'Waitlist entry removed.']);
    }

    /** @param array<string, string> $vars */
    private function handleWaitlistClaimGet(array $vars = []): void
    {
        $token = (string) ($vars['token'] ?? '');
        $entry = (new WaitlistService())->getClaimByToken($token);
        echo json_encode(['entry' => $entry]);
    }


    private function savePublicChecklistSubmission(array $data): ?int
    {
        $ref = trim((string)($data['referenceNumber'] ?? $data['inquiryId'] ?? ''));

        $phase = (string) ($data['phaseSlug'] ?? $data['phase'] ?? 'before');
        $customer = is_array($data['customer'] ?? null) ? $data['customer'] : [];
        $vehicle  = is_array($data['vehicle'] ?? null)  ? $data['vehicle']  : [];

        $customerName  = trim((string)($customer['name']  ?? $data['customerName']  ?? ''));
        $customerEmail = trim((string)($customer['email'] ?? $data['customerEmail'] ?? ''));
        $contactNumber = trim((string)($customer['phone'] ?? $data['contactNumber'] ?? ''));

        $make        = trim((string)($vehicle['make']        ?? $data['make']        ?? ''));
        $model       = trim((string)($vehicle['model']       ?? $data['model']       ?? ''));
        $year        = trim((string)($vehicle['year']        ?? $data['yearModel']   ?? ''));
        $plateNumber = trim((string)($vehicle['plateNumber'] ?? $data['plateNumber'] ?? ''));

        $serviceTitle  = trim((string)($data['serviceTitle']  ?? $data['productToPurchase'] ?? ''));
        $installerName = trim((string)($data['installerName'] ?? $data['technicianName']    ?? 'Shop Technician'));
        $generalNotes  = isset($data['generalNotes']) ? (string)$data['generalNotes'] : null;
        $signatureData = isset($data['signatureData']) ? (string)$data['signatureData'] : null;

        $db = Database::getInstance();

        // ── 4-Tier Smart Inquiry Matching (Reference # -> Plate # -> Email -> Phone) ──
        $inquiryId = null;
        if ($ref !== '') {
            $stmtInq = $db->prepare('SELECT id, reference_number FROM customer_inquiries WHERE reference_number = :ref OR id = :id LIMIT 1');
            $stmtInq->execute([':ref' => $ref, ':id' => $ref]);
            $rowInq = $stmtInq->fetch(\PDO::FETCH_ASSOC);
            if ($rowInq) {
                $inquiryId = $rowInq['id'];
                if (!empty($rowInq['reference_number'])) {
                    $ref = $rowInq['reference_number'];
                }
            }
        }

        if (!$inquiryId && $plateNumber !== '') {
            $stmtInq = $db->prepare('SELECT id, reference_number FROM customer_inquiries WHERE plate_number = :plate ORDER BY id DESC LIMIT 1');
            $stmtInq->execute([':plate' => $plateNumber]);
            $rowInq = $stmtInq->fetch(\PDO::FETCH_ASSOC);
            if ($rowInq) {
                $inquiryId = $rowInq['id'];
                if ($ref === '' && !empty($rowInq['reference_number'])) {
                    $ref = $rowInq['reference_number'];
                }
            }
        }

        if (!$inquiryId && $customerEmail !== '') {
            $stmtInq = $db->prepare('SELECT id, reference_number FROM customer_inquiries WHERE email_address = :email ORDER BY id DESC LIMIT 1');
            $stmtInq->execute([':email' => $customerEmail]);
            $rowInq = $stmtInq->fetch(\PDO::FETCH_ASSOC);
            if ($rowInq) {
                $inquiryId = $rowInq['id'];
                if ($ref === '' && !empty($rowInq['reference_number'])) {
                    $ref = $rowInq['reference_number'];
                }
            }
        }

        if (!$inquiryId && $contactNumber !== '') {
            $stmtInq = $db->prepare('SELECT id, reference_number FROM customer_inquiries WHERE contact_number = :phone ORDER BY id DESC LIMIT 1');
            $stmtInq->execute([':phone' => $contactNumber]);
            $rowInq = $stmtInq->fetch(\PDO::FETCH_ASSOC);
            if ($rowInq) {
                $inquiryId = $rowInq['id'];
                if ($ref === '' && !empty($rowInq['reference_number'])) {
                    $ref = $rowInq['reference_number'];
                }
            }
        }

        if ($ref === '') {
            $ref = 'REF_' . date('Ymd_His');
        }

        $payloadJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stmt = $db->prepare('
            INSERT INTO public_checklist_submissions
                (inquiry_id, reference_number, phase, customer_name, customer_email, contact_number,
                 vehicle_make, vehicle_model, vehicle_year, plate_number, service_title, installer_name,
                 general_notes, signature_data, payload_json, updated_at)
            VALUES
                (:inquiry_id, :reference_number, :phase, :customer_name, :customer_email, :contact_number,
                 :vehicle_make, :vehicle_model, :vehicle_year, :plate_number, :service_title, :installer_name,
                 :general_notes, :signature_data, :payload_json, NOW())
            ON DUPLICATE KEY UPDATE
                inquiry_id = VALUES(inquiry_id),
                customer_name = VALUES(customer_name),
                customer_email = VALUES(customer_email),
                contact_number = VALUES(contact_number),
                vehicle_make = VALUES(vehicle_make),
                vehicle_model = VALUES(vehicle_model),
                vehicle_year = VALUES(vehicle_year),
                plate_number = VALUES(plate_number),
                service_title = VALUES(service_title),
                installer_name = VALUES(installer_name),
                general_notes = VALUES(general_notes),
                signature_data = VALUES(signature_data),
                payload_json = VALUES(payload_json),
                updated_at = NOW()
        ');

        $stmt->execute([
            ':inquiry_id'       => $inquiryId,
            ':reference_number' => $ref,
            ':phase'            => $phase,
            ':customer_name'    => $customerName,
            ':customer_email'   => $customerEmail,
            ':contact_number'   => $contactNumber,
            ':vehicle_make'     => $make,
            ':vehicle_model'    => $model,
            ':vehicle_year'     => $year,
            ':plate_number'     => $plateNumber,
            ':service_title'    => $serviceTitle,
            ':installer_name'   => $installerName,
            ':general_notes'    => $generalNotes,
            ':signature_data'   => $signatureData,
            ':payload_json'     => $payloadJson,
        ]);

        if ($inquiryId) {
            $phaseTitle = ($phase === 'before') ? 'Pre-Service' : 'Post-Service';
            try {
                (new InquiryActivityService())->add(
                    (string)$inquiryId,
                    'public_checklist_completed',
                    "Public {$phaseTitle} Inspection Checklist Submitted",
                    "Phase: {$phaseTitle} | Tech/Installer: {$installerName}",
                    null,
                    'system'
                );
            } catch (\Throwable) {}
        }

        return (int) $db->lastInsertId();
    }

    private function handlePublicChecklistSubmit(): void
    {
        $data = $this->jsonBody();
        $id = $this->savePublicChecklistSubmission($data);

        $sendEmail = !empty($data['sendEmail']);
        $emailPhase = (string)($data['emailPhase'] ?? $data['phaseSlug'] ?? $data['phase'] ?? 'before');

        if ($sendEmail) {
            try {
                require_once __DIR__ . '/NotificationJobQueueService.php';
                (new NotificationJobQueueService())->dispatch('checklist_inspection_submitted', [
                    'data'       => $data,
                    'emailPhase' => $emailPhase,
                ]);
            } catch (\Throwable $e) {
                error_log('[ChecklistSubmit] Queue dispatch failed: ' . $e->getMessage());
            }
        }

        echo json_encode(['success' => true, 'submissionId' => $id]);
    }

    private function handlePublicChecklistGetSubmission(): void
    {
        $ref = trim((string)($_GET['ref'] ?? $_GET['referenceNumber'] ?? $_GET['inquiryId'] ?? ''));
        $phase = trim((string)($_GET['phase'] ?? 'before'));

        if ($ref === '') {
            throw new RuntimeException('Missing required reference parameter.', 400);
        }

        $db = Database::getInstance();
        $stmt = $db->prepare('
            SELECT * FROM public_checklist_submissions
            WHERE (reference_number = :ref OR inquiry_id = :id) AND phase = :phase
            ORDER BY id DESC LIMIT 1
        ');
        $stmt->execute([':ref' => $ref, ':id' => $ref, ':phase' => $phase]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            echo json_encode(['submission' => null]);
            return;
        }

        $row['payload'] = json_decode($row['payload_json'], true);
        echo json_encode(['submission' => $row]);
    }

    private function handlePublicChecklistPdfGet(): void
    {
        $ref = trim((string)($_GET['ref'] ?? $_GET['referenceNumber'] ?? $_GET['inquiryId'] ?? ''));
        $phase = trim((string)($_GET['phase'] ?? 'before'));

        if ($ref === '') {
            throw new RuntimeException('Missing required reference parameter.', 400);
        }

        $db = Database::getInstance();
        $stmt = $db->prepare('
            SELECT payload_json FROM public_checklist_submissions
            WHERE (reference_number = :ref OR inquiry_id = :id) AND phase = :phase
            ORDER BY id DESC LIMIT 1
        ');
        $stmt->execute([':ref' => $ref, ':id' => $ref, ':phase' => $phase]);
        $payloadJson = $stmt->fetchColumn();

        if (!$payloadJson) {
            $stmtInq = $db->prepare('SELECT * FROM customer_inquiries WHERE reference_number = :ref OR id = :id LIMIT 1');
            $stmtInq->execute([':ref' => $ref, ':id' => $ref]);
            $inquiry = $stmtInq->fetch(\PDO::FETCH_ASSOC);

            if (!$inquiry) {
                throw new RuntimeException('No inquiry or saved checklist submission found for this reference.', 404);
            }

            $data = [
                'referenceNumber' => $inquiry['reference_number'] ?? $ref,
                'customer' => [
                    'name'  => $inquiry['full_name'] ?? '',
                    'email' => $inquiry['email_address'] ?? '',
                    'phone' => $inquiry['contact_number'] ?? '',
                ],
                'vehicle' => [
                    'make'        => $inquiry['make'] ?? '',
                    'model'       => $inquiry['model'] ?? '',
                    'year'        => $inquiry['year_model'] ?? '',
                    'plateNumber' => $inquiry['plate_number'] ?? '',
                ],
                'serviceTitle'  => $inquiry['product_to_purchase'] ?? 'Vehicle Service',
                'installerName' => 'Shop Technician',
                'phase'         => $phase,
            ];
        } else {
            $data = json_decode((string)$payloadJson, true);
            if (!is_array($data)) {
                $data = [];
            }
        }

        $serviceSlug = (string) ($data['serviceSlug'] ?? 'android-headunit');
        require_once __DIR__ . '/ChecklistPdfTemplates.php';
        require_once __DIR__ . '/ChecklistPdfOverlayRenderer.php';

        $template = ChecklistPdfTemplates::forServiceAndPhase($serviceSlug, $phase);
        if ($template === null) {
            throw new RuntimeException("No PDF template found for service {$serviceSlug} and phase {$phase}");
        }

        $renderer = new ChecklistPdfOverlayRenderer();
        $pdfBinary = $renderer->renderPublicChecklist($data, $template);

        header_remove('X-Frame-Options');
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="1625_Autolab_' . ucfirst($serviceSlug) . '_' . ucfirst($phase) . '_Checklist.pdf"');
        header('Content-Length: ' . strlen($pdfBinary));
        echo $pdfBinary;
        exit;
    }

    private function handlePublicChecklistPdf(): void
    {
        $data = $this->jsonBody();
        $this->savePublicChecklistSubmission($data);

        $serviceSlug = (string) ($data['serviceSlug'] ?? 'android-headunit');
        $phase       = (string) ($data['phaseSlug'] ?? $data['phase'] ?? 'before');

        require_once __DIR__ . '/ChecklistPdfTemplates.php';
        require_once __DIR__ . '/ChecklistPdfOverlayRenderer.php';

        $template = ChecklistPdfTemplates::forServiceAndPhase($serviceSlug, $phase);
        if ($template === null) {
            throw new RuntimeException("No PDF template found for service {$serviceSlug} and phase {$phase}");
        }

        $renderer = new ChecklistPdfOverlayRenderer();
        $pdfBinary = $renderer->renderPublicChecklist($data, $template);

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="1625_Autolab_' . ucfirst($serviceSlug) . '_' . ucfirst($phase) . '_Checklist.pdf"');
        header('Content-Length: ' . strlen($pdfBinary));
        echo $pdfBinary;
        exit;
    }

    private function handleAdminChecklistResendEmail(): void
    {
        $user = $this->requireAuth();
        $data = $this->jsonBody();
        $ref = trim((string)($data['ref'] ?? $data['referenceNumber'] ?? $data['inquiryId'] ?? ''));
        $phase = trim((string)($data['phase'] ?? 'before'));

        if ($ref === '') {
            throw new RuntimeException('Missing required reference parameter.', 400);
        }

        $db = Database::getInstance();
        $stmt = $db->prepare('
            SELECT payload_json FROM public_checklist_submissions
            WHERE (reference_number = :ref OR inquiry_id = :id) AND phase = :phase
            ORDER BY id DESC LIMIT 1
        ');
        $stmt->execute([':ref' => $ref, ':id' => $ref, ':phase' => ($phase === 'final' ? 'after' : $phase)]);
        $payloadJson = $stmt->fetchColumn();

        if (!$payloadJson) {
            $stmtInq = $db->prepare('SELECT * FROM customer_inquiries WHERE reference_number = :ref OR id = :id LIMIT 1');
            $stmtInq->execute([':ref' => $ref, ':id' => $ref]);
            $inquiry = $stmtInq->fetch(\PDO::FETCH_ASSOC);

            if (!$inquiry) {
                throw new RuntimeException('No inquiry or saved checklist submission found for this reference.', 404);
            }

            $payload = [
                'referenceNumber' => $inquiry['reference_number'] ?? $ref,
                'customerName'    => $inquiry['full_name'] ?? '',
                'customerEmail'   => $inquiry['email_address'] ?? '',
                'contactNumber'   => $inquiry['contact_number'] ?? '',
                'vehicle'         => trim(($inquiry['make'] ?? '') . ' ' . ($inquiry['model'] ?? '') . ' ' . ($inquiry['year_model'] ?? '')),
                'plateNumber'     => $inquiry['plate_number'] ?? '',
                'serviceFieldValue' => $inquiry['product_to_purchase'] ?? 'Vehicle Service',
                'installerName'   => 'Shop Technician',
                'date'            => $inquiry['appointment_date'] ?? date('Y-m-d'),
                'phase'           => $phase,
            ];
        } else {
            $payload = json_decode((string)$payloadJson, true) ?: [];
        }

        require_once __DIR__ . '/NotificationJobQueueService.php';
        (new NotificationJobQueueService())->dispatch('checklist_inspection_submitted', [
            'data'       => $payload,
            'emailPhase' => $phase,
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Checklist inspection report email queued successfully.',
        ]);
    }

    private function handleAdminChecklistDelete(): void
    {
        $user = $this->requireAuth();
        $data = $this->jsonBody();
        $ref = trim((string)($data['ref'] ?? $data['referenceNumber'] ?? $data['inquiryId'] ?? ''));
        $phase = trim((string)($data['phase'] ?? 'before'));

        if ($ref === '') {
            throw new RuntimeException('Missing required reference parameter.', 400);
        }

        $db = Database::getInstance();
        $stmt = $db->prepare('
            DELETE FROM public_checklist_submissions
            WHERE (reference_number = :ref OR inquiry_id = :id) AND phase = :phase
        ');
        $stmt->execute([':ref' => $ref, ':id' => $ref, ':phase' => $phase]);

        echo json_encode([
            'success' => true,
            'message' => "Inspection submission for {$phase} phase reset successfully.",
        ]);
    }

    private function handleAdminChecklistUpdate(): void
    {
        $user = $this->requireAuth();
        $data = $this->jsonBody();
        $ref = trim((string)($data['ref'] ?? $data['referenceNumber'] ?? $data['inquiryId'] ?? ''));
        $phase = trim((string)($data['phase'] ?? 'before'));
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];

        if ($ref === '' || empty($payload)) {
            throw new RuntimeException('Missing required reference or payload parameter.', 400);
        }

        $installerName = (string)($payload['installerName'] ?? 'Shop Technician');
        $additionalNotes = (string)($payload['additionalNotes'] ?? $payload['additional_notes'] ?? '');

        $db = Database::getInstance();
        $stmt = $db->prepare('
            UPDATE public_checklist_submissions
               SET payload_json = :payload,
                   installer_name = :installer,
                   general_notes = :notes,
                   updated_at = NOW()
             WHERE (reference_number = :ref OR inquiry_id = :id) AND phase = :phase
        ');
        $stmt->execute([
            ':payload'   => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':installer' => $installerName,
            ':notes'     => $additionalNotes,
            ':ref'       => $ref,
            ':id'        => $ref,
            ':phase'     => $phase,
        ]);

        echo json_encode([
            'success' => true,
            'message' => "Inspection checklist updated successfully.",
        ]);
    }

    // -------------------------------------------------------------------------
    // Admin Backup & Restore Handlers
    // -------------------------------------------------------------------------

    /** @param array<string, string> $vars */
    private function handleAdminBackupList(array $vars = []): void
    {
        $this->requireRoles(['owner', 'admin']);
        $service = new BackupService();
        $snapshots = $service->listSnapshots();
        $stats = $service->getSystemStats();

        echo json_encode([
            'success'   => true,
            'snapshots' => $snapshots,
            'stats'     => $stats,
        ]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminBackupCreate(array $vars = []): void
    {
        $user = $this->requireRoles(['owner', 'admin']);
        $data = $this->jsonBody();
        $scope = (string) ($data['scope'] ?? 'full');

        $service = new BackupService();
        $result = $service->createBackup($scope, $user);

        echo json_encode($result);
    }

    /** @param array<string, string> $vars */
    private function handleAdminBackupDownload(array $vars = []): void
    {
        $this->requireRoles(['owner', 'admin']);
        $filename = trim($vars['filename'] ?? '');

        $service = new BackupService();
        $filePath = $service->getSnapshotPath($filename);

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mime = $ext === 'zip' ? 'application/zip' : 'application/sql';

        header('Content-Description: File Transfer');
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Pragma: public');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');

        readfile($filePath);
        exit;
    }

    /** @param array<string, string> $vars */
    private function handleAdminBackupDelete(array $vars = []): void
    {
        $this->requireRoles(['owner', 'admin']);
        $filename = trim($vars['filename'] ?? '');

        $service = new BackupService();
        $deleted = $service->deleteSnapshot($filename);

        echo json_encode(['success' => $deleted, 'filename' => $filename]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminBackupInspect(array $vars = []): void
    {
        $this->requireRoles(['owner', 'admin']);
        $service = new BackupService();

        // 1. Check if a file was uploaded via multipart/form-data
        if (!empty($_FILES['backup_file']['tmp_name']) && is_uploaded_file($_FILES['backup_file']['tmp_name'])) {
            $tmpPath = $_FILES['backup_file']['tmp_name'];
            $origName = $_FILES['backup_file']['name'] ?? 'uploaded_backup.zip';
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            if (!in_array($ext, ['zip', 'sql'], true)) {
                throw new InvalidArgumentException('Uploaded file must be a .zip or .sql backup file.', 422);
            }

            // Move to temporary folder for inspection
            $tempInspectPath = $service->getSystemStats()['backups']['backupDir'] . '/tmp/inspect_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!move_uploaded_file($tmpPath, $tempInspectPath)) {
                throw new RuntimeException('Failed to process uploaded backup file.', 500);
            }

            $inspection = $service->inspectBackup($tempInspectPath);
            $inspection['filename'] = $origName;
            $inspection['tempToken'] = basename($tempInspectPath);
            echo json_encode(['success' => true, 'inspection' => $inspection]);
            return;
        }

        // 2. Check if inspecting an existing server snapshot
        $data = $this->jsonBody();
        $snapshotFilename = trim((string) ($data['snapshotFilename'] ?? ''));

        if ($snapshotFilename === '') {
            throw new InvalidArgumentException('Missing backup file or snapshot filename to inspect.', 400);
        }

        $snapshotPath = $service->getSnapshotPath($snapshotFilename);
        $inspection = $service->inspectBackup($snapshotPath);

        echo json_encode(['success' => true, 'inspection' => $inspection]);
    }

    /** @param array<string, string> $vars */
    private function handleAdminBackupRestore(array $vars = []): void
    {
        $user = $this->requireRoles(['owner', 'admin']);
        $service = new BackupService();

        $restoreDb = true;
        $restoreMedia = true;
        $restoreSettings = true;
        $sourcePath = '';
        $isTempUpload = false;

        // Check multipart form upload
        if (!empty($_FILES['backup_file']['tmp_name']) && is_uploaded_file($_FILES['backup_file']['tmp_name'])) {
            $tmpPath = $_FILES['backup_file']['tmp_name'];
            $origName = $_FILES['backup_file']['name'] ?? 'uploaded_backup.zip';
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            if (!in_array($ext, ['zip', 'sql'], true)) {
                throw new InvalidArgumentException('Uploaded file must be a .zip or .sql backup file.', 422);
            }

            $sourcePath = $service->getSystemStats()['backups']['backupDir'] . '/tmp/restore_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!move_uploaded_file($tmpPath, $sourcePath)) {
                throw new RuntimeException('Failed to process uploaded restore file.', 500);
            }
            $isTempUpload = true;

            $restoreDb = isset($_POST['restoreDatabase']) ? filter_var($_POST['restoreDatabase'], FILTER_VALIDATE_BOOLEAN) : true;
            $restoreMedia = isset($_POST['restoreMedia']) ? filter_var($_POST['restoreMedia'], FILTER_VALIDATE_BOOLEAN) : true;
            $restoreSettings = isset($_POST['restoreSettings']) ? filter_var($_POST['restoreSettings'], FILTER_VALIDATE_BOOLEAN) : true;
        } else {
            $data = $this->jsonBody();

            // Check if tempToken from previous inspect was passed
            $tempToken = trim((string) ($data['tempToken'] ?? ''));
            if ($tempToken !== '') {
                $cleanToken = basename($tempToken);
                $candidate = $service->getSystemStats()['backups']['backupDir'] . '/tmp/' . $cleanToken;
                if (is_file($candidate)) {
                    $sourcePath = $candidate;
                    $isTempUpload = true;
                }
            }

            if ($sourcePath === '') {
                $snapshotFilename = trim((string) ($data['snapshotFilename'] ?? ''));
                if ($snapshotFilename === '') {
                    throw new InvalidArgumentException('No backup file or server snapshot specified for restoration.', 400);
                }
                $sourcePath = $service->getSnapshotPath($snapshotFilename);
            }

            $restoreDb = isset($data['restoreDatabase']) ? (bool) $data['restoreDatabase'] : true;
            $restoreMedia = isset($data['restoreMedia']) ? (bool) $data['restoreMedia'] : true;
            $restoreSettings = isset($data['restoreSettings']) ? (bool) $data['restoreSettings'] : true;
        }

        try {
            $result = $service->restoreBackup($sourcePath, [
                'restoreDatabase' => $restoreDb,
                'restoreMedia'    => $restoreMedia,
                'restoreSettings' => $restoreSettings,
            ], $user);

            echo json_encode($result);
        } finally {
            if ($isTempUpload && is_file($sourcePath)) {
                @unlink($sourcePath);
            }
        }
    }
}
