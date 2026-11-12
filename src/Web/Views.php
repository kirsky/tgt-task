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

    /** HTML-escapes a value for templates. */
    public static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
