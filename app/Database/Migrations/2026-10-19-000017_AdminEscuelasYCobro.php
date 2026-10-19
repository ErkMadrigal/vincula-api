<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

/**
 * - admin_escuelas: escuelas que puede ver cada admin (asignadas por el super admin o creadas por él).
 * - escuelas.cobro_activo: si está en 0, la asistencia NO revisa si el alumno pagó.
 * - escuelas.creada_por: usuario que creó la escuela.
 */
class AdminEscuelasYCobro extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'auto_increment' => true],
            'usuario_id' => ['type' => 'INT'],
            'escuela_id' => ['type' => 'INT'],
            'origen'     => ['type' => 'ENUM', 'constraint' => ['asignada', 'creada'], 'default' => 'asignada'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['usuario_id', 'escuela_id']);
        $this->forge->addForeignKey('usuario_id', 'usuarios', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('escuela_id', 'escuelas', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('admin_escuelas');

        $this->forge->addColumn('escuelas', [
            'cobro_activo' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'after' => 'activa'],
            'creada_por'   => ['type' => 'INT', 'null' => true, 'after' => 'cobro_activo'],
        ]);

        // Los admins que ya existen conservan su escuela actual
        $this->db->query("
            INSERT IGNORE INTO admin_escuelas (usuario_id, escuela_id, origen, created_at)
            SELECT id, escuela_id, 'asignada', NOW() FROM usuarios
            WHERE rol = 'admin' AND escuela_id IS NOT NULL
        ");
    }

    public function down()
    {
        $this->forge->dropTable('admin_escuelas');
        $this->forge->dropColumn('escuelas', ['cobro_activo', 'creada_por']);
    }
}
