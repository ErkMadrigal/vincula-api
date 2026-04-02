<?php
namespace App\Models;
use CodeIgniter\Model;

class AlumnoModel extends Model
{
    protected $table         = 'alumnos';
    protected $primaryKey    = 'id';
    protected $allowedFields = [
        'escuela_id','uuid','curp','nombre','grado','grupo','activo','pagado'
    ];
    protected $useTimestamps = true;

    public function findByUuid(string $uuid): array|null
    {
        return $this->where('uuid', $uuid)->where('activo', 1)->first();
    }

    public function misHijos(int $usuarioId): array
    {
        return $this->db->table('alumnos a')
            ->select('a.id, a.uuid, a.nombre, a.grado, a.grupo, a.pagado, ua.relacion')
            ->join('usuario_alumno ua', 'ua.alumno_id = a.id')
            ->where('ua.usuario_id', $usuarioId)
            ->where('a.activo', 1)
            ->get()->getResultArray();
    }
}