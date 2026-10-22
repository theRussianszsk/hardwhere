<x-mail::message>
# Shoppers are looking for {{ $alert->subject_label }}

Hi {{ $prospect->contact_name ?? $prospect->name }},

In the last {{ $alert->window_hours }} hours, **{{ $alert->searcher_count }} shoppers** searched HardWhere for **{{ $alert->subject_label }}**.
@if ($alert->unserved)
None of them found a store on HardWhere that carries it.
@endif

{{ $prospect->name }} could be the store they find. Listing on HardWhere is how nearby customers see your stock and prices.

<x-mail::button :url="$signupUrl">
List {{ $prospect->name }} on HardWhere
</x-mail::button>

<x-mail::subcopy>
You're receiving this because {{ $prospect->name }} sells {{ $alert->category?->name ?? 'hardware' }}. Reply with "unsubscribe" and we won't email you again.
</x-mail::subcopy>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
