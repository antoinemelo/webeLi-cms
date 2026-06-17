<?php

declare(strict_types=1);

namespace App\StaticExport;

use App\Application\Forms\FormRepository;
use App\Application\PublicApi\PublicApiUrlResolver;

/**
 * Renders published public forms into the static HTML payload.
 *
 * A static export cannot rely on the theme runtime fetching
 * /api/v1/forms/{key} from the installation directory where the CMS happened
 * to run. The form markup is therefore materialized at export time from the
 * published forms database. Submissions still target the canonical dynamic
 * endpoint when a backend is available.
 */
final class StaticFormRenderer
{
    /** @var list<string> */
    private array $warnings = [];
    /** @var list<string> */
    private array $forms = [];

    public function __construct(
        private readonly ?FormRepository $formsRepository,
        private readonly PublicApiUrlResolver $apiUrls = new PublicApiUrlResolver(),
    ) {}

    /**
     * @param array<string,mixed> $site
     * @return array{html:string,warnings:list<string>,forms:list<string>,runtime_required:bool}
     */
    public function render(string $html, StaticExportRoute $route, array $site): array
    {
        $this->warnings = [];
        $this->forms = [];

        if (!preg_match('/data-form-block=/i', $html)) {
            return ['html' => $html, 'warnings' => [], 'forms' => [], 'runtime_required' => false];
        }

        if ($this->formsRepository === null) {
            $this->warnings[] = 'Formulaires statiques non rendus: base forms indisponible.';
            return ['html' => $html, 'warnings' => $this->warnings, 'forms' => [], 'runtime_required' => false];
        }

        $self = $this;
        $html = preg_replace_callback(
            '#<section\b([^>]*\bdata-form-block=("|\')([^"\']+)\2[^>]*)>(.*?)</section>#is',
            static function (array $match) use ($self, $route, $site): string {
                return $self->renderSection($match, $route, $site);
            },
            $html
        ) ?? $html;

        return [
            'html' => $html,
            'warnings' => $this->warnings,
            'forms' => array_values(array_unique($this->forms)),
            'runtime_required' => $this->forms !== [],
        ];
    }

