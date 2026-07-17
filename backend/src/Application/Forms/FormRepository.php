<?php

declare(strict_types=1);

namespace App\Application\Forms;

use App\Core\Database;
use InvalidArgumentException;

final class FormRepository
{
    public function __construct(
        private readonly Database $db,
        private readonly ?FormSubmissionActivitySink $activitySink = null,
        private readonly ?FormRelationAddressToken $relationTokens = null,
    ) {}

    /** @return array{forms:list<array<string,mixed>>} */
    public function list(int $siteId, string $q = '', ?string $language = null, int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $language = $this->normalizeLanguage($language ?: 'fr');
        $params = ['site_id' => $siteId, 'lang' => $language];
        $where = ['f.site_id = :site_id'];
        if ($q !== '') {
            $where[] = '(f.form_key LIKE :q OR COALESCE(ft_lang.name, ft_any.name, f.form_key) LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        $rows = $this->db->all(
            'SELECT f.*, COALESCE(ft_lang.name, ft_any.name, f.form_key) AS name,
                    COALESCE(ft_lang.submit_label, ft_any.submit_label, \'Envoyer\') AS submit_label,
                    (SELECT COUNT(*) FROM form_fields ff WHERE ff.form_id = f.id) AS field_count,
                    (SELECT COUNT(*) FROM form_submissions fs WHERE fs.form_id = f.id) AS submission_count
             FROM forms f
             LEFT JOIN form_translations ft_lang ON ft_lang.form_id = f.id AND ft_lang.language_code = :lang
             LEFT JOIN form_translations ft_any ON ft_any.id = (SELECT MIN(id) FROM form_translations WHERE form_id = f.id)
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY f.updated_at DESC, f.id DESC
             LIMIT ' . $limit,
            $params
        );
        return ['forms' => array_map(fn(array $row): array => $this->summary($row), $rows)];
    }

    public function findById(int $siteId, int $id, ?string $language = null): ?array
    {
        $row = $this->db->one('SELECT * FROM forms WHERE site_id = :site_id AND id = :id', ['site_id' => $siteId, 'id' => $id]);
        return $row ? $this->hydrate($row, $language) : null;
    }

    public function findPublic(int $siteId, string $key, string $language): ?array
    {
        $row = $this->db->one(
            'SELECT * FROM forms WHERE site_id = :site_id AND form_key = :key AND status = \'published\' AND is_active = 1 LIMIT 1',
            ['site_id' => $siteId, 'key' => $key]
        );
        return $row ? $this->publicDefinition($row, $language) : null;
    }

    public function create(int $siteId, array $payload, ?int $actorId = null): array
    {
        $normalized = $this->normalizeFormPayload($payload, true);
        return $this->db->transaction(function () use ($siteId, $normalized, $actorId): array {
            $this->db->run(
                'INSERT INTO forms(site_id, form_key, status, is_active, store_submissions, notification_enabled, notification_recipients_json, notification_subject, honeypot_field, min_submit_seconds, rate_limit_max_attempts, rate_limit_window_seconds, settings_json, created_by_iam_user_id, updated_by_iam_user_id)
                 VALUES(:site_id, :form_key, :status, :is_active, :store_submissions, :notification_enabled, :notification_recipients_json, :notification_subject, :honeypot_field, :min_submit_seconds, :rate_limit_max_attempts, :rate_limit_window_seconds, :settings_json, :actor, :actor)',
                ['site_id' => $siteId, 'actor' => $actorId, ...$normalized['form']]
            );
            $id = $this->db->lastInsertId();
            $this->replaceTranslations($id, $normalized['translations']);
            $this->replaceFields($id, $normalized['fields']);
            return $this->findById($siteId, $id) ?? [];
        });
    }

    public function update(int $siteId, int $id, array $payload, ?int $actorId = null): ?array
    {
        if (!$this->db->one('SELECT id FROM forms WHERE site_id = :site_id AND id = :id', ['site_id' => $siteId, 'id' => $id])) {
            return null;
        }
        $normalized = $this->normalizeFormPayload($payload, false);
        return $this->db->transaction(function () use ($siteId, $id, $normalized, $actorId): array {
            $this->db->run(
                'UPDATE forms SET form_key = :form_key, status = :status, is_active = :is_active, store_submissions = :store_submissions,
                     notification_enabled = :notification_enabled, notification_recipients_json = :notification_recipients_json, notification_subject = :notification_subject,
                     honeypot_field = :honeypot_field, min_submit_seconds = :min_submit_seconds, rate_limit_max_attempts = :rate_limit_max_attempts,
                     rate_limit_window_seconds = :rate_limit_window_seconds, settings_json = :settings_json, updated_by_iam_user_id = :actor, updated_at = CURRENT_TIMESTAMP
                 WHERE site_id = :site_id AND id = :id',
                ['site_id' => $siteId, 'id' => $id, 'actor' => $actorId, ...$normalized['form']]
            );
            $this->replaceTranslations($id, $normalized['translations']);
            $this->replaceFields($id, $normalized['fields']);
            return $this->findById($siteId, $id) ?? [];
        });
    }

    public function delete(int $siteId, int $id): bool
    {
        $this->db->run('DELETE FROM forms WHERE site_id = :site_id AND id = :id', ['site_id' => $siteId, 'id' => $id]);
        return true;
    }

    /** @return array{submissions:list<array<string,mixed>>} */
    public function submissions(int $siteId, int $formId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $rows = $this->db->all(
            'SELECT fs.* FROM form_submissions fs JOIN forms f ON f.id = fs.form_id WHERE f.site_id = :site_id AND fs.form_id = :form_id ORDER BY fs.created_at DESC, fs.id DESC LIMIT ' . $limit,
            ['site_id' => $siteId, 'form_id' => $formId]
        );
        return ['submissions' => array_map(fn(array $r): array => $this->submissionRow($r), $rows)];
    }

    public function submit(int $siteId, string $formKey, string $language, array $payload, array $context): array
    {
        $form = $this->findPublic($siteId, $formKey, $language);
        if (!$form) {
            throw new InvalidArgumentException('FORM_NOT_FOUND');
        }
        $addressedRelation = null;
        $relationToken = trim((string) ($payload['_relation_token'] ?? ''));
        if ($relationToken !== '' && $this->relationTokens !== null) {
            $addressedRelation = $this->relationTokens->verify($relationToken, $siteId, $formKey);
        }
        $raw = is_array($payload['values'] ?? null) ? $payload['values'] : $payload;
        $spam = $this->spamCheck($form, $payload, $context);
        if ($spam['blocked']) {
            $this->recordRateHit((int) $form['id'], (string) ($context['ip_hash'] ?? ''));
            return ['accepted' => true, 'status' => 'received', 'message' => (string) $form['success_message']];
        }
        $values = $this->validateSubmission($form, $raw);
        $this->recordRateHit((int) $form['id'], (string) ($context['ip_hash'] ?? ''));
        $submissionId = null;
        if (!empty($form['store_submissions'])) {
            $submissionId = $this->storeSubmission($form, $language, $values, $context, $spam);
            if ($this->activitySink !== null) {
                try {
                    $this->activitySink->recordFormSubmission([
                        'site_id' => (int) $form['site_id'],
                        'form_id' => (int) $form['id'],
                        'form_key' => (string) $form['form_key'],
                        'form_name' => (string) ($form['name'] ?? $form['form_key']),
                        'submission_id' => $submissionId,
                        'status' => 'received',
                        'occurred_at' => gmdate('Y-m-d H:i:s'),
                        'addressed_relation' => $addressedRelation ?? (is_array($context['addressed_relation'] ?? null) ? $context['addressed_relation'] : null),
                        'verified_email' => is_string($context['verified_email'] ?? null) ? $context['verified_email'] : null,
                        'retention_until' => $context['retention_until'] ?? null,
                    ]);
                } catch (\Throwable) {
                    // Forms remains canonical and available when the optional CRM projection fails.
                }
            }
        }
        $this->notify($form, $values, $submissionId);
        return ['accepted' => true, 'status' => 'received', 'submission_id' => $submissionId, 'message' => (string) $form['success_message']];
    }

    /** @return list<array<string,string>> */
    public function csvRows(int $siteId, int $formId): array
    {
        $form = $this->findById($siteId, $formId);
        if (!$form) { return []; }
        $fields = $form['fields'];
        $header = ['id','created_at','status','language_code', ...array_map(fn($f) => (string) $f['field_key'], $fields)];
        $rows = [$header];
        foreach ($this->db->all('SELECT * FROM form_submissions WHERE form_id = :id ORDER BY created_at DESC, id DESC', ['id' => $formId]) as $submission) {
            $payload = json_decode((string) $submission['payload_json'], true) ?: [];
            $row = [(string)$submission['id'], (string)$submission['created_at'], (string)$submission['submission_status'], (string)($submission['language_code'] ?? '')];
            foreach ($fields as $field) {
                $value = $payload[(string)$field['field_key']] ?? '';
                $row[] = is_array($value) ? implode('|', array_map('strval', $value)) : (string) $value;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private function hydrate(array $row, ?string $language = null): array
    {
        $id = (int) $row['id'];
        $translations = $this->db->all('SELECT * FROM form_translations WHERE form_id = :id ORDER BY language_code', ['id' => $id]);
        $fields = $this->db->all('SELECT * FROM form_fields WHERE form_id = :id ORDER BY sort_order, id', ['id' => $id]);
        foreach ($fields as &$field) {
            $field['id'] = (int) $field['id'];
            $field['is_required'] = (bool) $field['is_required'];
            $field['is_active'] = (bool) $field['is_active'];
            $field['validation'] = json_decode((string) $field['validation_json'], true) ?: [];
            $field['settings'] = json_decode((string) $field['settings_json'], true) ?: [];
            unset($field['validation_json'], $field['settings_json']);
            $field['translations'] = $this->db->all('SELECT language_code, label, placeholder, help_text, options_json FROM form_field_translations WHERE field_id = :id ORDER BY language_code', ['id' => (int) $field['id']]);
            foreach ($field['translations'] as &$tr) {
                $tr['options'] = json_decode((string) $tr['options_json'], true) ?: [];
                unset($tr['options_json']);
            }
        }
        return $this->summary($row) + [
            'translations' => $translations,
            'fields' => $fields,
            'settings' => json_decode((string) $row['settings_json'], true) ?: [],
            'notification_recipients' => json_decode((string) $row['notification_recipients_json'], true) ?: [],
            'language_code' => $language,
        ];
    }

    private function publicDefinition(array $row, string $language): array
    {
        $form = $this->hydrate($row, $language);
        $tr = $this->bestTranslation($form['translations'], $language);
        $fields = [];
        foreach ($form['fields'] as $field) {
            if (empty($field['is_active'])) { continue; }
            $ft = $this->bestTranslation($field['translations'], $language);
            if (!$ft) { $ft = ['label' => $field['field_key'], 'placeholder' => '', 'help_text' => '', 'options' => []]; }
            $fields[] = [
                'field_key' => $field['field_key'], 'field_type' => $field['field_type'], 'is_required' => (bool) $field['is_required'], 'width' => $field['width'],
                'default_value' => $field['default_value'], 'validation' => $field['validation'], 'label' => $ft['label'] ?? $field['field_key'],
                'placeholder' => $ft['placeholder'] ?? '', 'help_text' => $ft['help_text'] ?? '', 'options' => $ft['options'] ?? [],
            ];
        }
        return [
            'id' => (int)$form['id'], 'site_id' => (int)$form['site_id'], 'form_key' => $form['form_key'],
            'name' => $tr['name'] ?? $form['form_key'], 'description_text' => $tr['description_text'] ?? '',
            'submit_label' => $tr['submit_label'] ?? 'Envoyer', 'success_message' => $tr['success_message'] ?? 'Merci, votre message a été envoyé.',
            'honeypot_field' => $form['honeypot_field'], 'min_submit_seconds' => (int)$form['min_submit_seconds'],
            'rate_limit_max_attempts' => (int)$form['rate_limit_max_attempts'], 'rate_limit_window_seconds' => (int)$form['rate_limit_window_seconds'],
            'store_submissions' => (bool)$form['store_submissions'], 'notification_enabled' => (bool)$form['notification_enabled'],
            'notification_recipients' => $form['notification_recipients'] ?? [], 'notification_subject' => $form['notification_subject'] ?? null,
            'fields' => $fields,
        ];
    }

    private function summary(array $row): array
    {
        return [
            'id' => (int)$row['id'], 'site_id' => (int)$row['site_id'], 'form_key' => (string)$row['form_key'], 'name' => (string)($row['name'] ?? $row['form_key']),
            'status' => (string)$row['status'], 'is_active' => (bool)$row['is_active'], 'store_submissions' => (bool)$row['store_submissions'],
            'notification_enabled' => (bool)$row['notification_enabled'], 'notification_subject' => $row['notification_subject'] ?? null,
            'honeypot_field' => (string)$row['honeypot_field'], 'min_submit_seconds' => (int)$row['min_submit_seconds'],
            'rate_limit_max_attempts' => (int)$row['rate_limit_max_attempts'], 'rate_limit_window_seconds' => (int)$row['rate_limit_window_seconds'],
            'field_count' => (int)($row['field_count'] ?? 0), 'submission_count' => (int)($row['submission_count'] ?? 0),
            'created_at' => (string)$row['created_at'], 'updated_at' => (string)$row['updated_at'],
        ];
    }

    private function normalizeFormPayload(array $payload, bool $creating): array
    {
        $key = strtolower(trim((string)($payload['form_key'] ?? '')));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $key)) { throw new InvalidArgumentException('Clé formulaire invalide.'); }
        $status = in_array(($payload['status'] ?? 'draft'), ['draft','published','archived'], true) ? (string)$payload['status'] : 'draft';
        $recipients = array_values(array_filter(array_map('strval', is_array($payload['notification_recipients'] ?? null) ? $payload['notification_recipients'] : []), fn($v) => filter_var($v, FILTER_VALIDATE_EMAIL)));
        $translations = is_array($payload['translations'] ?? null) ? $payload['translations'] : [];
        if ($translations === []) { $translations = [['language_code' => 'fr', 'name' => $payload['name'] ?? $key, 'submit_label' => 'Envoyer', 'success_message' => 'Merci, votre message a été envoyé.']]; }
        $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];
        if ($creating && $fields === []) { throw new InvalidArgumentException('Au moins un champ est obligatoire.'); }
        return [
            'form' => [
                'form_key' => $key, 'status' => $status, 'is_active' => !empty($payload['is_active']) ? 1 : 0, 'store_submissions' => array_key_exists('store_submissions', $payload) && !$payload['store_submissions'] ? 0 : 1,
                'notification_enabled' => !empty($payload['notification_enabled']) ? 1 : 0, 'notification_recipients_json' => json_encode($recipients, JSON_UNESCAPED_UNICODE),
                'notification_subject' => trim((string)($payload['notification_subject'] ?? '')) ?: null,
                'honeypot_field' => preg_match('/^[a-z][a-z0-9_]{1,40}$/', (string)($payload['honeypot_field'] ?? 'website')) ? (string)$payload['honeypot_field'] : 'website',
                'min_submit_seconds' => max(0, min(30, (int)($payload['min_submit_seconds'] ?? 2))),
                'rate_limit_max_attempts' => max(1, min(100, (int)($payload['rate_limit_max_attempts'] ?? 5))),
                'rate_limit_window_seconds' => max(60, min(86400, (int)($payload['rate_limit_window_seconds'] ?? 900))),
                'settings_json' => json_encode(is_array($payload['settings'] ?? null) ? $payload['settings'] : [], JSON_UNESCAPED_UNICODE),
            ],
            'translations' => $translations,
            'fields' => $fields,
        ];
    }

    private function replaceTranslations(int $formId, array $translations): void
    {
        $this->db->run('DELETE FROM form_translations WHERE form_id = :id', ['id' => $formId]);
        $fallbackName = '';
        foreach ($translations as $candidate) {
            if (!is_array($candidate)) { continue; }
            $fallbackName = trim((string)($candidate['name'] ?? ''));
            if ($fallbackName !== '') { break; }
        }
        if ($fallbackName === '') {
            $fallbackName = (string)($this->db->one('SELECT form_key FROM forms WHERE id = :id', ['id' => $formId])['form_key'] ?? 'formulaire');
        }

        $seen = [];
        foreach ($translations as $tr) {
            if (!is_array($tr)) { continue; }
            $lang = $this->normalizeLanguage((string)($tr['language_code'] ?? 'fr'));
            if (isset($seen[$lang])) { continue; }
            $seen[$lang] = true;
            $name = trim((string)($tr['name'] ?? '')) ?: $fallbackName;
            $this->db->run('INSERT INTO form_translations(form_id, language_code, name, description_text, submit_label, success_message) VALUES(:id,:lang,:name,:desc,:submit,:success)', [
                'id'=>$formId,'lang'=>$lang,'name'=>$name,'desc'=>trim((string)($tr['description_text'] ?? '')) ?: null,'submit'=>trim((string)($tr['submit_label'] ?? 'Envoyer')) ?: 'Envoyer','success'=>trim((string)($tr['success_message'] ?? 'Merci, votre message a été envoyé.')) ?: 'Merci, votre message a été envoyé.'
            ]);
        }
    }

    private function replaceFields(int $formId, array $fields): void
    {
        $this->db->run('DELETE FROM form_fields WHERE form_id = :id', ['id' => $formId]);
        $allowed = ['text','textarea','email','tel','url','number','select','radio','checkbox','checkboxes','hidden','date','consent'];
        foreach (array_values($fields) as $i => $field) {
            if (!is_array($field)) { continue; }
            $key = strtolower(trim((string)($field['field_key'] ?? '')));
            if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $key)) { throw new InvalidArgumentException('Clé de champ invalide: ' . $key); }
            $type = in_array(($field['field_type'] ?? 'text'), $allowed, true) ? (string)$field['field_type'] : 'text';
            $validation = is_array($field['validation'] ?? null) ? $field['validation'] : [];
            $settings = is_array($field['settings'] ?? null) ? $field['settings'] : [];
            $this->db->run('INSERT INTO form_fields(form_id, field_key, field_type, sort_order, is_required, is_active, width, default_value, validation_json, settings_json) VALUES(:form_id,:field_key,:field_type,:sort_order,:is_required,:is_active,:width,:default_value,:validation_json,:settings_json)', [
                'form_id'=>$formId,'field_key'=>$key,'field_type'=>$type,'sort_order'=>(int)($field['sort_order'] ?? $i),'is_required'=>!empty($field['is_required']) ? 1 : 0,'is_active'=>array_key_exists('is_active',$field) && !$field['is_active'] ? 0 : 1,
                'width'=>in_array(($field['width'] ?? 'full'), ['full','half','third'], true) ? (string)$field['width'] : 'full','default_value'=>isset($field['default_value']) ? (string)$field['default_value'] : null,
                'validation_json'=>json_encode($validation, JSON_UNESCAPED_UNICODE),'settings_json'=>json_encode($settings, JSON_UNESCAPED_UNICODE),
            ]);
            $fieldId = $this->db->lastInsertId();
            $translations = is_array($field['translations'] ?? null) ? $field['translations'] : [['language_code'=>'fr','label'=>$field['label'] ?? $key, 'options'=>$field['options'] ?? []]];
            $fallbackLabel = '';
            foreach ($translations as $candidate) {
                if (!is_array($candidate)) { continue; }
                $candidateLabel = trim((string)($candidate['label'] ?? ''));
                if ($candidateLabel !== '') { $fallbackLabel = $candidateLabel; break; }
            }
            if ($fallbackLabel === '') { $fallbackLabel = $key; }
            $seenTranslations = [];
            foreach ($translations as $tr) {
                if (!is_array($tr)) { continue; }
                $lang = $this->normalizeLanguage((string)($tr['language_code'] ?? 'fr'));
                if (isset($seenTranslations[$lang])) { continue; }
                $seenTranslations[$lang] = true;
                $label = trim((string)($tr['label'] ?? '')) ?: $fallbackLabel;
                $options = is_array($tr['options'] ?? null) ? $tr['options'] : [];
                $this->db->run('INSERT INTO form_field_translations(field_id, language_code, label, placeholder, help_text, options_json) VALUES(:id,:lang,:label,:placeholder,:help,:options)', [
                    'id'=>$fieldId,'lang'=>$lang,'label'=>$label,'placeholder'=>trim((string)($tr['placeholder'] ?? '')) ?: null,'help'=>trim((string)($tr['help_text'] ?? '')) ?: null,'options'=>json_encode($options, JSON_UNESCAPED_UNICODE),
                ]);
            }
        }
    }

    private function bestTranslation(array $translations, string $language): ?array
    {
        $language = $this->normalizeLanguage($language);
        foreach ($translations as $tr) { if (($tr['language_code'] ?? '') === $language) { return $tr; } }
        $base = substr($language, 0, 2);
        foreach ($translations as $tr) { if (substr((string)($tr['language_code'] ?? ''), 0, 2) === $base) { return $tr; } }
        return $translations[0] ?? null;
    }

    private function normalizeLanguage(?string $language): string
    {
        $language = strtolower(trim((string)($language ?: 'fr')));
        return preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $language) ? $language : 'fr';
    }

    private function spamCheck(array $form, array $payload, array $context): array
    {
        $score = 0.0; $reasons = [];
        $hp = (string)$form['honeypot_field'];
        if (trim((string)($payload[$hp] ?? '')) !== '') { $score += 1; $reasons[] = 'honeypot'; }
        $started = (int)($payload['_started_at'] ?? 0);
        if ($started > 0 && time() - $started < (int)$form['min_submit_seconds']) { $score += .5; $reasons[] = 'too_fast'; }
        $maxAttempts = max(1, (int)($form['rate_limit_max_attempts'] ?? 5));
        $windowSeconds = max(60, (int)($form['rate_limit_window_seconds'] ?? 900));
        $hits = $this->db->one("SELECT COUNT(*) AS c FROM form_rate_limit_hits WHERE form_id=:id AND ip_hash=:ip AND created_at >= datetime('now', :window)", ['id'=>(int)$form['id'],'ip'=>(string)($context['ip_hash'] ?? ''),'window'=>'-' . $windowSeconds . ' seconds']);
        if ((int)($hits['c'] ?? 0) >= $maxAttempts) { $score += 1; $reasons[] = 'rate_limit'; }
        return ['score'=>$score,'reasons'=>$reasons,'blocked'=>$score >= 1.0];
    }

    private function validateSubmission(array $form, array $raw): array
    {
        $errors = [];
        $values = [];
        foreach ($form['fields'] as $field) {
            $key = (string)$field['field_key']; $type = (string)$field['field_type'];
            $value = $raw[$key] ?? ($field['default_value'] ?? null);
            if ($type === 'checkboxes') { $value = is_array($value) ? array_values(array_map('strval', $value)) : []; }
            else { $value = is_scalar($value) ? trim((string)$value) : ''; }
            if (!empty($field['is_required']) && ($value === '' || $value === [] || ($type === 'consent' && !in_array((string)$value, ['1','true','on','yes'], true)))) { $errors[$key][] = 'Champ obligatoire.'; }
            if ($value !== '' && $type === 'email' && !filter_var((string)$value, FILTER_VALIDATE_EMAIL)) { $errors[$key][] = 'Adresse email invalide.'; }
            if ($value !== '' && $type === 'url' && !filter_var((string)$value, FILTER_VALIDATE_URL)) { $errors[$key][] = 'URL invalide.'; }
            $validation = is_array($field['validation'] ?? null) ? $field['validation'] : [];
            if (is_string($value) && isset($validation['max']) && mb_strlen($value) > (int)$validation['max']) { $errors[$key][] = 'Texte trop long.'; }
            if (is_string($value) && isset($validation['min']) && mb_strlen($value) < (int)$validation['min']) { $errors[$key][] = 'Texte trop court.'; }
            $values[$key] = $value;
        }
        if ($errors) { throw new FormValidationException($errors); }
        return $values;
    }

    private function storeSubmission(array $form, string $language, array $values, array $context, array $spam): int
    {
        return $this->db->transaction(function () use ($form, $language, $values, $context, $spam): int {
            $this->db->run('INSERT INTO form_submissions(form_id, site_id, language_code, submission_status, spam_score, spam_reasons_json, ip_hash, user_agent, referer_url, payload_json) VALUES(:form_id,:site_id,:lang,:status,:score,:reasons,:ip,:ua,:ref,:payload)', [
                'form_id'=>(int)$form['id'],'site_id'=>(int)$form['site_id'],'lang'=>$language,'status'=>'received','score'=>$spam['score'],'reasons'=>json_encode($spam['reasons'], JSON_UNESCAPED_UNICODE),'ip'=>$context['ip_hash'] ?? null,'ua'=>$context['user_agent'] ?? null,'ref'=>$context['referer_url'] ?? null,'payload'=>json_encode($values, JSON_UNESCAPED_UNICODE),
            ]);
            $id = $this->db->lastInsertId();
            foreach ($form['fields'] as $field) {
                $key = (string)$field['field_key']; $value = $values[$key] ?? '';
                $this->db->run('INSERT INTO form_submission_values(submission_id, field_id, field_key, field_label, value_json, value_text) VALUES(:sid,:fid,:key,:label,:json,:text)', [
                    'sid'=>$id,'fid'=>(int)($field['id'] ?? 0) ?: null,'key'=>$key,'label'=>(string)($field['label'] ?? $key),'json'=>json_encode($value, JSON_UNESCAPED_UNICODE),'text'=>is_array($value) ? implode(', ', $value) : (string)$value,
                ]);
            }
            return $id;
        });
    }

    private function recordRateHit(int $formId, string $ipHash): void
    {
        if ($ipHash === '') { return; }
        $this->db->run('INSERT INTO form_rate_limit_hits(form_id, ip_hash) VALUES(:id,:ip)', ['id'=>$formId,'ip'=>$ipHash]);
        $this->db->run("DELETE FROM form_rate_limit_hits WHERE created_at < datetime('now', '-2 days')");
    }

    private function notify(array $form, array $values, ?int $submissionId): void
    {
        if (empty($form['notification_enabled'])) { return; }
        $recipients = is_array($form['notification_recipients'] ?? null) ? $form['notification_recipients'] : [];
        $subject = trim((string)($form['notification_subject'] ?? 'Nouvelle soumission formulaire')) ?: 'Nouvelle soumission formulaire';
        $body = "Nouvelle soumission pour " . $form['form_key'] . "\n\n";
        foreach ($values as $k => $v) { $body .= $k . ': ' . (is_array($v) ? implode(', ', $v) : (string)$v) . "\n"; }
        foreach ($recipients as $recipient) {
            $status = 'skipped'; $error = null; $sentAt = null;
            if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                $ok = @mail((string)$recipient, $subject, $body, ['From' => 'noreply@localhost']);
                $status = $ok ? 'sent' : 'failed'; $sentAt = $ok ? gmdate('Y-m-d H:i:s') : null; $error = $ok ? null : 'mail_returned_false';
            }
            $this->db->run('INSERT INTO form_notification_deliveries(form_id, submission_id, recipient, status, subject, error_message, sent_at) VALUES(:fid,:sid,:recipient,:status,:subject,:error,:sent)', ['fid'=>(int)$form['id'],'sid'=>$submissionId,'recipient'=>(string)$recipient,'status'=>$status,'subject'=>$subject,'error'=>$error,'sent'=>$sentAt]);
        }
    }

    private function submissionRow(array $r): array
    {
        $r['id'] = (int)$r['id']; $r['form_id'] = (int)$r['form_id']; $r['site_id'] = (int)$r['site_id']; $r['spam_score'] = (float)$r['spam_score'];
        $r['payload'] = json_decode((string)$r['payload_json'], true) ?: []; $r['spam_reasons'] = json_decode((string)$r['spam_reasons_json'], true) ?: [];
        unset($r['payload_json'], $r['spam_reasons_json']); return $r;
    }
}
