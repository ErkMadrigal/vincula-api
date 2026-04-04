<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class AddPasswordChanged extends Migration
{
    // app/Database/Migrations/2024-01-01-000015_AddPasswordChanged.php
    public function up()
    {
        $this->db->query("ALTER TABLE usuarios ADD COLUMN password_changed TINYINT(1) DEFAULT 0 AFTER password");
    }

    public function down()
    {
        $this->db->query("ALTER TABLE usuarios DROP COLUMN password_changed");
    }
}