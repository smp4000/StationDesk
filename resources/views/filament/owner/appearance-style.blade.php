@php($palette = app(\App\Settings\OwnerAppearance::class)->current())
<style>
    :root { --sd-sidebar: {{ $palette['sidebar'] }}; --sd-nav: {{ $palette['nav'] }}; --sd-active: {{ $palette['active'] }}; --sd-active-nav: {{ $palette['active_nav'] ?? $palette['nav'] }}; --sd-primary: {{ $palette['primary'] }}; --sd-accent: {{ $palette['accent'] }}; --sd-canvas: {{ $palette['canvas'] }}; --sd-soft: {{ $palette['soft'] }}; --sd-ink: {{ $palette['ink'] }}; --sd-muted: {{ $palette['muted'] ?? '#60726d' }}; --sd-line: {{ $palette['line'] ?? '#dce4df' }}; }
    /* Markenfarben für Schaltflächen direkt setzen: Filaments generierte Abstufungen verändern sonst den Originalton. */
    .fi-btn.fi-color-primary:not(.fi-outlined) { background-color: {{ $palette['primary'] }}; color: {{ $palette['button_text'] ?? '#ffffff' }}; }
    .fi-btn.fi-color-primary:not(.fi-outlined) > .fi-icon { color: inherit; }
    .fi-btn.fi-color-primary:not(.fi-outlined):not([disabled]):not(.fi-disabled):hover { background-color: {{ $palette['hover'] ?? $palette['active'] }}; color: {{ $palette['hover_text'] ?? '#ffffff' }}; }
</style>
