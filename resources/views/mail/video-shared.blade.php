<x-mail::message>
# {{ $senderName }} shared a video with you

**{{ $videoTitle }}**

@if($message)
{{ $message }}
@endif

<x-mail::button :url="$watchUrl" color="primary">
Watch Video
</x-mail::button>

<x-mail::subcopy>
If you did not expect this email, you can safely ignore it.
</x-mail::subcopy>

<img src="{{ $pixelUrl }}" width="1" height="1" alt="" style="display:none;" />
</x-mail::message>
