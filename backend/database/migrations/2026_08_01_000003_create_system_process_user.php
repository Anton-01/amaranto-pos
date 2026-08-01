<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Siembra la identidad "System Automated Process" bajo la que firman los
     * jobs del scheduler (cierre automatico de cajas a las 21:00).
     *
     * Va en migracion —no en el seeder— para que exista tambien en las bases
     * de produccion ya desplegadas. Es idempotente por email.
     *
     * El usuario es inoperable de forma deliberada: password aleatorio de 64
     * caracteres que nadie conoce, sin rol asignado (el RBAC le niega todo
     * endpoint operativo) y sin 2FA. Solo existe como FK de auditoria.
     */
    public function up(): void
    {
        $exists = DB::table('users')->where('email', \App\Models\User::SYSTEM_EMAIL)->exists();

        if ($exists) {
            return;
        }

        DB::table('users')->insert([
            'id' => Str::uuid()->toString(),
            'name' => 'System Automated Process',
            'email' => \App\Models\User::SYSTEM_EMAIL,
            'password' => Hash::make(Str::random(64)),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // No se elimina: los cierres automaticos historicos lo referencian con
        // FK restrict y borrar la identidad romperia la cadena de auditoria.
    }
};
