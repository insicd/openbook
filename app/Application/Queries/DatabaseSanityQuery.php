<?php

namespace App\Application\Queries;

use App\Domain\Comments\Comment;
use App\Domain\Events\Event;
use App\Domain\Events\EventComment;
use App\Domain\Events\EventParticipation;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class DatabaseSanityQuery
{
    private const RELATIONS = [
        'likes' => ['morph' => 'likeable', 'models' => [Post::class, Comment::class, Event::class, EventComment::class]],
        'mentions' => ['morph' => 'mentionable', 'models' => [Post::class, Comment::class, Event::class, EventComment::class]],
        'notifications' => ['morph' => 'notifiable', 'models' => [Post::class, Comment::class, Follow::class, Actor::class, Event::class, EventComment::class, EventParticipation::class]],
    ];

    /** @return array<string, array<string, int>> */
    public function samples(int $limit): array
    {
        $counts = [];
        foreach ($this->relations() as $table => $relation) {
            foreach (array_keys($relation['parents']) as $type) {
                $counts[$table][$type] = $this->orphans($table, $type, $limit)->get()->count();
            }
        }

        return $counts;
    }

    /** @return array<string, array<string, int>> */
    public function unsupportedCounts(): array
    {
        $counts = [];
        foreach (array_keys($this->relations()) as $table) {
            $counts[$table] = array_map(intval(...), $this->unknownTypes($table)->pluck('row_count', 'type')->all());
        }

        return $counts;
    }

    /** @return array<string, array{morph: string, parents: array<string, string>}> */
    public function relations(): array
    {
        $relations = [];

        foreach (self::RELATIONS as $table => $definition) {
            $parents = [];
            foreach ($definition['models'] as $class) {
                $model = new $class;
                $parents[$model->getMorphClass()] = $model->getTable();
            }
            $relations[$table] = ['morph' => $definition['morph'], 'parents' => $parents];
        }

        return $relations;
    }

    public function orphans(string $table, string $type, int $limit, ?string $afterId = null): Builder
    {
        $definition = $this->definition($table);
        $parent = $definition['parents'][$type] ?? throw new InvalidArgumentException('Unsupported sanity parent type.');
        $this->validateLimit($limit);

        // Raw tables deliberately include parents whose status is deleted.
        return DB::table($table)
            ->select($table.'.id')
            ->where($table.'.'.$definition['morph'].'_type', $type)
            ->whereNotExists(function (Builder $query) use ($table, $definition, $parent): void {
                $query->selectRaw('1')->from($parent.' as sanity_parent')
                    ->whereColumn('sanity_parent.id', $table.'.'.$definition['morph'].'_id');
            })
            ->when($afterId !== null, fn (Builder $query) => $query->where($table.'.id', '>', $afterId))
            ->orderBy($table.'.id')
            ->limit($limit);
    }

    public function unknownTypes(string $table, int $limit = 100): Builder
    {
        $definition = $this->definition($table);
        $this->validateLimit($limit);
        $column = $definition['morph'].'_type';

        return DB::table($table)->select($column.' as type')->selectRaw('COUNT(*) as row_count')
            ->whereNotIn($column, array_keys($definition['parents']))
            ->groupBy($column)->orderBy($column)->limit($limit);
    }

    /** @return array{morph: string, parents: array<string, string>} */
    private function definition(string $table): array
    {
        return $this->relations()[$table] ?? throw new InvalidArgumentException('Unsupported sanity table.');
    }

    private function validateLimit(int $limit): void
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('The sanity batch limit must be positive.');
        }
    }
}
