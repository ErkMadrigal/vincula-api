<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class CreateAvisos extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'auto_increment' => true],
            'escuela_id'  => ['type' => 'INT'],
            'autor_id'    => ['type' => 'INT'],
            'titulo'      => ['type' => 'VARCHAR', 'constraint' => 150],
            'descripcion' => ['type' => 'TEXT'],
            'grado'       => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'grupo'       => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
            'vigencia'    => ['type' => 'DATE', 'null' => true],
            'activo'      => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addForeignKey('escuela_id', 'escuelas', 'id');
        $this->forge->addForeignKey('autor_id',   'usuarios', 'id');
        $this->forge->createTable('avisos');
    }

    public function down()
    {
        $this->forge->dropTable('avisos');
    }
}