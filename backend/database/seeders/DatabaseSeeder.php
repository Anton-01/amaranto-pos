<?php

namespace Database\Seeders;

use App\Models\GlobalSetting;
use App\Models\NotificationType;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\Table;
use App\Models\TicketConfig;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = Role::create(['name' => 'admin']);
        Role::create(['name' => 'manager']);
        Role::create(['name' => 'vendor']);

        $user = User::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Administrador',
            'email' => 'admin@cronos.pos',
            'password' => 'password',
            'status' => 'active',
        ]);

        $user->roles()->attach($admin->id);

        PaymentMethod::create([
            'name' => 'Efectivo',
            'slug' => 'cash',
            'status' => 'active',
            'is_system' => true,
        ]);
        PaymentMethod::create([
            'name' => 'Tarjeta de Crédito/Débito',
            'slug' => 'card',
            'status' => 'active',
            'is_system' => true,
        ]);
        PaymentMethod::create([
            'name' => 'Transferencia',
            'slug' => 'transfer',
            'status' => 'active',
            'is_system' => true,
        ]);

        // Plano base del comedor: 8 mesas repartidas en dos zonas.
        $floorPlan = [
            ['name' => 'Mesa 1', 'capacity' => 2, 'zone' => 'Salón'],
            ['name' => 'Mesa 2', 'capacity' => 4, 'zone' => 'Salón'],
            ['name' => 'Mesa 3', 'capacity' => 4, 'zone' => 'Salón'],
            ['name' => 'Mesa 4', 'capacity' => 6, 'zone' => 'Salón'],
            ['name' => 'Mesa 5', 'capacity' => 2, 'zone' => 'Terraza'],
            ['name' => 'Mesa 6', 'capacity' => 4, 'zone' => 'Terraza'],
            ['name' => 'Mesa 7', 'capacity' => 8, 'zone' => 'Terraza'],
            ['name' => 'Barra 1', 'capacity' => 1, 'zone' => 'Barra'],
        ];

        foreach ($floorPlan as $table) {
            Table::create($table + ['status' => 'available', 'is_active' => true]);
        }

        $notificationTypes = [
            [
                'slug' => 'low_stock_alert',
                'name' => 'Alerta de Stock Bajo',
                'description' => 'Se dispara cuando un producto alcanza o baja del stock mínimo configurado.',
                'allowed_roles' => ['admin', 'manager'],
            ],
            [
                'slug' => 'cash_register_closed',
                'name' => 'Cierre de Caja',
                'description' => 'Notifica cuando un usuario cierra su turno de caja registradora.',
                'allowed_roles' => ['admin', 'manager'],
            ],
            [
                'slug' => 'petty_cash_withdrawal',
                'name' => 'Retiro de Caja Chica',
                'description' => 'Se dispara cuando se registra un retiro de fondos de caja chica.',
                'allowed_roles' => ['admin'],
            ],
            [
                'slug' => 'daily_sales_summary',
                'name' => 'Resumen Diario de Ventas',
                'description' => 'Resumen consolidado de las ventas del día enviado al cierre de operaciones.',
                'allowed_roles' => ['admin', 'manager'],
            ],
            [
                'slug' => 'promotion_expiring',
                'name' => 'Promoción por Vencer',
                'description' => 'Alerta cuando una promoción activa está próxima a su fecha de finalización.',
                'allowed_roles' => ['admin', 'manager'],
            ],
            [
                'slug' => 'user_suspended',
                'name' => 'Usuario Suspendido',
                'description' => 'Notifica cuando un usuario ha sido suspendido del sistema.',
                'allowed_roles' => ['admin'],
            ],
        ];

        foreach ($notificationTypes as $type) {
            NotificationType::create($type);
        }

        GlobalSetting::create([
            'key' => 'tax_rate',
            'value' => ['rate' => 0.16, 'label' => 'IVA 16%'],
        ]);

        GlobalSetting::create([
            'key' => 'investment_split',
            'value' => ['investment_pct' => 70, 'profit_pct' => 30],
        ]);

        GlobalSetting::create([
            'key' => 'timezone',
            'value' => ['timezone' => 'America/Mexico_City', 'label' => 'Ciudad de México (UTC-6)'],
        ]);

        GlobalSetting::create([
            'key' => 'currency',
            'value' => ['code' => 'MXN', 'symbol' => '$', 'label' => 'Peso Mexicano'],
        ]);

        $fiscalData = [
            'business_name' => 'Cronos POS',
            'rfc' => 'XAXX010101000',
            'address' => 'Av. Principal #123, Col. Centro',
            'city' => 'Ciudad de México',
            'phone' => '55-1234-5678',
        ];

        GlobalSetting::create([
            'key' => 'fiscal_data',
            'value' => $fiscalData,
        ]);

        /*
         * Configuracion de ticket ACTIVA — version 1.
         *
         * No es un adorno: sin una fila activa aqui, el sistema no puede
         * VENDER. Tanto OrderController@store (mostrador) como
         * TableSessionController@open (comedor) abortan con
         * ERR_TICKET_NO_ACTIVE_CONFIG y devuelven 422, de modo que una
         * instalacion recien sembrada quedaba inoperante: el cajero no podia
         * cobrar y el mesero no podia abrir una mesa.
         *
         * Los datos replican `fiscal_data` para que ambas fuentes nazcan
         * coherentes. El administrador puede versionarla desde /ticket-config
         * cuando cargue los datos reales del negocio (el modulo es
         * append-only: cada cambio crea una version nueva y desactiva la
         * anterior, nunca edita esta).
         */
        TicketConfig::create([
            'version' => 1,
            'is_active' => true,
            'business_name' => $fiscalData['business_name'],
            'rfc' => $fiscalData['rfc'],
            'address' => $fiscalData['address'].', '.$fiscalData['city'],
            'phone' => $fiscalData['phone'],
            'header_message' => 'Gracias por su preferencia',
            'footer_message' => 'Conserve su ticket para cualquier aclaracion',
            'updated_by' => $user->id,
        ]);

        if ($this->shouldSeedDemoData()) {
            $this->call([
                DemoCatalogSeeder::class,
                DemoSalesSeeder::class,
            ]);
        }
    }

    /**
     * ¿Se siembran catalogo y ventas de demostracion?
     *
     * Fuera de produccion es lo deseable: un entorno recien levantado tiene que
     * poder venderse, imprimirse y arquearse sin capturar nada a mano. En
     * produccion queda APAGADO salvo peticion explicita — un ticket falso en la
     * base real contamina el historial de ventas y el arqueo de caja.
     *
     * `SEED_DEMO_DATA` manda sobre el entorno en ambas direcciones (docker
     * compose lo fija en `true` para el entorno local).
     */
    private function shouldSeedDemoData(): bool
    {
        $flag = env('SEED_DEMO_DATA');

        if ($flag !== null && $flag !== '') {
            return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
        }

        return ! app()->isProduction();
    }
}
