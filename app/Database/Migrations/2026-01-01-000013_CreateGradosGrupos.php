<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class CreateGradosGrupos extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'auto_increment' => true],
            'escuela_id' => ['type' => 'INT'],
            'grado'      => ['type' => 'VARCHAR', 'constraint' => 20],
            'grupo'      => ['type' => 'VARCHAR', 'constraint' => 10],
            'turno'      => ['type' => 'ENUM', 'constraint' => ['matutino','vespertino'], 'default' => 'matutino'],
            'activo'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['escuela_id', 'grado', 'grupo', 'turno']);
        $this->forge->addForeignKey('escuela_id', 'escuelas', 'id');
        $this->forge->createTable('grados_grupos');
    }

    public function down()
    {
        $this->forge->dropTable('grados_grupos');
    }
}