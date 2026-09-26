<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Draws the initials avatar shown for members without a profile photo, so no third-party avatar service sees who is
 * browsing. The image depends only on the initials in the URL, which lets browsers and Cloudflare cache it for a year.
 */
final class DefaultAvatarController extends Controller
{
    /**
     * Initials that render as a blank avatar, for names with no letters or digits.
     */
    public const string BLANK = '-';

    public function __invoke(string $initials): Response
    {
        abort_unless(preg_match('/^(?:[\p{L}\p{N}]{1,2}|-)$/Du', $initials) === 1, 404);

        $text = $initials === self::BLANK ? '' : htmlspecialchars($initials, ENT_XML1);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" fill="#EBF4FF"/>'
            .'<text x="32" y="32" dy=".35em" text-anchor="middle" fill="#7F9CF5" font-size="28" '
            .'font-family="ui-sans-serif, system-ui, sans-serif">'.$text.'</text>'
            .'</svg>';

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}
