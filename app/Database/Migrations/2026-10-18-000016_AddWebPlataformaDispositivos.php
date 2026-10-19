<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

// Permite registrar tokens de notificaciones push de navegador (app web / PWA)
class AddWebPlataformaDispositivos extends Migration
{
    public function up()
    {
        $this->forge->modifyColumn('dispositivos', [
            'plataforma' => ['type' => 'ENUM', 'constraint' => ['android', 'ios', 'web'], 'default' => 'android'],
        ]);
    }

    public function down()
    {
        $this->forge->modifyColumn('dispositivos', [
            'plataforma' => ['type' => 'ENUM', 'constraint' => ['android', 'ios'], 'default' => 'android'],
        ]);
    }
}
