<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class CreateCalificaciones extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'auto_increment' => true],
            'escuela_id'  => ['type' => 'INT'],
            'alumno_id'   => ['type' => 'INT'],
            'autor_id'    => ['type' => 'INT'],
            'bimestre'    => ['type' => 'TINYINT', 'constraint' => 1],
            'ciclo'       => ['type' => 'VARCHAR', 'constraint' => 10],
            'promedio'    => ['type' => 'DECIMAL', 'constraint' => '4,2', 'null' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['alumno_id', 'bimestre', 'ciclo']);
        $this->forge->addForeignKey('escuela_id', 'escuelas', 'id');
        $this->forge->addForeignKey('alumno_id',  'alumnos',  'id');
        $this->forge->addForeignKey('autor_id',   'usuarios', 'id');
        $this->forge->createTable('calificaciones');
    }

    public function down()
    {
        $this->forge->dropTable('calificaciones');
    }
}