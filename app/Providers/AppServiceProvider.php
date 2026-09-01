<?php

namespace App\Providers;

use App\Application\Queries\PopularHashtagsQuery;
use App\Application\Queries\SidebarSuggestionContext;
use App\Application\Queries\SuggestedActorsByBioQuery;
use App\Application\Queries\SuggestedLocalActorsQuery;
use App\Application\Services\ConversationReadTracker;
use App\Application\Services\InstanceSettings;
use App\Domain\Accounts\User;
use App\Domain\Comments\Comment;
use App\Domain\Communities\Community;
use App\Domain\Moderation\AuditLog;
use App\Domain\Moderation\DomainBlock;
use App\Domain\Moderation\Report;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Infrastructure\Push\BrowserPushGateway;
use App\Infrastructure\Push\WebPushGateway;
use App\Infrastructure\Security\Http\DnsResolver;
use App\Infrastructure\Security\Http\SystemDnsResolver;
use App\Policies\CommentPolicy;
use App\Policies\CommunityPolicy;
use App\Policies\PostPolicy;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Implementazione reale della risoluzione DNS usata dalla protezione
        // SSRF: nei test viene sostituita con un doppio che non richiede una
        // rete o domini realmente registrati (vedi Tests\TestCase).
        $this->app->bind(DnsResolver::class, SystemDnsResolver::class);
        $this->app->bind(BrowserPushGateway::class, WebPushGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('app.env') === 'production') {
            URL::forceScheme('https');
        }

        // Alias stabili per le relazioni polimorfiche (likeable, mentionable,
        // notifiable): svincolano lo schema dal namespace PHP dei modelli,
        // che potra' cambiare senza richiedere una migration dei dati.
        Relation::enforceMorphMap([
            'post' => Post::class,
            'comment' => Comment::class,
            'follow' => Follow::class,
            'notification' => Notification::class,
            'report' => Report::class,
            'domain_block' => DomainBlock::class,
            'audit_log' => AuditLog::class,
            'user' => User::class,
            'actor' => Actor::class,
            'community' => Community::class,
        ]);

        // Registrazione esplicita: i modelli non vivono in App\Models, quindi
        // la convenzione di auto-discovery delle policy di Laravel non li
        // troverebbe automaticamente.
        Gate::policy(Post::class, PostPolicy::class);
        Gate::policy(Comment::class, CommentPolicy::class);
        Gate::policy(Community::class, CommunityPolicy::class);

        Gate::define('accessAdminPanel', fn ($user) => $user->isStaff());
        Gate::define('moderate', fn ($user) => $user->canModerate());
        Gate::define('administer', fn ($user) => $user->canAdminister());

        if (config('openbook.installed')) {
            try {
                $this->app->make(InstanceSettings::class)->applyToRuntimeConfig();
            } catch (\Throwable) {
                // DB non ancora disponibile (installazione / migrazioni).
            }
        }

        $this->registerViewComposers();
    }

    /**
     * I dati del layout autenticato (badge notifiche, suggerimenti nella
     * colonna destra) sono calcolati qui invece che in ogni controller: le
     * sidebar compaiono in tutte le pagine dietro login, quindi centralizzare
     * la query evita di doverla ripetere ovunque venga aggiunta una nuova vista.
     */
    private function registerViewComposers(): void
    {
        View::composer('layouts.app', function ($view): void {
            if (! auth()->check()) {
                $view->with([
                    'unreadNotificationsCount' => 0,
                    'unreadMessagesCount' => 0,
                    'headerNotifications' => collect(),
                    'modalComposerCommunities' => collect(),
                    'isHomeFeed' => false,
                ]);

                return;
            }

            $actorId = auth()->user()->actor?->id;
            $viewerActor = auth()->user()->actor;

            $view->with([
                'unreadNotificationsCount' => Notification::query()
                    ->where('recipient_id', auth()->id())
                    ->whereNull('read_at')
                    ->count(),
                'unreadMessagesCount' => $viewerActor !== null
                    ? app(ConversationReadTracker::class)->unreadCountFor($viewerActor)
                    : 0,
                // Anteprima per il dropdown della navbar: la pagina completa
                // resta raggiungibile dalla sidebar sinistra.
                'headerNotifications' => Notification::query()
                    ->where('recipient_id', auth()->id())
                    ->with(['actor.user.profile', 'notifiable'])
                    ->orderByDesc('created_at')
                    ->limit(8)
                    ->get(),
                // Community iscrivibili nel composer del dialog "+".
                'modalComposerCommunities' => $actorId === null
                    ? collect()
                    : Community::query()
                        ->with('actor')
                        ->whereIn('actor_id', Follow::query()
                            ->select('following_id')
                            ->where('follower_id', $actorId)
                            ->where('status', Follow::STATUS_ACCEPTED))
                        ->orderBy('slug')
                        ->get(),
                'isHomeFeed' => request()->routeIs('feed.index'),
            ]);
        });

        View::composer('partials.sidebar-right', function ($view): void {
            $viewerActor = auth()->user()?->actor;
            $contextTerm = SidebarSuggestionContext::bioSearchTerm();

            if ($viewerActor !== null && $contextTerm !== null) {
                $suggestions = app(SuggestedActorsByBioQuery::class)->forViewer($viewerActor, $contextTerm);

                if ($suggestions->isEmpty()) {
                    $suggestions = app(SuggestedLocalActorsQuery::class)->forViewer($viewerActor);
                }
            } elseif ($viewerActor !== null) {
                $suggestions = app(SuggestedLocalActorsQuery::class)->forViewer($viewerActor);
            } else {
                $suggestions = collect();
            }

            $trending = app(PopularHashtagsQuery::class)->top(PopularHashtagsQuery::SIDEBAR_LIMIT + 1);

            $view->with([
                'suggestedActors' => $suggestions,
                'popularHashtags' => $trending->take(PopularHashtagsQuery::SIDEBAR_LIMIT),
                'popularHashtagsHasMore' => $trending->count() > PopularHashtagsQuery::SIDEBAR_LIMIT,
            ]);
        });
    }
}
