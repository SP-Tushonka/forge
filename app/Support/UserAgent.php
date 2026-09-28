<?php

declare(strict_types=1);

namespace App\Support;

use Detection\MobileDetect;

final class UserAgent
{
    /**
     * @var array<string, string>
     */
    private const array OPERATING_SYSTEMS = [
        'Windows' => 'Windows',
        'Windows NT' => 'Windows NT',
        'OS X' => 'Mac OS X',
        'Debian' => 'Debian',
        'Ubuntu' => 'Ubuntu',
        'Macintosh' => 'PPC',
        'OpenBSD' => 'OpenBSD',
        'Linux' => 'Linux',
        'ChromeOS' => 'CrOS',
    ];

    /**
     * @var array<string, string>
     */
    private const array BROWSERS = [
        'Opera Mini' => 'Opera Mini',
        'Opera' => 'Opera|OPR',
        'Edge' => 'Edge|Edg',
        'Coc Coc' => 'coc_coc_browser',
        'UCBrowser' => 'UCBrowser',
        'Vivaldi' => 'Vivaldi',
        'Chrome' => 'Chrome',
        'Firefox' => 'Firefox',
        'Safari' => 'Safari',
        'IE' => 'MSIE|IEMobile|MSIEMobile|Trident/[.0-9]+',
        'Netscape' => 'Netscape',
        'Mozilla' => 'Mozilla',
        'WeChat' => 'MicroMessenger',
    ];

    /**
     * @return array{browser: string|null, platform: string|null, device_type: string}
     */
    public static function parse(string $userAgent): array
    {
        $detector = new MobileDetect;
        $detector->setUserAgent($userAgent);

        /** @var array<string, string> $platforms */
        $platforms = array_merge(MobileDetect::getOperatingSystems(), self::OPERATING_SYSTEMS);
        /** @var array<string, string> $browsers */
        $browsers = self::BROWSERS + MobileDetect::getBrowsers();

        return [
            'browser' => self::match($userAgent, $browsers),
            'platform' => self::match($userAgent, $platforms),
            'device_type' => match (true) {
                $detector->isTablet() => 'tablet',
                $detector->isMobile() => 'mobile',
                default => 'desktop',
            },
        ];
    }

    /**
     * @param  array<string, string>  $rules
     */
    private static function match(string $userAgent, array $rules): ?string
    {
        if ($userAgent === '') {
            return null;
        }

        foreach ($rules as $name => $regex) {
            if ($regex !== '' && preg_match('/'.str_replace('/', '\\/', $regex).'/i', $userAgent)) {
                return $name;
            }
        }

        return null;
    }
}
