<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class CreateAsistencias extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'auto_increment' => true],
            'escuela_id'  => ['type' => 'INT'],
            'alumno_id'   => ['type' => 'INT'],
            'maestro_id'  => ['type' => 'INT'],
            'tipo'        => ['type' => 'ENUM', 'constraint' => ['entrada','salida']],
            'fecha'       => ['type' => 'DATE'],
            'hora'        => ['type' => 'TIME'],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addForeignKey('escuela_id', 'escuelas', 'id');
        $this->forge->addForeignKey('alumno_id',  'alumnos',  'id');
        $this->forge->addForeignKey('maestro_id', 'usuarios', 'id');
        $this->forge->createTable('asistencias');
    }

    public function down()
    {
        $this->forge->dropTable('asistencias');
    }
}