<?php

namespace App\Modules\Licenses\Services;

use App\Models\License;
use App\Models\User;
use App\Modules\Licenses\Support\DigitalLicensePresenter;
use App\Modules\Licenses\Support\LicensePortraitResolver;
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
        private readonly LicensePortraitResolver $portraits,
    ) {}

    /**
     * @return array{binary: string, filename: string, license: License}
     */
    public function printPdf(User $actor, License $license): array
    {
        $binary = $this->renderCredentialPdf($license);
        $updated = $this->licenses->recordPrint($actor, $license);

        return [
            'binary' => $binary,
            'filename' => 'license-'.$this->safeFilename((string) $license->license_number).'.pdf',
            'license' => $updated,
        ];
    }

    /**
     * Citizen download: same credential renderer, no employee print metadata.
     *
     * @return array{binary: string, filename: string, license: License}
     */
    public function downloadPdf(User $citizen, License $license): array
    {
        $binary = $this->renderCredentialPdf($license);

        $this->lifecycle->recordAudit(
            $citizen,
            'license.downloaded',
            $license,
            null,
            ['source' => 'citizen']
        );

        return [
            'binary' => $binary,
            'filename' => 'SYRTAK-License-'.$this->safeFilename((string) $license->license_number).'.pdf',
            'license' => $license,
        ];
    }

    public function renderCredentialPdf(License $license): string
    {
        $this->lifecycle->ensureVerificationToken($license);
        $license->refresh();

        $payload = DigitalLicensePresenter::payload($license);
        $verificationUrl = (string) ($payload['verification_url'] ?? '');
        $qrPng = $verificationUrl !== '' ? $this->qrPngDataUri($verificationUrl) : '';

        try {
            return $this->renderPdf($payload, $qrPng, $license);
        } catch (Throwable $e) {
            report($e);
            throw new \App\Exceptions\ApiException('messages.licenses.print_failed', 500);
        }
    }

    public function qrPngDataUri(string $verificationUrl): string
    {
        $result = (new Builder(
            writer: new PngWriter(),
            writerOptions: [],
            data: $verificationUrl,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 280,
            margin: 12,
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
            size: 280,
            margin: 12,
        ))->build();

        return $result->getString();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderPdf(array $payload, string $qrDataUri, License $license): string
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
            'format' => [85.60, 53.98],
            'margin_left' => 0,
            'margin_right' => 0,
            'margin_top' => 0,
            'margin_bottom' => 0,
            'default_font' => 'dejavusans',
            'fontDir' => $fontDirs,
            'fontdata' => $fontData,
            'tempDir' => $tempDir,
            'directionality' => 'rtl',
        ]);

        $mpdf->SetDirectionality('rtl');
        $mpdf->autoScriptToLang = true;
        $mpdf->autoLangToFont = true;

        $mpdf->WriteHTML(view('licenses.digital-card', [
            'payload' => $payload,
            'qr' => $qrDataUri,
            'logo' => $this->logoPath(),
            'portrait' => $this->portraitPath($license),
            'generated_at' => app(BusinessClock::class)->now()->format('Y-m-d H:i'),
        ])->render());

        return $mpdf->Output('', 'S');
    }

    private function logoPath(): ?string
    {
        $path = public_path('branding/syrtak-license-logo.png');

        return is_file($path) ? $path : null;
    }

    private function portraitPath(License $license): ?string
    {
        $resolved = $this->portraits->resolve($license);

        return $resolved['path'] ?? null;
    }

    private function safeFilename(string $licenseNumber): string
    {
        $safe = preg_replace('/[^\w.\-]+/', '-', $licenseNumber) ?: 'license';
        $safe = trim($safe, '-');

        return $safe !== '' ? $safe : 'license';
    }
}
