{{--
    Vendored copy of Scribe's default theme layout (knuckleswtf/scribe v5.11.x). The only change from the package original
    is that nothing loads from a third party: the Google Fonts stylesheet is dropped (the theme falls back to system
    fonts) and lodash, highlight.js and jets come from public/vendor/scribe, which the Vite build copies out of
    node_modules. When upgrading Scribe, re-sync this file against the package's resources/views/themes/default/index.blade.php.
--}}
@php
    use Knuckles\Scribe\Tools\WritingUtils as u;
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta content="IE=edge,chrome=1" http-equiv="X-UA-Compatible">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>{!! $metadata['title'] !!}</title>

    <link rel="stylesheet" href="{!! $assetPathPrefix !!}css/theme-default.style.css" media="screen">
    <link rel="stylesheet" href="{!! $assetPathPrefix !!}css/theme-default.print.css" media="print">

    <script src="/vendor/scribe/lodash.min.js"></script>

    <link rel="stylesheet" href="/vendor/scribe/obsidian.min.css">
    <script src="/vendor/scribe/highlight.min.js"></script>

    <script src="/vendor/scribe/jets.min.js"></script>

@if(isset($metadata['example_languages']))
    <style id="language-style">
        /* starts out as display none and is replaced with js later  */
        @foreach($metadata['example_languages'] as $lang)
            body .content .{{ $lang }}-example code { display: none; }
        @endforeach
    </style>
@endif

@if($tryItOut['enabled'] ?? true)
    <script>
        var tryItOutBaseUrl = "{!! $tryItOut['base_url'] ?? $baseUrl !!}";
        var useCsrf = Boolean({!! $tryItOut['use_csrf'] ?? null !!});
        var csrfUrl = "{!! $tryItOut['csrf_url'] ?? null !!}";
    </script>
    <script src="{{ u::getVersionedAsset($assetPathPrefix.'js/tryitout.js') }}"></script>
@endif

    <script src="{{ u::getVersionedAsset($assetPathPrefix.'js/theme-default.js') }}"></script>

</head>

<body data-languages="{{ json_encode($metadata['example_languages'] ?? []) }}">

@include("scribe::themes.default.sidebar")

<div class="page-wrapper">
    <div class="dark-box"></div>
    <div class="content">
        {!! $intro !!}

        {!! $auth !!}

        @include("scribe::themes.default.groups")

        {!! $append !!}
    </div>
    <div class="dark-box">
        @if(isset($metadata['example_languages']))
            <div class="lang-selector">
                @foreach($metadata['example_languages'] as $name => $lang)
                    @php if (is_numeric($name)) $name = $lang; @endphp
                    <button type="button" class="lang-button" data-language-name="{{$lang}}">{{$name}}</button>
                @endforeach
            </div>
        @endif
    </div>
</div>
</body>
</html>
