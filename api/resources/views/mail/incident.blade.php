@if ($kind === 'incident_opened')
Upfixy found a problem with {{ $host }} that has lasted three checks in a row.

{{ $title }}
{{ $meaning }}

First seen: {{ $when }} (IST)
@else
{{ $host }} looks fine again: two checks in a row passed.

The problem was: {{ $title }}
Recovered: {{ $when }} (IST)
@endif

Details, plain-language help and a message you can send to support:
{{ $link }}

You get this because you look after this website in Upfixy. Change alert settings in Settings.
