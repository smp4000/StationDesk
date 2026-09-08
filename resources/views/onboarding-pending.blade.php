<!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">@if ($status !== 'failed')<meta http-equiv="refresh" content="10">@endif<title>Dein Arbeitsplatz · StationDeck</title>@vite('resources/css/app.css')</head>
<body class="sd-public"><main class="sd-welcome"><a class="sd-wordmark" href="/">Station<span>Deck</span></a><p class="sd-eyebrow">DEIN START</p>
@if ($status === 'failed')
<h1>Die Einrichtung<br>braucht Unterstützung.</h1><p>Dein Konto bleibt erhalten. Die Plattformverwaltung kann die Einrichtung prüfen und erneut starten. Dein Testzeitraum hat noch nicht begonnen.</p>
@else
<h1>Dein Arbeitsplatz<br>wird vorbereitet.</h1><p>{{ $status === 'provisioning' ? 'Deine Tankstelle und dein Chef-Zugang werden gerade eingerichtet.' : 'Deine E-Mail ist bestätigt. Die Einrichtung wartet auf die Verarbeitung.' }} Dein Testzeitraum beginnt erst, wenn deine Tankstelle bereitsteht. Diese Seite aktualisiert sich automatisch.</p>
@endif
<a class="sd-primary-link" href="{{ url('/owner') }}">Status aktualisieren</a>
<form method="post" action="{{ route('filament.owner.auth.logout') }}">@csrf<button type="submit" class="sd-primary-link">Abmelden</button></form>
</main></body></html>
