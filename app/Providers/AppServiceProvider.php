<?php

namespace App\Providers;

use App\Application\Queries\PopularHashtagsQuery;
use App\Application\Services\InstanceSettings;
use App\Domain\Comments\Comment;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Infrastructure\Security\Http\DnsResolver;
use App\Infrastructure\Security\Http\SystemDnsResolver;
use App\Policies\CommentPolicy;
use App\Policies\PostPolicy;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(Registered::class, SendEmailVerificationNotification::class);

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
        ]);

        // Registrazione esplicita: i modelli non vivono in App\Models, quindi
        // la convenzione di auto-discovery delle policy di Laravel non li
        // troverebbe automaticamente.
        Gate::policy(Post::class, PostPolicy::class);
        Gate::policy(Comment::class, CommentPolicy::class);

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
                    'headerNotifications' => collect(),
                ]);

                return;
            }

            $view->with([
                'unreadNotificationsCount' => Notification::query()
                    ->where('recipient_id', auth()->id())
                    ->whereNull('read_at')
                    ->count(),
                // Anteprima per il dropdown della navbar: la pagina completa
                // resta raggiungibile dalla sidebar sinistra.
                'headerNotifications' => Notification::query()
                    ->where('recipient_id', auth()->id())
                    ->with('actor.user.profile')
                    ->orderByDesc('created_at')
                    ->limit(8)
                    ->get(),
            ]);
        });

        View::composer('partials.sidebar-right', function ($view): void {
            $viewerActor = auth()->user()?->actor;
            $suggestions = collect();

            if ($viewerActor !== null) {
                $excludedIds = Follow::query()
                    ->where('follower_id', $viewerActor->id)
                    ->pluck('following_id')
                    ->push($viewerActor->id);

                $suggestions = Actor::query()
                    ->where('is_local', true)
                    ->where('type', Actor::TYPE_PERSON)
                    ->where('status', Actor::STATUS_ACTIVE)
                    ->whereNotIn('id', $excludedIds)
                    ->whereHas('user.settings', fn ($query) => $query->where('discoverable', true))
                    ->with('user.profile')
                    ->latest('created_at')
                    ->limit(5)
                    ->get();
            }

            $view->with([
                'suggestedActors' => $suggestions,
                'popularHashtags' => app(PopularHashtagsQuery::class)->top(),
            ]);
        });
    }
}
