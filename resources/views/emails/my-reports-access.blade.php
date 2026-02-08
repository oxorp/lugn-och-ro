<x-mail::message>
# Åtkomst till dina rapporter

Klicka på knappen nedan för att se dina köpta rapporter. Länken gäller i 24 timmar.

<x-mail::button :url="$url">
Visa mina rapporter
</x-mail::button>

Om du inte har begärt den här länken kan du ignorera det här meddelandet.

*{{ config('app.name') }}*
</x-mail::message>
