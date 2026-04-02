<?php

namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class CreateEscuelas extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'auto_increment' => true],
            'nombre'      => ['type' => 'VARCHAR', 'constraint' => 100],
            'subdominio'  => ['type' => 'VARCHAR', 'constraint' => 50],
            'plan'        => ['type' => 'ENUM', 'constraint' => ['basico','pro'], 'default' => 'basico'],
            'activa'      => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->createTable('escuelas');
    }

    public function down()
    {
        $this->forge->dropTable('escuelas');
    }
}