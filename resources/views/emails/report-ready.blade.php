<x-mail::message>
# Din rapport är klar

Vi har analyserat **{{ $address ?? 'din valda plats' }}**.

@if($score)
**Områdespoäng: {{ round($score) }}/100**
@endif

<x-mail::button :url="$url">
Visa rapport
</x-mail::button>

Spara den här länken — den fungerar för alltid.

{{ $url }}

*{{ config('app.name') }}*
</x-mail::message>
