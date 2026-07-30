<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    @php
        $raw = $getRecord()->raw_diagnostics;
        $data = is_string($raw) ? json_decode($raw, true) : $raw;
        $data = is_array($data) ? $data : [];

        $scoredLines = is_array($data['scored_lines'] ?? null) ? $data['scored_lines'] : [];
        $engine = (string) ($data['engine'] ?? 'unknown');
        $pageW = round((float) ($data['page_width'] ?? 0));
        $pageH = round((float) ($data['page_height'] ?? 0));
        $boxCount = count($scoredLines);
    @endphp

    <style>
        .ocr-diag {
            --ocr-border: #e5e7eb;
            --ocr-head-bg: #f9fafb;
            --ocr-summary: #4b5563;
            --ocr-strong: #111827;
            --ocr-hint: #2563eb;
            --ocr-text: #374151;
            --ocr-conf-high: #15803d;
            --ocr-conf-mid: #b45309;
            --ocr-conf-low: #b91c1c;
        }
        .dark .ocr-diag {
            --ocr-border: #374151;
            --ocr-head-bg: #1f2937;
            --ocr-summary: #9ca3af;
            --ocr-strong: #f9fafb;
            --ocr-hint: #60a5fa;
            --ocr-text: #d1d5db;
            --ocr-conf-high: #4ade80;
            --ocr-conf-mid: #fbbf24;
            --ocr-conf-low: #f87171;
        }
        .ocr-diag summary {
            cursor: pointer;
            user-select: none;
            color: var(--ocr-summary);
            padding: 4px 0;
        }
        .ocr-diag summary strong { color: var(--ocr-strong); }
        .ocr-diag summary .ocr-hint { color: var(--ocr-hint); }
        .ocr-diag table {
            border-collapse: collapse;
            width: 100%;
            color: var(--ocr-text);
        }
        .ocr-diag th {
            padding: 4px 8px;
            border: 1px solid var(--ocr-border);
            background: var(--ocr-head-bg);
            color: var(--ocr-strong);
            font-size: 0.7rem;
            text-align: right;
            white-space: nowrap;
        }
        .ocr-diag th:first-child { text-align: left; width: 100%; }
        .ocr-diag td {
            padding: 3px 8px;
            border: 1px solid var(--ocr-border);
            font-size: 0.72rem;
            text-align: right;
            white-space: nowrap;
        }
        .ocr-diag td:first-child {
            font-family: monospace;
            text-align: left;
            white-space: normal;
        }
        .ocr-diag .ocr-conf-high { color: var(--ocr-conf-high); font-weight: 700; }
        .ocr-diag .ocr-conf-mid { color: var(--ocr-conf-mid); font-weight: 700; }
        .ocr-diag .ocr-conf-low { color: var(--ocr-conf-low); font-weight: 700; }
    </style>

    <details class="ocr-diag" style="font-size:0.8rem;width:100%">
        <summary>
            Engine: <strong>{{ $engine }}</strong>
            &nbsp;&middot;&nbsp; {{ $pageW }}&times;{{ $pageH }}px
            &nbsp;&middot;&nbsp; {{ $boxCount }} OCR boxes
            @if ($boxCount > 0)
                &mdash; <span class="ocr-hint">click to expand</span>
            @endif
        </summary>

        @if ($boxCount > 0)
            <div style="overflow-x:auto;margin-top:8px;width:100%">
                <table>
                    <thead>
                        <tr>
                            <th>Text</th>
                            <th>Conf.</th>
                            <th>Chars</th>
                            <th>X (px)</th>
                            <th>H (px)</th>
                            <th>Y (px)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($scoredLines as $line)
                            @continue(! is_array($line))
                            @php
                                $conf = round((float) ($line['confidence'] ?? 0) * 100, 1);
                                $confClass = $conf >= 90 ? 'ocr-conf-high' : ($conf >= 60 ? 'ocr-conf-mid' : 'ocr-conf-low');
                                $chars = (int) ($line['char_count'] ?? mb_strlen((string) ($line['text'] ?? '')));
                            @endphp
                            <tr>
                                <td>{{ mb_strimwidth((string) ($line['text'] ?? ''), 0, 160, '…') }}</td>
                                <td class="{{ $confClass }}">{{ $conf }}%</td>
                                <td>{{ $chars }}</td>
                                <td>{{ round((float) ($line['x_start'] ?? 0)) }}&ndash;{{ round((float) ($line['x_end'] ?? 0)) }}</td>
                                <td>{{ round((float) ($line['height'] ?? 0)) }}</td>
                                <td>{{ round((float) ($line['y'] ?? 0)) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </details>
</x-dynamic-component>
