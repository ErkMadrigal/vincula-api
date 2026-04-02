<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class AddDirectorRol extends Migration
{
    public function up()
    {
        $this->db->query("ALTER TABLE usuarios MODIFY rol ENUM('super_admin','admin','director','maestro','padre') DEFAULT 'padre'");
    }

    public function down()
    {
        $this->db->query("ALTER TABLE usuarios MODIFY rol ENUM('super_admin','admin','maestro','padre') DEFAULT 'padre'");
    }
}