<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class CreateDispositivos extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'auto_increment' => true],
            'usuario_id'  => ['type' => 'INT'],
            'fcm_token'   => ['type' => 'VARCHAR', 'constraint' => 255],
            'plataforma'  => ['type' => 'ENUM', 'constraint' => ['android','ios'], 'default' => 'android'],
            'activo'      => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addForeignKey('usuario_id', 'usuarios', 'id');
        $this->forge->createTable('dispositivos');
    }

    public function down()
    {
        $this->forge->dropTable('dispositivos');
    }
}