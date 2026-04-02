<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class CreateAuditoria extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'auto_increment' => true],
            'escuela_id'  => ['type' => 'INT', 'null' => true],
            'usuario_id'  => ['type' => 'INT', 'null' => true],
            'nombre'      => ['type' => 'VARCHAR', 'constraint' => 100],
            'rol'         => ['type' => 'VARCHAR', 'constraint' => 30],
            'accion'      => ['type' => 'ENUM', 'constraint' => ['crear','editar','eliminar','login','logout','bloquear','desbloquear']],
            'modulo'      => ['type' => 'VARCHAR', 'constraint' => 50],
            'descripcion' => ['type' => 'TEXT', 'null' => true],
            'ip'          => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->createTable('auditoria');
    }

    public function down()
    {
        $this->forge->dropTable('auditoria');
    }
}