<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;

/**
 * Builds real UploadedFile instances (test mode) so Fileinfo-backed getMimeType()
 * reflects file content — unlike UploadedFile::fake() which derives MIME from the filename.
 */
final class FakeDocumentFile
{
    public static function pdf(string $name = 'document.pdf', int $minBytes = 256): UploadedFile
    {
        $content = "%PDF-1.4\n"
            ."1 0 obj<<>>endobj\n"
            ."trailer<<>>\n"
            ."%%EOF\n";

        if (strlen($content) < $minBytes) {
            $content .= str_repeat('0', $minBytes - strlen($content));
        }

        return self::fromContent($name, $content);
    }

    public static function jpeg(string $name = 'document.jpg'): UploadedFile
    {
        $content = hex2bin(
            'ffd8ffe000104a46494600010100000100010000'
            .'ffdb004300080606070605080707070909080a0c'
            .'140d0c0b0b0c1912130f141d1a1f1e1d1a1c1c20'
            .'242e2720222c231c1c2837292c30313434341f27'
            .'393d38323c2e333432'
            .'ffd9'
        );

        return self::fromContent($name, $content === false ? "\xFF\xD8\xFF\xD9" : $content);
    }

    public static function png(string $name = 'document.png'): UploadedFile
    {
        $content = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO5W5W0AAAAASUVORK5CYII=',
            true
        );

        return self::fromContent($name, $content === false ? '' : $content);
    }

    public static function textDisguisedAsPdf(string $name = 'document.pdf'): UploadedFile
    {
        return self::fromContent($name, "This is plain text, not a PDF.\n");
    }

    public static function textDisguisedAsJpeg(string $name = 'document.jpg'): UploadedFile
    {
        return self::fromContent($name, "Not a JPEG image payload.\n");
    }

    public static function phpDisguisedAsPdf(string $name = 'file.php.pdf'): UploadedFile
    {
        return self::fromContent($name, "<?php echo 'malware';\n");
    }

    public static function emptyPdf(string $name = 'empty.pdf'): UploadedFile
    {
        return self::fromContent($name, '');
    }

    public static function oversizedPdf(string $name = 'huge.pdf', int $kilobytes = 4200): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'dlms_doc_');

        if ($path === false) {
            throw new \RuntimeException('Unable to create temporary file for document fixture.');
        }

        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new \RuntimeException('Unable to write oversized document fixture.');
        }

        $prefix = "%PDF-1.4\n%%EOF\n";
        fwrite($handle, $prefix);

        $remaining = max(0, ($kilobytes * 1024) - strlen($prefix));
        $chunk = str_repeat('A', 8192);

        while ($remaining > 0) {
            $write = min(8192, $remaining);
            fwrite($handle, $write === 8192 ? $chunk : str_repeat('A', $write));
            $remaining -= $write;
        }

        fclose($handle);

        // Size-rejection tests must not invoke Fileinfo on multi-MB payloads.
        return new class($path, $name) extends UploadedFile
        {
            public function __construct(string $path, string $originalName)
            {
                parent::__construct($path, $originalName, 'application/pdf', null, true);
            }

            public function getMimeType(): string
            {
                return 'application/pdf';
            }
        };
    }

    private static function fromContent(string $originalName, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'dlms_doc_');

        if ($path === false) {
            throw new \RuntimeException('Unable to create temporary file for document fixture.');
        }

        file_put_contents($path, $content);

        // mimeType=null forces Symfony/Laravel to guess via Fileinfo from content.
        return new UploadedFile($path, $originalName, null, null, true);
    }
}
