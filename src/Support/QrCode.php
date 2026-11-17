<?php

namespace Darvis\Mfa\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Draws an otpauth:// URI as a QR code when bacon/bacon-qr-code is installed
 * (Laravel Fortify brings it). Without it the settings screen shows the key
 * to type in by hand instead.
 */
class QrCode
{
    public static function svg(string $uri): ?string
    {
        if (! class_exists(Writer::class)) {
            return null;
        }

        $svg = (new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd)))->writeString($uri);

        return trim(substr($svg, (int) strpos($svg, "\n") + 1));
    }
}
