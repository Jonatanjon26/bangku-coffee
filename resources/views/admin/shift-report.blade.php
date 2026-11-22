<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('admin.shifts.report_title', ['name' => $shift->name, 'date' => $shift->opened_at->format('d/m/Y')]) }}</title>
    <style>
        body { margin: 0; padding: 24px; background: #f5f5f4; font: 13px/1.35 ui-monospace, 'SFMono-Regular', Menlo, monospace; }
        .paper { width: max-content; margin: 0 auto; padding: 16px; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.15); white-space: pre; }
        .b { font-weight: 700; } .big, .big-c { font-weight: 700; font-size: 1.4em; }
        .actions { text-align: center; margin-bottom: 16px; font-family: system-ui, sans-serif; }
        @media print { body { padding: 0; background: #fff; } .actions { display: none; } .paper { box-shadow: none; padding: 0; } @page { size: 57mm auto; margin: 2mm; } }
    </style>
</head>
<body>
    <div class="actions"><button type="button" onclick="window.print()">{{ __('admin.shifts.print') }}</button></div>
    <div class="paper">@foreach ($doc['lines'] as $line)<div class="{{ $line['style'] ?? '' }}">{{ $line['text'] !== '' ? $line['text'] : ' ' }}</div>@endforeach</div>
</body>
</html>
