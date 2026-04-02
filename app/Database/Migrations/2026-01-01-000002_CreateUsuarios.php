<?php

namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class CreateUsuarios extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'auto_increment' => true],
            'escuela_id'  => ['type' => 'INT'],
            'curp'        => ['type' => 'VARCHAR', 'constraint' => 18, 'unique' => true],
            'nombre'      => ['type' => 'VARCHAR', 'constraint' => 100],
            'password'    => ['type' => 'VARCHAR', 'constraint' => 255],
            'rol'         => ['type' => 'ENUM', 'constraint' => ['super_admin','admin','maestro','padre'], 'default' => 'padre'],
            'activo'      => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'fcm_token'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addForeignKey('escuela_id', 'escuelas', 'id');
        $this->forge->createTable('usuarios');
    }

    public function down()
    {
        $this->forge->dropTable('usuarios');
    }
}