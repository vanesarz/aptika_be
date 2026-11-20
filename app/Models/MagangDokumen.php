<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MagangDokumen extends Model
{
    protected $fillable = ['jenis', 'status', 'nomor', 'pengesah', 'file', 'catatan', 'dicatat_oleh'];
}
