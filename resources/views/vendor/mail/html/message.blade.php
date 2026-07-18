<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{ config('app.name') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} AGAIN<br><br>
Вы получили это письмо, потому что зарегистрировались на сайте AGAIN или запросили это уведомление.<br><br>
Это сообщение отправлено вам от:<br>
AGAIN | {{ config('mail.from.address') }}<br><br>
<a href="mailto:{{ config('mail.from.address') }}?subject={{ rawurlencode('Отписка от рассылки AGAIN') }}">Отписаться от рассылки</a>
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
