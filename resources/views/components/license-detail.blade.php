<li class="px-4 py-4 last:pb-0 sm:px-0">
    <h3 class="font-bold">{{ __('License') }}</h3>
    <p class="truncate">
        @if ($license->link !== '')
            <a
                href="{{ $license->link }}"
                title="{{ $license->name }}"
                target="_blank"
                rel="noopener noreferrer"
                class="text-gray-200 underline hover:text-white"
            >
                {{ $license->name }}
            </a>
        @else
            <span class="text-gray-200">{{ $license->name }}</span>
        @endif
    </p>
    @foreach ($customLicenseFiles as $file)
        <p class="truncate">
            <a
                href="{{ $file['url'] }}"
                title="{{ $file['url'] }}"
                target="_blank"
                rel="noopener noreferrer"
                class="text-gray-200 underline hover:text-white"
            >
                {{ $file['label'] }}
            </a>
        </p>
    @endforeach
</li>
