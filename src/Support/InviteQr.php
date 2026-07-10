<?php

namespace Spdotdev\Inventory\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Renders a household invite link as an inline SVG QR code for the web UI.
 * SVG back end on purpose: no imagick/GD requirement on the host, scales
 * crisply, and inlines into Blade without a file round-trip. The Android app
 * renders its own QR client-side (zxing) from the same link.
 */
class InviteQr
{
    public static function svg(string $link, int $size = 200): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($size),
            new SvgImageBackEnd,
        );

        $svg = (new Writer($renderer))->writeString($link);

        // Strip the XML declaration — this is embedded in an HTML document.
        return (string) preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg);
    }
}
