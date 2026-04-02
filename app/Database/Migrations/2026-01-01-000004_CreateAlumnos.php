<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class CreateAlumnos extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'auto_increment' => true],
            'escuela_id'  => ['type' => 'INT'],
            'uuid'        => ['type' => 'VARCHAR', 'constraint' => 36],
            'curp'        => ['type' => 'VARCHAR', 'constraint' => 18],
            'nombre'      => ['type' => 'VARCHAR', 'constraint' => 100],
            'grado'       => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'grupo'       => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
            'activo'      => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'pagado'      => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('uuid');
        $this->forge->addUniqueKey(['escuela_id', 'curp']);
        $this->forge->addForeignKey('escuela_id', 'escuelas', 'id');
        $this->forge->createTable('alumnos');
    }

    public function down()
    {
        $this->forge->dropTable('alumnos');
    }
}