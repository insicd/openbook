<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Estende le segnalazioni ai commenti: post_id diventa nullable e
     * comment_id opzionale (esattamente uno dei due valorizzato a runtime).
     *
     * I drop di FK/indici usano i nomi reali dallo schema: su MySQL il
     * vincolo atteso da Laravel (`reports_post_id_foreign`) a volte non
     * esiste (indice riusato, creazione parziale, naming diverso).
     */
    public function up(): void
    {
        $this->dropForeignKeyForColumns('reports', ['post_id']);
        $this->dropIndexForColumns('reports', ['reporter_id', 'post_id'], unique: true);

        Schema::table('reports', function (Blueprint $table) {
            $table->uuid('post_id')->nullable()->change();
        });

        if (! Schema::hasColumn('reports', 'comment_id')) {
            Schema::table('reports', function (Blueprint $table) {
                $table->uuid('comment_id')->nullable()->after('post_id');
            });
        }

        if (! $this->hasForeignKeyForColumns('reports', ['post_id'])) {
            Schema::table('reports', function (Blueprint $table) {
                $table->foreign('post_id')->references('id')->on('posts')->cascadeOnDelete();
            });
        }

        if (! $this->hasForeignKeyForColumns('reports', ['comment_id'])) {
            Schema::table('reports', function (Blueprint $table) {
                $table->foreign('comment_id')->references('id')->on('comments')->cascadeOnDelete();
            });
        }

        if (! $this->hasIndexForColumns('reports', ['reporter_id', 'post_id'], unique: true)) {
            Schema::table('reports', function (Blueprint $table) {
                $table->unique(['reporter_id', 'post_id']);
            });
        }

        if (! $this->hasIndexForColumns('reports', ['reporter_id', 'comment_id'], unique: true)) {
            Schema::table('reports', function (Blueprint $table) {
                $table->unique(['reporter_id', 'comment_id']);
            });
        }

        if (! $this->hasIndexForColumns('reports', ['comment_id'], unique: false)) {
            Schema::table('reports', function (Blueprint $table) {
                $table->index('comment_id');
            });
        }
    }

    public function down(): void
    {
        $this->dropForeignKeyForColumns('reports', ['post_id']);
        $this->dropForeignKeyForColumns('reports', ['comment_id']);
        $this->dropIndexForColumns('reports', ['reporter_id', 'post_id'], unique: true);
        $this->dropIndexForColumns('reports', ['reporter_id', 'comment_id'], unique: true);
        $this->dropIndexForColumns('reports', ['comment_id'], unique: false);

        if (Schema::hasColumn('reports', 'comment_id')) {
            Schema::table('reports', function (Blueprint $table) {
                $table->dropColumn('comment_id');
            });
        }

        Schema::table('reports', function (Blueprint $table) {
            $table->uuid('post_id')->nullable(false)->change();
        });

        if (! $this->hasForeignKeyForColumns('reports', ['post_id'])) {
            Schema::table('reports', function (Blueprint $table) {
                $table->foreign('post_id')->references('id')->on('posts')->cascadeOnDelete();
            });
        }

        if (! $this->hasIndexForColumns('reports', ['reporter_id', 'post_id'], unique: true)) {
            Schema::table('reports', function (Blueprint $table) {
                $table->unique(['reporter_id', 'post_id']);
            });
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function dropForeignKeyForColumns(string $table, array $columns): void
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (($foreignKey['columns'] ?? []) !== $columns) {
                continue;
            }

            $name = $foreignKey['name'] ?? null;

            Schema::table($table, function (Blueprint $blueprint) use ($name, $columns): void {
                if (is_string($name) && $name !== '') {
                    $blueprint->dropForeign($name);
                } else {
                    $blueprint->dropForeign($columns);
                }
            });
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasForeignKeyForColumns(string $table, array $columns): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (($foreignKey['columns'] ?? []) === $columns) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $columns
     */
    private function dropIndexForColumns(string $table, array $columns, bool $unique): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['columns'] ?? []) !== $columns) {
                continue;
            }

            if ((bool) ($index['unique'] ?? false) !== $unique) {
                continue;
            }

            if ($index['primary'] ?? false) {
                continue;
            }

            $name = $index['name'] ?? null;

            if (! is_string($name) || $name === '' || str_starts_with($name, 'sqlite_autoindex_')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($name, $unique): void {
                if ($unique) {
                    $blueprint->dropUnique($name);
                } else {
                    $blueprint->dropIndex($name);
                }
            });
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasIndexForColumns(string $table, array $columns, bool $unique): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['columns'] ?? []) !== $columns) {
                continue;
            }

            if ((bool) ($index['unique'] ?? false) !== $unique) {
                continue;
            }

            return true;
        }

        return false;
    }
};
