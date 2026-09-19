@props(['caption', 'rows', 'columns'])

{{-- sr-only sits on a wrapper: on the table itself it clips the cells but not the caption, which renders visibly. --}}
<div class="sr-only">
    <table>
        <caption>{{ $caption }}</caption>
        <thead>
            <tr>
                @foreach ($columns as $label)
                    <th scope="col">{{ $label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    @foreach (array_keys($columns) as $field)
                        <td>{{ $row[$field] ?? '—' }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
