<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communities', function (Blueprint $table): void {
            $table->string('avatar_disk', 32)->default('public');
            $table->string('avatar_path')->nullable();
            $table->string('cover_disk', 32)->default('public');
            $table->string('cover_path')->nullable();
        });

        DB::transaction(function (): void {
            DB::table('communities')->select(['id', 'actor_id'])->orderBy('id')->chunkById(100, function ($communities): void {
                foreach ($communities as $community) {
                    $actor = DB::table('actors')->where('id', $community->actor_id)->first();
                    if ($actor === null || ! $actor->is_local) {
                        continue;
                    }
                    $files = $urls = [];
                    foreach (['avatar' => 'icon', 'cover' => 'image'] as $slot => $field) {
                        $path = $actor->{$field.'_path'} ?? $this->localPath($actor->{$field.'_url'});
                        if (filled($path)) {
                            $files[$slot.'_path'] = $path;
                            $files[$slot.'_disk'] = $actor->{$field.'_disk'} ?? 'public';
                            $urls[$field.'_url'] = null;
                        }
                    }
                    if ($files !== []) {
                        DB::table('communities')->where('id', $community->id)->update($files);
                        DB::table('actors')->where('id', $actor->id)->update($urls);
                    }
                }
            });
        });

        Schema::table('actors', fn (Blueprint $table) => $table->dropColumn(['icon_disk', 'icon_path', 'image_disk', 'image_path']));
    }

    public function down(): void
    {
        Schema::table('actors', function (Blueprint $table): void {
            $table->string('icon_disk', 32)->nullable();
            $table->string('icon_path')->nullable();
            $table->string('image_disk', 32)->nullable();
            $table->string('image_path')->nullable();
        });
        DB::transaction(function (): void {
            DB::table('communities')->orderBy('id')->chunkById(100, function ($communities): void {
                foreach ($communities as $community) {
                    $actor = DB::table('actors')->where('id', $community->actor_id)->first();
                    if ($actor === null || ! $actor->is_local) {
                        continue;
                    }
                    DB::table('actors')->where('id', $actor->id)->update([
                        'icon_disk' => $community->avatar_path ? $community->avatar_disk : null,
                        'icon_path' => $community->avatar_path,
                        'image_disk' => $community->cover_path ? $community->cover_disk : null,
                        'image_path' => $community->cover_path,
                        'icon_url' => $community->avatar_path
                            ? $this->legacyUrl($community->avatar_disk, $community->avatar_path, $actor->id, 'icon') : $actor->icon_url,
                        'image_url' => $community->cover_path
                            ? $this->legacyUrl($community->cover_disk, $community->cover_path, $actor->id, 'image') : $actor->image_url,
                    ]);
                }
            });
        });
        Schema::table('communities', fn (Blueprint $table) => $table->dropColumn(['avatar_path', 'avatar_disk', 'cover_path', 'cover_disk']));
    }

    private function localPath(?string $url): ?string
    {
        $base = rtrim(Storage::disk('public')->url(''), '/').'/';
        if (! filled($url) || ! str_starts_with($url, $base)) {
            return null;
        }
        $path = substr($url, strlen($base));

        return preg_match('~^(avatars|covers)/[a-zA-Z0-9._-]+$~', $path) ? $path : null;
    }

    private function legacyUrl(string $disk, string $path, string $id, string $slot): string
    {
        return $disk === 'public'
            ? rtrim(Storage::disk('public')->url(''), '/').'/'.$path
            : rtrim((string) config('app.url'), '/').'/asset-files/actor/'.$id.'/'.$slot.'/'.sha1($path);
    }
};
