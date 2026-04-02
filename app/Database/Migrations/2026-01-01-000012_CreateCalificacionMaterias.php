<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class CreateCalificacionMaterias extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'               => ['type' => 'INT', 'auto_increment' => true],
            'calificacion_id'  => ['type' => 'INT'],
            'materia'          => ['type' => 'VARCHAR', 'constraint' => 100],
            'calificacion'     => ['type' => 'DECIMAL', 'constraint' => '4,2'],
            'observaciones'    => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addForeignKey('calificacion_id', 'calificaciones', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('calificacion_materias');
    }

    public function down()
    {
        $this->forge->dropTable('calificacion_materias');
    }
}