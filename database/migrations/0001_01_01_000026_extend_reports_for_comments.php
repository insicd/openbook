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
     * Su MySQL l'indice unique (reporter_id, post_id) puo' essere quello
     * usato dal FK su reporter_id (prefisso sinistro): non si puo' droppare
     * l'unique senza aver prima rimosso tutti i FK della tabella.
     */
    public function up(): void
    {
        $this->dropAllForeignKeys('reports');
        $this->dropIndexForColumns('reports', ['reporter_id', 'post_id'], unique: true);

        Schema::table('reports', function (Blueprint $table) {
            $table->uuid('post_id')->nullable()->change();
        });

        if (! Schema::hasColumn('reports', 'comment_id')) {
            Schema::table('reports', function (Blueprint $table) {
                $table->uuid('comment_id')->nullable()->after('post_id');
            });
        }

        $this->ensureReportsConstraints(includeComment: true);
    }

    public function down(): void
    {
        $this->dropAllForeignKeys('reports');
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

        $this->ensureReportsConstraints(includeComment: false);
    }

    private function ensureReportsConstraints(bool $includeComment): void
    {
        if (! $this->hasIndexForColumns('reports', ['reporter_id', 'post_id'], unique: true)) {
            Schema::table('reports', function (Blueprint $table) {
                $table->unique(['reporter_id', 'post_id']);
            });
        }

        if ($includeComment && ! $this->hasIndexForColumns('reports', ['reporter_id', 'comment_id'], unique: true)) {
            Schema::table('reports', function (Blueprint $table) {
                $table->unique(['reporter_id', 'comment_id']);
            });
        }

        if ($includeComment && ! $this->hasIndexForColumns('reports', ['comment_id'], unique: false)) {
            Schema::table('reports', function (Blueprint $table) {
                $table->index('comment_id');
            });
        }

        if (! $this->hasForeignKeyForColumns('reports', ['reporter_id'])) {
            Schema::table('reports', function (Blueprint $table) {
                $table->foreign('reporter_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if (! $this->hasForeignKeyForColumns('reports', ['post_id'])) {
            Schema::table('reports', function (Blueprint $table) {
                $table->foreign('post_id')->references('id')->on('posts')->cascadeOnDelete();
            });
        }

        if ($includeComment && ! $this->hasForeignKeyForColumns('reports', ['comment_id'])) {
            Schema::table('reports', function (Blueprint $table) {
                $table->foreign('comment_id')->references('id')->on('comments')->cascadeOnDelete();
            });
        }

        if (! $this->hasForeignKeyForColumns('reports', ['reviewed_by'])) {
            Schema::table('reports', function (Blueprint $table) {
                $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    private function dropAllForeignKeys(string $table): void
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            $name = $foreignKey['name'] ?? null;
            $columns = $foreignKey['columns'] ?? [];

            Schema::table($table, function (Blueprint $blueprint) use ($name, $columns): void {
                if (is_string($name) && $name !== '') {
                    $blueprint->dropForeign($name);
                } elseif ($columns !== []) {
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
