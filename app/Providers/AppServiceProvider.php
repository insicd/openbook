<?php

namespace App\Providers;

use App\Domain\Accounts\User;
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
            $view->with('unreadNotificationsCount', auth()->check()
                ? Notification::query()
                    ->where('recipient_id', auth()->id())
                    ->whereNull('read_at')
                    ->count()
                : 0);
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
                    ->with('user.profile')
                    ->latest('created_at')
                    ->limit(5)
                    ->get();
            }

            $view->with([
                'suggestedActors' => $suggestions,
                'membersCount' => User::query()->count(),
            ]);
        });
    }
}
