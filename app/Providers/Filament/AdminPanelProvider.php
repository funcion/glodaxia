<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->profile(\App\Filament\Pages\Auth\EditProfile::class, isSimple: false)
            ->colors([
                'primary' => Color::hex('#0072ff'),
            ])
            ->brandLogo(fn () => asset('images/Glodaxia.png'))
            ->brandLogoHeight('2.2rem')
            ->favicon(asset('images/Glodaxia-sm.png'))
            ->navigationItems([
                NavigationItem::make('Ver Sitio Web')
                    ->url('/', shouldOpenInNewTab: true)
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->sort(-1),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                \App\Filament\Pages\Dashboard::class,
                \App\Filament\Pages\SettingsPage::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                Widgets\FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->renderHook(
                \Filament\View\PanelsRenderHook::HEAD_END,
                fn () => new \Illuminate\Support\HtmlString('
                    <style>
                        @keyframes glodaxia-spin-ring {
                            0% { transform: rotate(0deg); }
                            100% { transform: rotate(360deg); }
                        }
                        /* Hide original icon and filament indicator when background processing */
                        .is-processing-bg {
                            position: relative !important;
                            pointer-events: none !important;
                            cursor: not-allowed !important;
                            opacity: 0.9 !important;
                        }
                        .is-processing-bg svg,
                        .is-processing-bg .fi-icon-btn-icon,
                        .is-processing-bg .fi-btn-icon,
                        .is-processing-bg .fi-icon-btn-loading-indicator {
                            display: none !important;
                        }
                        /* Render pure circular dynamic spinner */
                        .is-processing-bg::after {
                            content: "" !important;
                            display: block !important;
                            width: 1.15rem !important;
                            height: 1.15rem !important;
                            border: 2.5px solid rgba(245, 158, 11, 0.25) !important;
                            border-top-color: #f59e0b !important;
                            border-radius: 50% !important;
                            animation: glodaxia-spin-ring 0.75s linear infinite !important;
                            margin: auto !important;
                        }
                    </style>
                ')
            )
            ->plugins([
                \BezhanSalleh\FilamentShield\FilamentShieldPlugin::make()->navigationGroup('Seguridad y Roles'),
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}