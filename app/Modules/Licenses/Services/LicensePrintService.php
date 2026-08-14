<?php

namespace App\Modules\Licenses\Services;

use App\Models\License;
use App\Modules\Licenses\Support\DigitalLicensePresenter;
use App\Support\BusinessClock;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Throwable;

class LicensePrintService
{
    public function __construct(
        private readonly LicenseLifecycleService $lifecycle,
        private readonly LicenseService $licenses,
    ) {}

    /**
     * @return array{binary: string, filename: string, license: License}
     */
    public function printPdf(\App\Models\User $actor, License $license): array
    {
        $this->lifecycle->ensureVerificationToken($license);
        $license->refresh();

        $payload = DigitalLicensePresenter::payload($license);
        $qrPng = $this->qrPngDataUri((string) DigitalLicensePresenter::verificationPublicUrl($license));

        try {
            $binary = $this->renderPdf($payload, $qrPng);
        } catch (Throwable $e) {
            report($e);
            throw new \App\Exceptions\ApiException('messages.licenses.print_failed', 500);
        }

        $updated = $this->licenses->recordPrint($actor, $license);

        return [
            'binary' => $binary,
            'filename' => 'license-'.$license->license_number.'.pdf',
            'license' => $updated,
        ];
    }

    public function qrPngDataUri(string $verificationUrl): string
    {
        $result = (new Builder(
            writer: new PngWriter(),
            writerOptions: [],
            data: $verificationUrl,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 220,
            margin: 8,
        ))->build();

        return 'data:image/png;base64,'.base64_encode($result->getString());
    }

    public function qrSvg(string $verificationUrl): string
    {
        $result = (new Builder(
            writer: new SvgWriter(),
            writerOptions: [],
            data: $verificationUrl,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 220,
            margin: 8,
        ))->build();

        return $result->getString();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderPdf(array $payload, string $qrDataUri): string
    {
        $defaultConfig = (new ConfigVariables())->getDefaults();
        $fontDirs = $defaultConfig['fontDir'];
        $defaultFontConfig = (new FontVariables())->getDefaults();
        $fontData = $defaultFontConfig['fontdata'];

        $tempDir = storage_path('app/mpdf-temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'default_font' => 'dejavusans',
            'fontDir' => $fontDirs,
            'fontdata' => $fontData,
            'tempDir' => $tempDir,
            'directionality' => 'rtl',
        ]);

        $mpdf->SetDirectionality('rtl');
        $mpdf->autoScriptToLang = true;
        $mpdf->autoLangToFont = true;

        $html = view('licenses.digital-card', [
            'payload' => $payload,
            'qr' => $qrDataUri,
            'generated_at' => app(BusinessClock::class)->now()->format('Y-m-d H:i'),
        ])->render();

        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }
}
