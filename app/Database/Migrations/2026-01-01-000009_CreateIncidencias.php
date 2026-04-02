<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class CreateIncidencias extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'auto_increment' => true],
            'escuela_id'  => ['type' => 'INT'],
            'alumno_id'   => ['type' => 'INT'],
            'maestro_id'  => ['type' => 'INT'],
            'tipo'        => ['type' => 'ENUM', 'constraint' => ['pelea','falta','conducta','accidente','otro']],
            'gravedad'    => ['type' => 'ENUM', 'constraint' => ['leve','moderada','grave']],
            'descripcion' => ['type' => 'TEXT'],
            'foto'        => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'acuse'       => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'acuse_at'    => ['type' => 'DATETIME', 'null' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addForeignKey('escuela_id', 'escuelas', 'id');
        $this->forge->addForeignKey('alumno_id',  'alumnos',  'id');
        $this->forge->addForeignKey('maestro_id', 'usuarios', 'id');
        $this->forge->createTable('incidencias');
    }

    public function down()
    {
        $this->forge->dropTable('incidencias');
    }
}