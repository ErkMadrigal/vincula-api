<?php
namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddFotoPerfil extends Migration
{
    public function up()
    {
        if (!$this->db->fieldExists('foto', 'usuarios')) {
            $this->forge->addColumn('usuarios', [
                'foto' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
                    'null'       => true,
                    'after'      => 'fcm_token',
                ],
            ]);
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('foto', 'usuarios')) {
            $this->forge->dropColumn('usuarios', 'foto');
        }
    }
}