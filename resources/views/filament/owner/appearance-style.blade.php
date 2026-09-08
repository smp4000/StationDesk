@php($palette = app(\App\Settings\OwnerAppearance::class)->current())
<style>
    :root { --sd-sidebar: {{ $palette['sidebar'] }}; --sd-nav: {{ $palette['nav'] }}; --sd-active: {{ $palette['active'] }}; --sd-primary: {{ $palette['primary'] }}; --sd-accent: {{ $palette['accent'] }}; --sd-canvas: {{ $palette['canvas'] }}; --sd-soft: {{ $palette['soft'] }}; --sd-ink: {{ $palette['ink'] }}; }
</style>
