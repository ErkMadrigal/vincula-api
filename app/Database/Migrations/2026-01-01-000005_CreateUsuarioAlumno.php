<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class CreateUsuarioAlumno extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'auto_increment' => true],
            'usuario_id'  => ['type' => 'INT'],
            'alumno_id'   => ['type' => 'INT'],
            'relacion'    => ['type' => 'ENUM', 'constraint' => ['padre','madre','tutor'], 'default' => 'padre'],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['usuario_id', 'alumno_id']);
        $this->forge->addForeignKey('usuario_id', 'usuarios', 'id');
        $this->forge->addForeignKey('alumno_id', 'alumnos', 'id');
        $this->forge->createTable('usuario_alumno');
    }

    public function down()
    {
        $this->forge->dropTable('usuario_alumno');
    }
}