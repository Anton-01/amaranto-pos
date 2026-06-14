<?php

namespace Database\Seeders;

use App\Models\NotificationType;
use App\Models\Role;
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
    }
}
