<?php

declare(strict_types=1);

namespace App\Web;

/**
 * Renders plain-PHP templates from `templates/` inside the shared layout.
 */
final class Views
{
    public function __construct(private string $dir)
    {
    }

    /**
     * Renders a page template wrapped in `layout.php`.
     *
     * @param string               $template Template name without `.php`.
     * @param array<string,mixed>  $vars     Variables for the template; `title` is used by the layout.
     */
    public function page(string $template, array $vars): string
    {
        $content = $this->partial($template, $vars);

        return $this->partial('layout', $vars + ['content' => $content]);
    }

    /**
     * Renders one template to a string.
     *
     * @param array<string,mixed> $vars
     */
    public function partial(string $template, array $vars): string
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        require "{$this->dir}/$template.php";

        return (string) ob_get_clean();
    }

    /**
     * Bootstrap badge classes for a status tone from {@see Labels}.
     *
     * @param string $tone `ok`, `warn`, `bad` or `wait`.
     */
    public static function badge(string $tone): string
    {
        // "subtle" badges: dark text on a light tint keeps contrast above WCAG AA (4.5:1)
        $c = match ($tone) {
            'ok' => 'success',
            'warn' => 'warning',
            'bad' => 'danger',
            default => 'secondary',
        };

        return "badge border bg-$c-subtle text-$c-emphasis border-$c-subtle";
    }

    /**
     * A badge with the Bootstrap Icon matching its tone, ready to print.
     *
     * @param array{text: string, tone: string} $label
     */
    public static function status(array $label): string
    {
        $icon = match ($label['tone']) {
            'ok' => 'bi-check2-circle',
            'warn' => 'bi-exclamation-circle',
            'bad' => 'bi-exclamation-triangle',
            default => 'bi-clock',
        };

        return '<span class="' . self::badge($label['tone']) . '"><i class="bi ' . $icon . ' me-1"></i>' . self::e($label['text']) . '</span>';
    }

    /**
     * Russian plural form for a count: 1 требует, 2 требуют, 5 требуют, 21 требует.
     *
     * @param string $one  Form for 1, 21, 31…
     * @param string $few  Form for 2–4, 22–24…
     * @param string $many Form for 0, 5–20, 25–30…
     */
    public static function plural(int $n, string $one, string $few, string $many): string
    {
        $mod10 = $n % 10;
        $mod100 = $n % 100;
        if ($mod10 === 1 && $mod100 !== 11) {
            return $one;
        }

        return $mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14) ? $few : $many;
    }

    /**
     * URL of a built asset with a version derived from the file's build time.
     * Static files are served with a one-year cache, so the version is what makes
     * browsers and Cloudflare fetch a new build.
     *
     * @param string $file File name inside `public/build`, e.g. `app.css`.
     */
    public static function asset(string $file): string
    {
        $path = dirname(__DIR__, 2) . '/public/build/' . $file;
        $mtime = is_file($path) ? (string) filemtime($path) : '0';

        return '/build/' . $file . '?v=' . hash('crc32b', $file . $mtime);
    }

    /**
     * Formats a stored date or ISO datetime in the company time zone (Europe/Minsk),
     * independent of the server's default time zone.
     *
     * @param string $value  `Y-m-d` or ISO-8601 datetime.
     * @param string $format PHP date format, e.g. `d.m H:i`.
     */
    public static function time(string $value, string $format): string
    {
        $tz = new \DateTimeZone(\App\Clock::TZ);

        return (new \DateTimeImmutable($value, $tz))->setTimezone($tz)->format($format);
    }

    /** HTML-escapes a value for templates. */
    public static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
