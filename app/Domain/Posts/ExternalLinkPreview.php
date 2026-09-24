<?php

namespace App\Domain\Posts;

use Illuminate\Database\Eloquent\Model;

class ExternalLinkPreview extends Model
{
    protected $primaryKey = 'url_hash';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'url_hash', 'url', 'available', 'title', 'description', 'site_name', 'image_url', 'fetched_at',
    ];

    protected function casts(): array
    {
        return ['available' => 'boolean', 'fetched_at' => 'datetime'];
    }
}
