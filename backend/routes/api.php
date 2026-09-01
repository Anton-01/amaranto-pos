<?php

use App\Http\Controllers\Admin\BackupController;
use App\Http\Controllers\Admin\CacheConfigurationController;
use App\Http\Controllers\Admin\EmailConfigurationController;
use App\Http\Controllers\Admin\JobMonitorController;
use App\Http\Controllers\Admin\MailPreviewController;
use App\Http\Controllers\Admin\PrintTicketController;
use App\Http\Controllers\Admin\QzSecurityController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SystemSettingsController;
use App\Http\Controllers\Admin\TrashController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Catalog\CategoryController;
use App\Http\Controllers\Catalog\PaymentMethodController;
use App\Http\Controllers\Catalog\ProductController;
use App\Http\Controllers\Catalog\ProductImageController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Dashboard\MonthlyAnalyticsController;
use App\Http\Controllers\Dining\TableController;
use App\Http\Controllers\Dining\TableSessionController;
use App\Http\Controllers\Finance\AnalyticsController;
use App\Http\Controllers\Finance\CashRegisterClosingController;
use App\Http\Controllers\Finance\CashRegisterController;
use App\Http\Controllers\Finance\PettyCashController;
use App\Http\Controllers\Logistics\StockMovementController;
use App\Http\Controllers\Media\AllowedFileTypeController;
use App\Http\Controllers\Media\DriveCredentialController;
use App\Http\Controllers\Media\MediaAuditLogController;
use App\Http\Controllers\Media\MediaLibraryController;
use App\Http\Controllers\Media\MediaShareLinkController;
use App\Http\Controllers\Media\PublicMediaShareController;
use App\Http\Controllers\Notifications\SystemNotificationController;
use App\Http\Controllers\Profile\NotificationPreferenceController;
use App\Http\Controllers\Profile\ProfileController;
use App\Http\Controllers\Promotion\PromotionController;
use App\Http\Controllers\Promotion\PromotionSearchController;
use App\Http\Controllers\Sales\DailySummaryController;
use App\Http\Controllers\Sales\OrderController;
use App\Http\Controllers\Sales\SalesExportController;
use App\Http\Controllers\Sales\TicketConfigController;
use App\Models\GlobalSetting;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Enlaces Compartidos de Medios — UNICA ruta publica del modulo
|--------------------------------------------------------------------------
|
| Deliberadamente FUERA de `auth:sanctum`: un enlace de comparticion existe
| para que alguien sin cuenta en el POS abra un recurso. La autorizacion no la
| da una sesion sino el token, que este controlador valida contra la base en
| CADA peticion (vigencia, revocacion y tope de descargas).
|
| El throttling es propio y agresivo. Un token de 256 bits no se adivina por
| fuerza bruta, pero un enlace filtrado si puede convertirse en un canal de
| exfiltracion masiva; el limite acota el dano de un enlace comprometido antes
| de que expire.
|
| Gracias a estas rutas el archivo en Drive JAMAS necesita el permiso "anyone
| with the link": los bytes los sirve el POS, y por eso se pueden caducar,
| contar y revocar.
|
*/
Route::prefix('media/shared')->middleware('throttle:30,1')->group(function () {
    Route::get('/{token}', [PublicMediaShareController::class, 'show']);
    Route::get('/{token}/content', [PublicMediaShareController::class, 'download']);
});

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        // Un TOTP son 6 digitos: sin freno, un script agota el espacio de
        // claves durante la vigencia del token temporal de 5 minutos.
        Route::post('/2fa/verify', [AuthController::class, 'verify2fa'])
            ->middleware('throttle:6,1');
    });

    Route::middleware(['auth:sanctum', 'user.active'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

        Route::prefix('2fa')->group(function () {
            Route::post('/setup', [TwoFactorController::class, 'setup']);
            Route::post('/confirm', [TwoFactorController::class, 'confirm']);
            Route::post('/disable', [TwoFactorController::class, 'disable']);
        });
    });
});

