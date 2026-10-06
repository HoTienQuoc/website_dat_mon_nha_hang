<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvExport
{
    public function download(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            foreach ((function () use ($headers, $rows) {
                yield $headers;
                yield from $rows;
            })() as $row) {
                $safe = array_map(fn ($value) => is_string($value) && preg_match('/^[\s]*[=+@-]|^[\t\r\n]/u', $value) ? "'".$value : $value, $row);
                fputcsv($stream, $safe, ',', '"', '');
            }
            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
