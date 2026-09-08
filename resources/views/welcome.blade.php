<!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>StationDeck · Dein Betrieb. Dein Überblick.</title>@vite('resources/css/app.css')</head>
<body class="sd-public"><header class="sd-public-header"><a class="sd-wordmark" href="/">Station<span>Deck</span></a><a href="{{ route('filament.owner.auth.login') }}">Anmelden ↗</a></header><main class="sd-welcome">
<p class="sd-eyebrow">FÜR DEINEN TANKSTELLENALLTAG</p><h1>Mehr Überblick.<br><span>Raum fürs Wesentliche.</span></h1><p>Ein gemeinsamer Arbeitsplatz für deine Tankstellen und dein Team.</p><a class="sd-primary-link" href="{{ route('filament.owner.auth.login') }}">Zu deinem Arbeitsplatz <span aria-hidden="true">↗</span></a>
@if (\App\Onboarding\RegisterOwner::available())
<div class="sd-public-note">Lokaler Testbetrieb · <a href="{{ route('filament.owner.auth.register') }}">Chef-Zugang und erste Tankstelle registrieren →</a></div>
@else
<div class="sd-public-note">StationDeck befindet sich im Aufbau. Die öffentliche Registrierung ist noch nicht geöffnet.</div>
@endif
</main><footer class="sd-public-footer"><span>StationDeck · Gemeinsam den Betrieb im Blick.</span><a href="{{ route('filament.admin.auth.login') }}">Plattformverwaltung</a></footer></body></html>