Route::middleware(['auth:sanctum', 'user.active'])->group(function () {
    Route::apiResource('categories', CategoryController::class);
    Route::patch('categories/{category}/toggle-status', [CategoryController::class, 'toggleStatus']);

    Route::get('products/grouped', [ProductController::class, 'grouped']);
    Route::apiResource('products', ProductController::class);
    Route::patch('products/{product}/toggle-status', [ProductController::class, 'toggleStatus']);
    Route::post('products/{product}/image', [ProductImageController::class, 'upload']);
    Route::delete('products/{product}/image', [ProductImageController::class, 'destroy']);

    Route::get('promotions/search', PromotionSearchController::class);
    Route::get('promotions/active', [PromotionController::class, 'active']);
    Route::get('promotions', [PromotionController::class, 'index']);
    Route::get('promotions/{promotion}', [PromotionController::class, 'show']);

    Route::middleware('role:admin,manager')->group(function () {
        Route::post('promotions', [PromotionController::class, 'store']);
        Route::put('promotions/{promotion}', [PromotionController::class, 'update']);
        Route::patch('promotions/{promotion}/toggle-status', [PromotionController::class, 'toggleStatus']);
        Route::delete('promotions/{promotion}', [PromotionController::class, 'destroy']);
    });

    Route::prefix('ticket-configs')->group(function () {
        Route::get('/', [TicketConfigController::class, 'index']);
        Route::get('/active', [TicketConfigController::class, 'active']);
        Route::get('/{ticketConfig}', [TicketConfigController::class, 'show']);
    });

    Route::middleware('role:admin,manager')->group(function () {
        Route::post('ticket-configs', [TicketConfigController::class, 'store']);
    });

    Route::get('orders', [OrderController::class, 'index']);
    Route::post('orders', [OrderController::class, 'store']);
    Route::get('orders/{order}', [OrderController::class, 'show']);
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel']);
    Route::post('orders/{order}/print', PrintTicketController::class);

    Route::post('printer/sign', [QzSecurityController::class, 'sign']);
    Route::get('printer/certificate', [QzSecurityController::class, 'certificate']);

    Route::get('tax-rate', function () {
        $setting = GlobalSetting::where('key', 'tax_rate')->first();
        $rate = $setting ? (float) ($setting->value['rate'] ?? 0.16) : 0.16;
        $label = $setting ? ($setting->value['label'] ?? 'IVA 16%') : 'IVA 16%';

        return response()->json(['status' => 'success', 'data' => ['rate' => $rate, 'label' => $label]]);
    });

    Route::get('sales/daily-summary', DailySummaryController::class);
    Route::get('sales/export', [SalesExportController::class, 'export']);

    // --- Comedor (Dine-in) -------------------------------------------------
    Route::prefix('tables')->group(function () {
        // Plano de mesas y detalle de la cuenta viva: todo el piso los consulta.
        Route::get('/', [TableController::class, 'index']);
        Route::get('/{table}', [TableController::class, 'show']);

        // Ciclo de vida de la mesa: transaccional, cualquier usuario operativo.
        Route::post('/{table}/open', [TableSessionController::class, 'open']);
        Route::post('/{table}/items', [TableSessionController::class, 'addItems']);
        Route::post('/{table}/close', [TableSessionController::class, 'close']);

        /*
         * Correccion de partidas ya comandadas. Reservada a admin y manager: un
         * mesero agrega consumo, pero retirarlo saca dinero ya registrado de la
         * cuenta y eso exige mando. La huella en audit_logs (usuario, producto,
         * cantidad y monto retirado) opera como segunda capa, no como la unica.
         */
        Route::middleware('role:admin,manager')->group(function () {
            Route::put('/{table}/items/{item}', [TableSessionController::class, 'updateItem']);
            Route::delete('/{table}/items/{item}', [TableSessionController::class, 'destroyItem']);
        });

        /*
         * Cancelacion de la cuenta completa. La autorizacion se resuelve dentro
         * del controlador (rol admin o contrasena de autorizacion), no con un
         * middleware de rol: el cajero SI puede cancelar, siempre que un
         * supervisor le haya confiado la contrasena.
         */
        Route::post('/{table}/cancel', [TableSessionController::class, 'cancel']);

        // Catalogo de mesas: administracion.
        Route::middleware('role:admin,manager')->group(function () {
            Route::post('/', [TableController::class, 'store']);
            Route::put('/{table}', [TableController::class, 'update']);
            Route::delete('/{table}', [TableController::class, 'destroy']);
        });
    });

    Route::get('payment-methods', [PaymentMethodController::class, 'index']);

    Route::middleware('role:admin,manager')->prefix('payment-methods')->group(function () {
        Route::post('/', [PaymentMethodController::class, 'store']);
        Route::put('/{paymentMethod}', [PaymentMethodController::class, 'update']);
        Route::delete('/{paymentMethod}', [PaymentMethodController::class, 'destroy']);
    });

    Route::prefix('dashboard')->group(function () {
        Route::get('/stats', [DashboardController::class, 'stats']);
        Route::get('/hourly-trend', [DashboardController::class, 'hourlyTrend']);
        Route::get('/top-products', [DashboardController::class, 'topProducts']);
        Route::get('/monthly-analytics', MonthlyAnalyticsController::class)
            ->middleware('role:admin,manager');
    });

    // Notificaciones estructuradas por tag (campana del header)
    Route::prefix('notifications')->group(function () {
        Route::get('/', [SystemNotificationController::class, 'index']);
        Route::post('/{notification}/read', [SystemNotificationController::class, 'markRead']);
    });

    // Auditoria inmutable de cierres — EXCLUSIVA de administradores
    Route::middleware('role:admin')->group(function () {
        Route::get('admin/cash-closings-audit', [CashRegisterClosingController::class, 'audit']);
        Route::get('admin/cash-closings-audit/{closing}', [CashRegisterClosingController::class, 'auditShow']);
    });

    /*
    |--------------------------------------------------------------------------
    | FASE 10 — Telemetria de Jobs y Ecosistema de Rollback (admin only)
    |--------------------------------------------------------------------------
    |
    | Observabilidad de la cola y recuperacion ante desastres. Ambas familias
    | son EXCLUSIVAS de administradores: la primera expone trazas tecnicas con
    | detalles internos del sistema, y la segunda puede reescribir la base de
    | datos completa.
    |
    */
    Route::middleware('role:admin')->prefix('admin')->group(function () {

        // Telemetria de jobs en segundo plano.
        Route::prefix('jobs')->group(function () {
            Route::get('/', [JobMonitorController::class, 'index']);
            // `history` va ANTES de `{job}` o el router lo tomaria por un UUID.
            Route::get('/history', [JobMonitorController::class, 'history']);
            Route::get('/{job}', [JobMonitorController::class, 'show']);
            Route::post('/{job}/retry', [JobMonitorController::class, 'retry']);
        });

        // Boveda de respaldos y rollback manual.
        Route::prefix('backups')->group(function () {
            Route::get('/', [BackupController::class, 'index']);
            Route::post('/', [BackupController::class, 'store']);
            Route::get('/{snapshot}/verify', [BackupController::class, 'verify']);
            Route::post('/{snapshot}/restore', [BackupController::class, 'restore']);
        });
    });

    Route::prefix('stock-movements')->group(function () {
        Route::get('/', [StockMovementController::class, 'index']);
        Route::post('/', [StockMovementController::class, 'store']);
        Route::get('/summary', [StockMovementController::class, 'summary']);
    });

    Route::middleware('role:admin,manager')->prefix('admin/users')->group(function () {
        Route::get('/', [UserController::class, 'index']);
        Route::post('/', [UserController::class, 'store']);
        Route::get('/roles', [UserController::class, 'roles']);
        Route::get('/{user}', [UserController::class, 'show']);
        Route::post('/{user}/toggle-status', [UserController::class, 'toggleStatus']);
        Route::patch('/{user}/toggle-status', [UserController::class, 'toggleStatus']);
        Route::delete('/{user}', [UserController::class, 'destroy']);
        Route::post('/{user}/send-password-reset', [UserController::class, 'sendPasswordReset']);
        Route::post('/{user}/sessions/{sessionId}/revoke', [UserController::class, 'revokeSession']);
    });

    Route::middleware('role:admin,manager')->prefix('admin')->group(function () {
        Route::prefix('trash')->group(function () {
            Route::get('/{type}', [TrashController::class, 'index']);
            Route::post('/{type}/{id}/restore', [TrashController::class, 'restore']);
            Route::delete('/{type}/{id}', [TrashController::class, 'forceDelete']);
        });

        Route::get('/settings', [SystemSettingsController::class, 'index']);
        Route::put('/settings', [SystemSettingsController::class, 'update']);

        /*
         * Contrasena de autorizacion para cancelaciones. Vive fuera del
         * endpoint generico de settings porque se guarda hasheada, y su
         * escritura queda reservada al admin: es la llave que delega la
         * facultad de anular consumo ya registrado.
         */
        Route::get('/settings/cancellation-password', [SystemSettingsController::class, 'cancellationPassword']);
        Route::middleware('role:admin')->put(
            '/settings/cancellation-password',
            [SystemSettingsController::class, 'updateCancellationPassword']
        );

        Route::prefix('roles')->group(function () {
            Route::get('/', [RoleController::class, 'index']);
            Route::get('/permissions', [RoleController::class, 'permissions']);
            Route::post('/', [RoleController::class, 'store']);
            Route::get('/{role}', [RoleController::class, 'show']);
            Route::put('/{role}', [RoleController::class, 'update']);
            Route::delete('/{role}', [RoleController::class, 'destroy']);
        });

        Route::prefix('mail-templates')->group(function () {
            Route::get('/', [MailPreviewController::class, 'index']);
            Route::get('/{slug}/render', [MailPreviewController::class, 'render']);
        });
    });

    /*
     * Outbound mailing credentials. Admin-only — these rows hold the provider
     * API key and decide where financial reports are delivered, so managers
     * (who may administer users and settings) are deliberately left out.
     */
    Route::middleware('role:admin')->prefix('admin/email-configurations')->group(function () {
        Route::get('/', [EmailConfigurationController::class, 'index']);
        Route::get('/catalogs', [EmailConfigurationController::class, 'catalogs']);

        /*
         * Synchronous health check. It sends a real message through the real
         * transport inside the request, so it is throttled apart from the
         * global quota: each click costs an SMTP dial against the provider and
         * an email in somebody's inbox.
         */
        Route::post('/test-connection', [EmailConfigurationController::class, 'testConnection'])
            ->middleware('throttle:10,1');

        Route::post('/', [EmailConfigurationController::class, 'store']);
        Route::put('/{email_configuration}', [EmailConfigurationController::class, 'update']);
        Route::patch('/{email_configuration}/toggle-status', [EmailConfigurationController::class, 'toggleStatus']);
        Route::delete('/{email_configuration}', [EmailConfigurationController::class, 'destroy']);
    });

    /*
     * Per-module cache policies. Admin-only — these rows decide for how long
     * the whole operation reads figures from Redis instead of PostgreSQL, so
     * a wrong window here makes every dashboard in the business show stale
     * numbers.
     */
    Route::middleware('role:admin')->prefix('admin/cache-configurations')->group(function () {
        Route::get('/', [CacheConfigurationController::class, 'index']);
        Route::get('/catalogs', [CacheConfigurationController::class, 'catalogs']);
        Route::post('/', [CacheConfigurationController::class, 'store']);
        Route::put('/{cache_configuration}', [CacheConfigurationController::class, 'update']);
        Route::patch('/{cache_configuration}/toggle-status', [CacheConfigurationController::class, 'toggleStatus']);
        Route::post('/{cache_configuration}/flush', [CacheConfigurationController::class, 'flush']);
        Route::delete('/{cache_configuration}', [CacheConfigurationController::class, 'destroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | Modulo de Medios Enterprise
    |--------------------------------------------------------------------------
    |
    | Tres familias con tres niveles de acceso, y la diferencia entre ellas no
    | es de comodidad sino de que puede romper cada una:
    |
    |  - Biblioteca (lectura): cualquier usuario operativo. Un cajero necesita
    |    ver el logo o la ficha tecnica de un producto.
    |  - Biblioteca (escritura) y enlaces: admin y manager. Subir, renombrar,
    |    archivar y sobre todo COMPARTIR mueve informacion fuera del sistema.
    |  - Tipos permitidos, credenciales y auditoria: SOLO admin. La primera
    |    define que entra al servidor, la segunda guarda la identidad contra
    |    Google, y la tercera es la evidencia forense del modulo.
    |
    */
    Route::prefix('media')->group(function () {

        // Lectura de la biblioteca y de los bytes. `content` exige sesion: los
        // archivos son privados y el POS es quien decide quien los ve.
        Route::get('/files', [MediaLibraryController::class, 'index']);
        Route::get('/files/{mediaFile}', [MediaLibraryController::class, 'show']);
        Route::get('/files/{mediaFile}/content', [MediaLibraryController::class, 'content']);

        Route::middleware('role:admin,manager')->group(function () {
            /*
             * La subida sale del cupo global de 100 req/min: cada peticion
             * cuesta una escritura en Drive y varias llamadas de permisos, y
             * un formulario en bucle saturaria la cuota de la API de Google
             * mucho antes que la del servidor.
             */
            Route::post('/files', [MediaLibraryController::class, 'store'])
                ->middleware('throttle:30,1');

            Route::put('/files/{mediaFile}', [MediaLibraryController::class, 'update']);
            Route::patch('/files/{mediaFile}/toggle-status', [MediaLibraryController::class, 'toggleStatus']);
            Route::delete('/files/{mediaFile}', [MediaLibraryController::class, 'destroy']);

            // Reaplica el contrato de privacidad sobre un archivo que alguien
            // pudo haber compartido a mano desde la interfaz de Drive.
            Route::post('/files/{mediaFile}/reapply-permissions', [MediaLibraryController::class, 'reapplyPermissions']);

            Route::get('/files/{mediaFile}/share-links', [MediaShareLinkController::class, 'index']);
            Route::post('/files/{mediaFile}/share-links', [MediaShareLinkController::class, 'store']);
            Route::delete('/files/{mediaFile}/share-links/{shareLink}', [MediaShareLinkController::class, 'destroy']);
        });

        /*
         * Catalogo dinamico de tipos permitidos. Solo admin: esta tabla ES la
         * politica de subida del sistema, y habilitar una extension equivocada
         * abre una via de entrada al servidor sin pasar por un despliegue.
         */
        Route::middleware('role:admin')->prefix('file-types')->group(function () {
            Route::get('/', [AllowedFileTypeController::class, 'index']);
            Route::get('/catalogs', [AllowedFileTypeController::class, 'catalogs']);
            Route::post('/', [AllowedFileTypeController::class, 'store']);
            Route::put('/{allowed_file_type}', [AllowedFileTypeController::class, 'update']);
            Route::patch('/{allowed_file_type}/toggle-status', [AllowedFileTypeController::class, 'toggleStatus']);
            Route::delete('/{allowed_file_type}', [AllowedFileTypeController::class, 'destroy']);
        });

        /*
         * Credenciales de Google Drive. Solo admin, por la misma razon que el
         * modulo de correo: estas filas guardan la identidad con la que el POS
         * se presenta ante un tercero.
         */
        Route::middleware('role:admin')->prefix('drive')->group(function () {
            Route::get('/credentials', [DriveCredentialController::class, 'show']);
            Route::post('/credentials', [DriveCredentialController::class, 'store']);
            Route::delete('/credentials', [DriveCredentialController::class, 'destroy']);

            /*
             * Diagnostico sincrono. Con freno propio: cada clic dispara una
             * emision de token y una lectura de carpeta contra Google, y la
             * cuota de esa API es un recurso compartido de la organizacion.
             */
            Route::post('/test-connection', [DriveCredentialController::class, 'testConnection'])
                ->middleware('throttle:10,1');
        });

        // Visor forense. Solo lectura por diseno: la traza la escribe el
        // servicio como efecto de acciones reales, nunca un endpoint.
        Route::middleware('role:admin')->prefix('audit')->group(function () {
            Route::get('/', [MediaAuditLogController::class, 'index']);
            Route::get('/catalogs', [MediaAuditLogController::class, 'catalogs']);
        });
    });

    Route::middleware('role:admin,manager')->prefix('analytics')->group(function () {
        Route::get('/sales-by-payment', [AnalyticsController::class, 'salesByPaymentMethod']);
        Route::get('/financial-summary', [AnalyticsController::class, 'financialSummary']);
        Route::get('/daily-trend', [AnalyticsController::class, 'dailyTrend']);
    });

    Route::prefix('profile')->group(function () {
        Route::get('/', [ProfileController::class, 'show']);
        Route::put('/', [ProfileController::class, 'update']);
        Route::put('/password', [ProfileController::class, 'updatePassword']);
        Route::get('/sessions', [ProfileController::class, 'sessions']);
        Route::post('/sessions/{sessionId}/revoke', [ProfileController::class, 'revokeSession']);

        Route::prefix('notifications')->group(function () {
            Route::get('/', [NotificationPreferenceController::class, 'index']);
            Route::put('/', [NotificationPreferenceController::class, 'update']);
        });
    });

    Route::prefix('petty-cash')->group(function () {
        Route::get('/', [PettyCashController::class, 'index']);
        Route::post('/', [PettyCashController::class, 'store']);
        Route::get('/summary', [PettyCashController::class, 'summary']);
        Route::get('/{pettyCashTransaction}', [PettyCashController::class, 'show']);
        Route::get('/{pettyCashTransaction}/verify', [PettyCashController::class, 'verify']);
    });

    Route::prefix('cash-registers')->group(function () {
        Route::get('/active', [CashRegisterController::class, 'active']);
        Route::post('/open', [CashRegisterController::class, 'open']);
        Route::get('/current', [CashRegisterClosingController::class, 'current']);
        Route::post('/close', [CashRegisterClosingController::class, 'store']);
        Route::get('/closings/export-excel', [CashRegisterClosingController::class, 'exportExcel']);
        /*
         * Preflight of the manual report: answers with the recipients stored
         * for the `sales` process, or 422 when that configuration does not
         * exist, so the modal never opens on a mailbox that cannot send.
         */
        Route::get('/closings/email-configuration', [CashRegisterClosingController::class, 'emailConfiguration']);
        Route::post('/closings/send-email', [CashRegisterClosingController::class, 'sendEmail']);
        Route::get('/closings/{closing}/pdf', [CashRegisterClosingController::class, 'exportPdf']);
        Route::get('/closings', [CashRegisterClosingController::class, 'index']);
    });
});
