@props(['samples' => null, 'span' => null, 'minSpan', 'minSamples'])

{{-- A young history, not a failed one: it names what has been gathered so far against what the projection needs, so
     the reader can see it is progressing rather than stuck. --}}
<span class="text-gray-500">{{ __('Still collecting') }}</span><br>
<span class="text-xs text-gray-500">
    @if ($samples !== null && $span !== null)
        {{ __(':samples samples over :span days; needs :minSamples over :minSpan days', ['samples' => $samples, 'span' => number_format($span, 1), 'minSamples' => $minSamples, 'minSpan' => $minSpan]) }}
    @else
        {{ __('How much history has been collected is unknown; a trend needs :minSamples samples over :minSpan days', ['minSamples' => $minSamples, 'minSpan' => $minSpan]) }}
    @endif
</span>