    /** @param array<int,string> $match @param array<string,mixed> $site */
    private function renderSection(array $match, StaticExportRoute $route, array $site): string
    {
        $attrs = (string) $match[1];
        $quote = (string) $match[2];
        $key = trim(html_entity_decode((string) $match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $inner = (string) $match[4];
        if ($key === '') {
            return (string) $match[0];
        }

        $form = null;
        try {
            $form = $this->formsRepository?->findPublic($route->siteId, $key, $route->languageCode);
        } catch (\Throwable $e) {
            $this->warnings[] = 'Formulaire ' . $key . ' non rendu: ' . $e->getMessage();
        }
        if (!$form) {
            $this->warnings[] = 'Formulaire public introuvable pour export statique: ' . $key . ' (' . $route->languageCode . ').';
            return (string) $match[0];
        }

        $this->forms[] = $key;
        $submitUrl = $this->submitUrl($site, $key, $route->languageCode);
        $success = (string) ($form['success_message'] ?? 'Merci, votre message a été envoyé.');
        $formHtml = $this->formHtml($form, $submitUrl, $success);

        $attrs = preg_replace('/\sdata-form-block=("|\')[^"\']+\1/i', '', $attrs) ?? $attrs;
        $attrs = preg_replace('/\sdata-form-started=("|\')[^"\']*\1/i', '', $attrs) ?? $attrs;
        $attrs .= ' data-static-form-block=' . $quote . $this->e($key) . $quote;

        if (preg_match('#<div\b([^>]*\bclass=("|\')[^"\']*\bform-block__mount\b[^"\']*\2[^>]*)>.*?</div>#is', $inner)) {
            $inner = preg_replace(
                '#<div\b([^>]*\bclass=("|\')[^"\']*\bform-block__mount\b[^"\']*\2[^>]*)>.*?</div>#is',
                '<div$1>' . $formHtml . '</div>',
                $inner,
                1
            ) ?? $inner;
        } else {
            $inner .= '<div class="form-block__mount">' . $formHtml . '</div>';
        }

        return '<section' . $attrs . '>' . $inner . '</section>';
    }

    /** @param array<string,mixed> $form */
    private function formHtml(array $form, string $submitUrl, string $success): string
    {
        $fields = is_array($form['fields'] ?? null) ? $form['fields'] : [];
        $started = (string) time();
        $honeypot = trim((string) ($form['honeypot_field'] ?? 'website')) ?: 'website';
        $button = trim((string) ($form['submit_label'] ?? 'Envoyer')) ?: 'Envoyer';
        $html = '<form class="native-form native-form--static" method="post" action="' . $this->e($submitUrl) . '" novalidate data-static-form="1" data-static-submit-url="' . $this->e($submitUrl) . '" data-static-success-message="' . $this->e($success) . '">';
        $html .= '<input type="hidden" name="_started_at" value="' . $this->e($started) . '">';
        $html .= '<input class="form-hp" type="text" name="' . $this->e($honeypot) . '" tabindex="-1" autocomplete="off" aria-hidden="true">';
        $html .= '<div class="form-grid">';
        foreach ($fields as $field) {
            if (is_array($field)) {
                $html .= $this->fieldHtml($field);
            }
        }
        $html .= '</div><p class="form-message" data-form-message></p>';
        $html .= '<button class="btn btn--primary" type="submit">' . $this->e($button) . '</button>';
        $html .= '</form>';
        return $html;
    }

    /** @param array<string,mixed> $field */
    private function fieldHtml(array $field): string
    {
        $key = trim((string) ($field['field_key'] ?? ''));
        if ($key === '') {
            return '';
        }
        $type = (string) ($field['field_type'] ?? 'text');
        $width = in_array((string) ($field['width'] ?? 'full'), ['full', 'half', 'third'], true) ? (string) $field['width'] : 'full';
        $required = !empty($field['is_required']);
        $labelText = (string) ($field['label'] ?? $key);
        $placeholder = (string) ($field['placeholder'] ?? '');
        $default = (string) ($field['default_value'] ?? '');
        $help = trim((string) ($field['help_text'] ?? ''));
        $id = 'form_' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', $key);
        $label = '<label for="' . $this->e($id) . '">' . $this->e($labelText) . ($required ? ' *' : '') . '</label>';
        $helpHtml = $help !== '' ? '<small id="' . $this->e($id) . '_help">' . $this->e($help) . '</small>' : '';
        $error = '<div class="form-error" data-error-for="' . $this->e($key) . '"></div>';
        $common = 'id="' . $this->e($id) . '" name="' . $this->e($key) . '"' . ($required ? ' required' : '') . ($placeholder !== '' ? ' placeholder="' . $this->e($placeholder) . '"' : '');

        if ($type === 'textarea') {
            return '<div class="form-field form-field--' . $this->e($width) . '">' . $label . '<textarea ' . $common . ' rows="5">' . $this->e($default) . '</textarea>' . $helpHtml . $error . '</div>';
        }

        if (in_array($type, ['select', 'radio', 'checkboxes'], true)) {
            $options = is_array($field['options'] ?? null) ? $field['options'] : [];
            if ($type === 'select') {
                $opts = '';
                foreach ($options as $option) {
                    if (!is_array($option)) { continue; }
                    $value = (string) ($option['value'] ?? $option['label'] ?? '');
                    $text = (string) ($option['label'] ?? $option['value'] ?? '');
                    $opts .= '<option value="' . $this->e($value) . '">' . $this->e($text) . '</option>';
                }
                return '<div class="form-field form-field--' . $this->e($width) . '">' . $label . '<select ' . $common . '>' . $opts . '</select>' . $helpHtml . $error . '</div>';
            }
            $checks = '';
            foreach ($options as $option) {
                if (!is_array($option)) { continue; }
                $value = (string) ($option['value'] ?? $option['label'] ?? '');
                $text = (string) ($option['label'] ?? $option['value'] ?? '');
                $name = $key . ($type === 'checkboxes' ? '[]' : '');
                $checks .= '<label class="form-check"><input type="' . ($type === 'radio' ? 'radio' : 'checkbox') . '" name="' . $this->e($name) . '" value="' . $this->e($value) . '"> ' . $this->e($text) . '</label>';
            }
            return '<fieldset class="form-field form-field--' . $this->e($width) . '"><legend>' . $this->e($labelText) . ($required ? ' *' : '') . '</legend>' . $checks . $helpHtml . $error . '</fieldset>';
        }

        if ($type === 'consent') {
            return '<div class="form-field form-field--full"><label class="form-check"><input type="checkbox" id="' . $this->e($id) . '" name="' . $this->e($key) . '" value="1"' . ($required ? ' required' : '') . '> ' . $this->e($labelText) . '</label>' . $helpHtml . $error . '</div>';
        }

        $htmlType = in_array($type, ['email', 'tel', 'url', 'number', 'date', 'hidden', 'checkbox'], true) ? $type : 'text';
        if ($htmlType === 'hidden') {
            return '<input type="hidden" name="' . $this->e($key) . '" value="' . $this->e($default) . '">';
        }
        return '<div class="form-field form-field--' . $this->e($width) . '">' . $label . '<input type="' . $this->e($htmlType) . '" ' . $common . ' value="' . $this->e($default) . '">' . $helpHtml . $error . '</div>';
    }

    /** @param array<string,mixed> $site */
    private function submitUrl(array $site, string $key, string $language): string
    {
        if (trim((string) ($site['base_url'] ?? $site['current_base_url'] ?? '')) === '') {
            $this->warnings[] = 'Formulaire ' . $key . ': endpoint de soumission relatif faute de base_url canonique.';
        }
        return $this->apiUrls->endpoint($site, 'forms/' . rawurlencode($key) . '/submit')
            . '?lang=' . rawurlencode($language);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
