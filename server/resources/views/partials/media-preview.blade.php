@php($src = $item->mediaUrl())
<div class="preview">
    @if($item->type === 'video')
        <video src="{{ $src }}"
               muted
               playsinline
               preload="auto"
               data-preview
               data-start-at="{{ $startAt }}"
               data-duration-ms="{{ (int) $item->duration_ms }}"></video>
        <span class="preview-wait" hidden></span>
    @else
        <img src="{{ $src }}" alt="{{ $item->type }}">
    @endif
</div>
