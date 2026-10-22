<x-mail::message>
# Demand is building

Hi {{ $recipientName }},

**{{ $alert->searcher_count }} different shoppers** searched for **{{ $alert->subject_label }}** on HardWhere in the last {{ $alert->window_hours }} hours ({{ $alert->search_count }} searches in total).

@if ($alert->unserved)
None of those searches found a store that currently lists it. If {{ $tenantName }} can source it, publishing a listing now puts you first in line.
@elseif ($alreadyListed)
{{ $tenantName }} already lists this. Check your stock and reorder levels so you don't run out while demand is high.
@else
Other stores list it, but {{ $tenantName }} doesn't yet. Consider adding it to your inventory.
@endif

<x-mail::panel>
@if ($alert->category)
Category: {{ $alert->category->name }}<br>
@endif
@if ($alert->brand)
Brand: {{ $alert->brand }}<br>
@endif
Alert raised: {{ $alert->created_at->timezone('Asia/Manila')->format('M j, Y g:i A') }}
</x-mail::panel>

This alert only reports counts. It never includes who searched.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
