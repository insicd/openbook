<?php

namespace App\Domain\Posts;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string $id
 * @property string $name
 */
class Hashtag extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
    ];

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_hashtags');
    }

    /**
     * Normalizza un tag grezzo (con o senza "#") nel formato canonico
     * salvato in "name": minuscolo, senza simbolo.
     */
    public static function normalize(string $raw): string
    {
        return mb_strtolower(ltrim(trim($raw), '#'));
    }
}
