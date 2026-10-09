<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side PDF rendering.
 *
 * Replaces the seventeen window.print() calls in the frontend, which opened
 * the browser print dialog against whatever else happened to be on screen,
 * and the single text/plain download that produced a .txt file.
 *
 * dompdf implements a limited subset of CSS 2.1: no flexbox, no grid, no
 * custom properties. The templates therefore use tables and inline styles
 * only. Each document is rendered server-side so it is stored, reproducible
 * and downloadable without a browser.
 */
class PdfService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function render(string $view, array $data, string $filename, string $orientation = 'portrait'): Response
    {
        $html = View::make("pdf.{$view}", array_merge($data, [
            'generatedAt' => now(),
        ]))->render();

        return $this->stream($html, $filename, $orientation);
    }

    public function stream(string $html, string $filename, string $orientation = 'portrait'): Response
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        // Confine rendering and script execution to the working directory.
        $options->set('chroot', base_path());
        $options->set('enable_php', false);
        $options->set('logOutputFile', storage_path('logs/dompdf.log'));

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('a4', $orientation);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        $output = $dompdf->output();

        return new Response($output, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->safeFilename($filename).'"',
            'Content-Length' => (string) strlen($output),
        ]);
    }

    public function inline(string $html, string $filename, string $orientation = 'portrait'): Response
    {
        $response = $this->stream($html, $filename, $orientation);

        $response->headers->set(
            'Content-Disposition',
            'inline; filename="'.$this->safeFilename($filename).'"'
        );

        return $response;
    }

    /**
     * Strips anything that could break the header or produce an unexpected
     * extension, since the name is built from record references.
     */
    private function safeFilename(string $filename): string
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) ?? 'document';
        $filename = trim($filename, '-.');

        if ($filename === '' || ! str_contains($filename, '.')) {
            $filename .= '.pdf';
        }

        return substr($filename, 0, 120);
    }
}
