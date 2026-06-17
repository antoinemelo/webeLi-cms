PRAGMA foreign_keys = ON;

INSERT INTO ai_provider_types(key, name, description, sort_order, enabled, is_system)
VALUES
    ('editorial', 'Éditorial', 'Rédaction, résumé, SEO, traduction et assistance éditoriale.', 10, 1, 1),
    ('embeddings', 'Embeddings', 'Vectorisation de contenus pour recherche sémantique, RAG et similarité.', 20, 1, 1),
    ('reranking', 'Re-ranking', 'Classement secondaire des résultats et amélioration de pertinence.', 30, 1, 1),
    ('transcription', 'Transcription', 'Audio vers texte, sous-titrage et indexation de médias.', 40, 1, 1),
    ('voice', 'Vocal', 'Voix, synthèse vocale, doublage et génération audio.', 50, 1, 1),
    ('image', 'Image', 'Analyse, génération, variantes et descriptions d’images.', 60, 1, 1),
    ('development', 'Développement', 'Aide au code, diagnostic technique, tests et intégration développeur.', 70, 1, 1),
    ('automation', 'Automatisation', 'Agents, workflows contrôlés, tâches outillées et actions assistées.', 80, 1, 1),
    ('local', 'Local', 'Modèles exécutés ou testés localement.', 90, 1, 1)
ON CONFLICT(key) DO UPDATE SET
    name = excluded.name,
    description = excluded.description,
    sort_order = excluded.sort_order,
    enabled = excluded.enabled,
    is_system = excluded.is_system,
    updated_at = CURRENT_TIMESTAMP;

INSERT INTO ai_settings(key, value_json, description, enabled)
VALUES
    ('ai.enabled', 'false', 'Activation globale du module IA. False par défaut.', 1),
    ('ai.default_model_id', 'null', 'Compatibilité : modèle global par défaut. Le choix effectif se fait par site et par usage.', 1),
    ('ai.default_text_model', '"null_text_model"', 'Compatibilité : modèle texte nul tant qu’aucun provider réel n’est configuré.', 1),
    ('ai.default_embedding_model', 'null', 'Compatibilité : modèle embeddings par défaut. Non configuré à ce stade.', 1),
    ('ai.log_prompts', 'false', 'Journalisation des prompts complets désactivée par défaut.', 1),
    ('ai.log_responses', 'false', 'Journalisation des réponses complètes désactivée par défaut.', 1)
ON CONFLICT(key) DO UPDATE SET
    value_json = excluded.value_json,
    description = excluded.description,
    enabled = excluded.enabled,
    updated_at = CURRENT_TIMESTAMP;

INSERT INTO ai_providers(type_key, key, name, provider_type, base_url, api_key_ref, enabled, is_default, options_json)
VALUES ('local', 'null_provider', 'Provider nul', 'null', NULL, NULL, 1, 1, '{"safe_default":true,"real_provider":false}')
ON CONFLICT(key) DO UPDATE SET
    type_key = excluded.type_key,
    name = excluded.name,
    provider_type = excluded.provider_type,
    base_url = excluded.base_url,
    api_key_ref = excluded.api_key_ref,
    enabled = excluded.enabled,
    is_default = excluded.is_default,
    options_json = excluded.options_json,
    updated_at = CURRENT_TIMESTAMP;

INSERT INTO ai_models(provider_id, key, name, model_type, context_window, input_price, output_price, currency, api_key_ref, max_monthly_budget_json, enabled, options_json)
SELECT id, 'null_text_model', 'Modèle texte nul', 'chat', 0, 0, 0, 'CHF', NULL, '{"amount":0,"currency":"CHF"}', 1, '{"safe_default":true,"returns_placeholder":true}' FROM ai_providers WHERE key = 'null_provider'
ON CONFLICT(provider_id, key) DO UPDATE SET
    name = excluded.name,
    model_type = excluded.model_type,
    context_window = excluded.context_window,
    input_price = excluded.input_price,
    output_price = excluded.output_price,
    currency = excluded.currency,
    api_key_ref = excluded.api_key_ref,
    max_monthly_budget_json = excluded.max_monthly_budget_json,
    enabled = excluded.enabled,
    options_json = excluded.options_json,
    updated_at = CURRENT_TIMESTAMP;

INSERT OR IGNORE INTO ai_model_usages(model_id, usage_key, sort_order)
SELECT m.id, 'editorial', 10 FROM ai_models m JOIN ai_providers p ON p.id = m.provider_id WHERE p.key = 'null_provider' AND m.key = 'null_text_model';

INSERT INTO ai_prompts(key, name, category, system_prompt, user_template, output_schema_json, language, enabled, version)
VALUES
    ('editorial.rewrite', 'Réécriture éditoriale', 'editorial', 'Vous êtes un assistant éditorial intégré à un CMS. Réécrivez le texte avec clarté, sobriété et fidélité au sens.', 'Texte à retravailler :

{{text}}', '{"type":"object","properties":{"text":{"type":"string"}}}', 'fr', 1, 1),
    ('seo.meta_description', 'Méta-description SEO', 'seo', 'Vous êtes un assistant SEO. Produisez une méta-description précise, naturelle et utile, sans surpromesse.', 'Titre : {{title}}
Contenu :
{{text}}

Rédigez une méta-description de 140 à 160 caractères.', '{"type":"object","properties":{"meta_description":{"type":"string"}}}', 'fr', 1, 1),
    ('seo.title', 'Titre SEO', 'seo', 'Vous êtes un assistant SEO intégré à un CMS. Proposez un titre clair, naturel et fidèle au contenu.', 'Titre actuel : {{title}}
Contenu :
{{text}}

Proposez un titre SEO court, lisible et sans surpromesse.', '{"type":"object","properties":{"title":{"type":"string"}}}', 'fr', 1, 1),
    ('translation.draft', 'Brouillon de traduction', 'translation', 'Vous êtes un assistant de traduction pour CMS. Traduisez fidèlement en conservant le ton, les noms propres et la structure utile.', 'Langue cible : {{target_locale}}
Texte source :
{{source_text}}', '{"type":"object","properties":{"draft":{"type":"string"}}}', NULL, 1, 1),
    ('media.alt_text', 'Texte alternatif média', 'image', 'Vous êtes un assistant accessibilité et médias. Rédigez un texte alternatif court, descriptif et factuel.', 'Contexte de l’image : {{image_context}}
Texte alternatif attendu en {{locale}}.', '{"type":"object","properties":{"alt_text":{"type":"string"}}}', 'fr', 1, 1),
    ('editorial.suggest_title', 'Suggestion de titre éditorial', 'editorial', '', '', '{"type":"object","properties":{"title":{"type":"string"}}}', 'fr', 0, 1),
    ('seo.suggest_metadata', 'Suggestion de métadonnées SEO', 'seo', '', '', '{"type":"object","properties":{"title":{"type":"string"},"description":{"type":"string"}}}', 'fr', 0, 1),
    ('translation.prepare_entry', 'Préparation de traduction', 'translation', '', '', '{"type":"object","properties":{"draft":{"type":"object"}}}', NULL, 0, 1)
ON CONFLICT(key, version) DO UPDATE SET
    name = excluded.name,
    category = excluded.category,
    system_prompt = excluded.system_prompt,
    user_template = excluded.user_template,
    output_schema_json = excluded.output_schema_json,
    language = excluded.language,
    enabled = excluded.enabled,
    updated_at = CURRENT_TIMESTAMP;

-- Catalogue clair : fournisseurs désactivés par défaut, aucun secret en clair.
INSERT INTO ai_providers(type_key, key, name, provider_type, base_url, api_key_ref, enabled, is_default, options_json)
VALUES
    ('editorial', 'openai', 'OpenAI', 'openai_compatible', 'https://api.openai.com/v1', 'env:OPENAI_API_KEY', 0, 0, '{"catalog":true,"note":"Référence généraliste et écosystème API."}'),
    ('editorial', 'anthropic', 'Anthropic / Claude', 'custom_http', 'https://api.anthropic.com/v1', 'env:ANTHROPIC_API_KEY', 0, 0, '{"catalog":true,"note":"Rédaction, analyse et raisonnement."}'),
    ('editorial', 'google_gemini', 'Google / Gemini / Vertex AI', 'custom_http', 'https://generativelanguage.googleapis.com/v1beta', 'env:GOOGLE_AI_API_KEY', 0, 0, '{"catalog":true,"note":"Multimodal et intégration Google Cloud."}'),
    ('editorial', 'mistral', 'Mistral AI', 'openai_compatible', 'https://api.mistral.ai/v1', 'env:MISTRAL_API_KEY', 0, 0, '{"catalog":true,"note":"Acteur européen pertinent pour CMS."}'),
    ('editorial', 'apertus', 'Apertus / APERTVS.ai', 'custom_http', NULL, 'env:APERTUS_API_KEY', 0, 0, '{"catalog":true,"note":"Souveraineté suisse, transparence, multilingue."}'),
    ('editorial', 'infomaniak', 'Infomaniak AI Services', 'infomaniak', 'https://api.infomaniak.com/ai', 'env:INFOMANIAK_AI_API_KEY', 0, 0, '{"catalog":true,"note":"Option suisse pour services IA."}'),
    ('editorial', 'meta_llama', 'Meta / Llama', 'custom_http', NULL, 'env:LLAMA_API_KEY', 0, 0, '{"catalog":true,"note":"Famille ouverte très diffusée."}'),
    ('editorial', 'alibaba_qwen', 'Alibaba / Qwen', 'custom_http', NULL, 'env:QWEN_API_KEY', 0, 0, '{"catalog":true,"note":"Modèles texte et code."}'),
    ('editorial', 'deepseek', 'DeepSeek', 'custom_http', 'https://api.deepseek.com/v1', 'env:DEEPSEEK_API_KEY', 0, 0, '{"catalog":true,"note":"Modèles de raisonnement et code."}'),
    ('embeddings', 'cohere', 'Cohere', 'custom_http', 'https://api.cohere.com/v2', 'env:COHERE_API_KEY', 0, 0, '{"catalog":true,"note":"RAG, embeddings et reranking."}'),
    ('editorial', 'azure_ai', 'Microsoft Azure AI', 'custom_http', NULL, 'env:AZURE_AI_API_KEY', 0, 0, '{"catalog":true,"note":"Cloud entreprise et gouvernance."}'),
    ('editorial', 'aws_bedrock', 'Amazon Bedrock', 'custom_http', NULL, 'env:AWS_BEDROCK_API_KEY', 0, 0, '{"catalog":true,"note":"Agrégateur de modèles entreprise."}'),
    ('editorial', 'ibm_watsonx', 'IBM watsonx', 'custom_http', NULL, 'env:IBM_WATSONX_API_KEY', 0, 0, '{"catalog":true,"note":"IA entreprise et conformité."}'),
    ('development', 'nvidia_nim', 'NVIDIA NIM', 'custom_http', NULL, 'env:NVIDIA_NIM_API_KEY', 0, 0, '{"catalog":true,"note":"Infrastructure GPU et modèles optimisés."}'),
    ('image', 'stability', 'Stability AI', 'custom_http', 'https://api.stability.ai/v2beta', 'env:STABILITY_API_KEY', 0, 0, '{"catalog":true,"note":"Images et usages créatifs."}'),
    ('image', 'black_forest_labs', 'Black Forest Labs / Flux', 'custom_http', NULL, 'env:BFL_API_KEY', 0, 0, '{"catalog":true,"note":"Génération image Flux."}'),
    ('voice', 'elevenlabs', 'ElevenLabs', 'custom_http', 'https://api.elevenlabs.io/v1', 'env:ELEVENLABS_API_KEY', 0, 0, '{"catalog":true,"note":"Voix, transcription, doublage."}'),
    ('local', 'huggingface', 'Hugging Face', 'custom_http', 'https://api-inference.huggingface.co', 'env:HUGGINGFACE_API_KEY', 0, 0, '{"catalog":true,"note":"Catalogue open source pour tests."}'),
    ('local', 'ollama', 'Ollama', 'ollama', 'http://127.0.0.1:11434/v1', NULL, 0, 0, '{"catalog":true,"note":"Développement local sans cloud."}'),
    ('local', 'lm_studio', 'LM Studio', 'openai_compatible', 'http://127.0.0.1:1234/v1', NULL, 0, 0, '{"catalog":true,"note":"Tests locaux OpenAI-compatible."}'),
    ('development', 'github_copilot', 'GitHub Copilot', 'custom_http', NULL, 'env:GITHUB_COPILOT_API_KEY', 0, 0, '{"catalog":true,"note":"Aide au développement."}'),
    ('editorial', 'perplexity', 'Perplexity', 'openai_compatible', 'https://api.perplexity.ai', 'env:PERPLEXITY_API_KEY', 0, 0, '{"catalog":true,"note":"Recherche assistée et réponses sourcées."}')
ON CONFLICT(key) DO UPDATE SET
    type_key = excluded.type_key,
    name = excluded.name,
    provider_type = excluded.provider_type,
    base_url = excluded.base_url,
    api_key_ref = COALESCE(ai_providers.api_key_ref, excluded.api_key_ref),
    options_json = excluded.options_json,
    updated_at = CURRENT_TIMESTAMP;

-- Exemples de modèles. Ils restent inactifs jusqu'à activation et saisie du budget.
-- Un modèle peut couvrir plusieurs usages via ai_model_usages.

INSERT INTO ai_models(provider_id, key, name, model_type, context_window, input_price, output_price, currency, api_key_ref, max_monthly_budget_json, enabled, options_json)
SELECT id, 'gpt-4.1-mini', 'GPT 4.1 mini', 'chat', 0, 0, 0, 'USD', NULL, '{"amount":0,"currency":"CHF"}', 0, '{"catalog":true}' FROM ai_providers WHERE key = 'openai'
ON CONFLICT(provider_id, key) DO UPDATE SET name=excluded.name, model_type=excluded.model_type, currency=excluded.currency, api_key_ref=COALESCE(ai_models.api_key_ref, excluded.api_key_ref), max_monthly_budget_json=excluded.max_monthly_budget_json, enabled=excluded.enabled, options_json=excluded.options_json, updated_at=CURRENT_TIMESTAMP;

DELETE FROM ai_model_usages WHERE model_id IN (SELECT m.id FROM ai_models m JOIN ai_providers p ON p.id = m.provider_id WHERE p.key = 'openai' AND m.key = 'gpt-4.1-mini');
INSERT OR IGNORE INTO ai_model_usages(model_id, usage_key, sort_order) SELECT m.id, 'editorial', 10 FROM ai_models m JOIN ai_providers p ON p.id = m.provider_id WHERE p.key = 'openai' AND m.key = 'gpt-4.1-mini';
INSERT OR IGNORE INTO ai_model_usages(model_id, usage_key, sort_order) SELECT m.id, 'image', 20 FROM ai_models m JOIN ai_providers p ON p.id = m.provider_id WHERE p.key = 'openai' AND m.key = 'gpt-4.1-mini';
INSERT OR IGNORE INTO ai_model_usages(model_id, usage_key, sort_order) SELECT m.id, 'development', 30 FROM ai_models m JOIN ai_providers p ON p.id = m.provider_id WHERE p.key = 'openai' AND m.key = 'gpt-4.1-mini';
INSERT OR IGNORE INTO ai_model_usages(model_id, usage_key, sort_order) SELECT m.id, 'automation', 40 FROM ai_models m JOIN ai_providers p ON p.id = m.provider_id WHERE p.key = 'openai' AND m.key = 'gpt-4.1-mini';

INSERT INTO ai_models(provider_id, key, name, model_type, context_window, input_price, output_price, currency, api_key_ref, max_monthly_budget_json, enabled, options_json)
SELECT id, 'claude-3-5-sonnet-latest', 'Claude Sonnet', 'chat', 0, 0, 0, 'USD', NULL, '{"amount":0,"currency":"CHF"}', 0, '{"catalog":true}' FROM ai_providers WHERE key = 'anthropic'
ON CONFLICT(provider_id, key) DO UPDATE SET name=excluded.name, model_type=excluded.model_type, currency=excluded.currency, api_key_ref=COALESCE(ai_models.api_key_ref, excluded.api_key_ref), max_monthly_budget_json=excluded.max_monthly_budget_json, enabled=excluded.enabled, options_json=excluded.options_json, updated_at=CURRENT_TIMESTAMP;

DELETE FROM ai_model_usages WHERE model_id IN (SELECT m.id FROM ai_models m JOIN ai_providers p ON p.id = m.provider_id WHERE p.key = 'anthropic' AND m.key = 'claude-3-5-sonnet-latest');
INSERT OR IGNORE INTO ai_model_usages(model_id, usage_key, sort_order) SELECT m.id, 'editorial', 10 FROM ai_models m JOIN ai_providers p ON p.id = m.provider_id WHERE p.key = 'anthropic' AND m.key = 'claude-3-5-sonnet-latest';
INSERT OR IGNORE INTO ai_model_usages(model_id, usage_key, sort_order) SELECT m.id, 'development', 20 FROM ai_models m JOIN ai_providers p ON p.id = m.provider_id WHERE p.key = 'anthropic' AND m.key = 'claude-3-5-sonnet-latest';
INSERT OR IGNORE INTO ai_model_usages(model_id, usage_key, sort_order) SELECT m.id, 'automation', 30 FROM ai_models m JOIN ai_providers p ON p.id = m.provider_id WHERE p.key = 'anthropic' AND m.key = 'claude-3-5-sonnet-latest';

INSERT INTO ai_models(provider_id, key, name, model_type, context_window, input_price, output_price, currency, api_key_ref, max_monthly_budget_json, enabled, options_json)
SELECT id, 'gemini-1.5-flash', 'Gemini Flash', 'chat', 0, 0, 0, 'USD', NULL, '{"amount":0,"currency":"CHF"}', 0, '{"catalog":true}' FROM ai_providers WHERE key = 'google_gemini'
ON CONFLICT(provider_id, key) DO UPDATE SET name=excluded.name, model_type=excluded.model_type, currency=excluded.currency, api_key_ref=COALESCE(ai_models.api_key_ref, excluded.api_key_ref), max_monthly_budget_json=excluded.max_monthly_budget_json, enabled=excluded.enabled, options_json=excluded.options_json, updated_at=CURRENT_TIMESTAMP;

DELETE FROM ai_model_usages WHERE model_id IN (SELECT m.id FROM ai_models m JOIN ai_providers p ON p.id = m.provider_id WHERE p.key = 'google_gemini' AND m.key = 'gemini-1.5-flash');
INSERT OR IGNORE INTO ai_model_usages(model_id, usage_key, sort_order) SELECT m.id, 'editorial', 10 FROM ai_models m JOIN ai_providers p ON p.id = m.provider_id WHERE p.key = 'google_gemini' AND m.key = 'gemini-1.5-flash';
INSERT OR IGNORE INTO ai_model_usages(model_id, usage_key, sort_order) SELECT m.id, 'image', 20 FROM ai_models m JOIN ai_providers p ON p.id = m.provider_id WHERE p.key = 'google_gemini' AND m.key = 'gemini-1.5-flash';
INSERT OR IGNORE INTO ai_model_usages(model_id, usage_key, sort_order) SELECT m.id, 'development', 30 FROM ai_models m JOIN ai_providers p ON p.id = m.provider_id WHERE p.key = 'google_gemini' AND m.key = 'gemini-1.5-flash';
INSERT OR IGNORE INTO ai_model_usages(model_id, usage_key, sort_order) SELECT m.id, 'automation', 40 FROM ai_models m JOIN ai_providers p ON p.id = m.provider_id WHERE p.key = 'google_gemini' AND m.key = 'gemini-1.5-flash';

INSERT INTO ai_models(provider_id, key, name, model_type, context_window, input_price, output_price, currency, api_key_ref, max_monthly_budget_json, enabled, options_json)
SELECT id, 'mistral-small-latest', 'Mistral Small latest', 'chat', 0, 0, 0, 'EUR', NULL, '{"amount":0,"currency":"CHF"}', 0, '{"catalog":true}' FROM ai_providers WHERE key = 'mistral'
ON CONFLICT(provider_id, key) DO UPDATE SET name=excluded.name, model_type=excluded.model_type, currency=excluded.currency, api_key_ref=COALESCE(ai_models.api_key_ref, excluded.api_key_ref), max_monthly_budget_json=excluded.max_monthly_budget_json, enabled=excluded.enabled, options_json=excluded.options_json, updated_at=CURRENT_TIMESTAMP;

DELETE FROM ai_model_usages WHERE model_id IN (SELECT m.id FROM ai_models m JOIN ai_providers p ON p.id = m.provider_id WHERE p.key = 'mistral' AND m.key = 'mistral-small-latest');
INSERT OR IGNORE INTO ai_model_usages(model_id, usage_key, sort_order) SELECT m.id, 'editorial', 10 FROM ai_models m JOIN ai_providers p ON p.id = m.provider_id WHERE p.key = 'mistral' AND m.key = 'mistral-small-latest';
INSERT OR IGNORE INTO ai_model_usages(model_id, usage_key, sort_order) SELECT m.id, 'development', 20 FROM ai_models m JOIN ai_providers p ON p.id = m.provider_id WHERE p.key = 'mistral' AND m.key = 'mistral-small-latest';
INSERT OR IGNORE INTO ai_model_usages(model_id, usage_key, sort_order) SELECT m.id, 'automation', 30 FROM ai_models m JOIN ai_providers p ON p.id = m.provider_id WHERE p.key = 'mistral' AND m.key = 'mistral-small-latest';

INSERT INTO ai_models(provider_id, key, name, model_type, context_window, input_price, output_price, currency, api_key_ref, max_monthly_budget_json, enabled, options_json)
SELECT id, 'embed-v4.0', 'Cohere Embed v4', 'embedding', 0, 0, 0, 'USD', NULL, '{"amount":0,"currency":"CHF"}', 0, '{"catalog":true}' FROM ai_providers WHERE key = 'cohere'
ON CONFLICT(provider_id, key) DO UPDATE SET name=excluded.name, model_type=excluded.model_type, currency=excluded.currency, api_key_ref=COALESCE(ai_models.api_key_ref, excluded.api_key_ref), max_monthly_budget_json=excluded.max_monthly_budget_json, enabled=excluded.enabled, options_json=excluded.options_json, updated_at=CURRENT_TIMESTAMP;

DELETE FROM ai_model_usages WHERE model_id IN (SELECT m.id FROM ai_models m JOIN ai_providers p ON p.id = m.provider_id WHERE p.key = 'cohere' AND m.key = 'embed-v4.0');
INSERT OR IGNORE INTO ai_model_usages(model_id, usage_key, sort_order) SELECT m.id, 'embeddings', 10 FROM ai_models m JOIN ai_providers p ON p.id = m.provider_id WHERE p.key = 'cohere' AND m.key = 'embed-v4.0';

INSERT INTO ai_models(provider_id, key, name, model_type, context_window, input_price, output_price, currency, api_key_ref, max_monthly_budget_json, enabled, options_json)
SELECT id, 'rerank-v3.5', 'Cohere Rerank v3.5', 'reranker', 0, 0, 0, 'USD', NULL, '{"amount":0,"currency":"CHF"}', 0, '{"catalog":true}' FROM ai_providers WHERE key = 'cohere'
ON CONFLICT(provider_id, key) DO UPDATE SET name=excluded.name, model_type=excluded.model_type, currency=excluded.currency, api_key_ref=COALESCE(ai_models.api_key_ref, excluded.api_key_ref), max_monthly_budget_json=excluded.max_monthly_budget_json, enabled=excluded.enabled, options_json=excluded.options_json, updated_at=CURRENT_TIMESTAMP;

DELETE FROM ai_model_usages WHERE model_id IN (SELECT m.id FROM ai_models m JOIN ai_providers p ON p.id = m.provider_id WHERE p.key = 'cohere' AND m.key = 'rerank-v3.5');
INSERT OR IGNORE INTO ai_model_usages(model_id, usage_key, sort_order) SELECT m.id, 'reranking', 10 FROM ai_models m JOIN ai_providers p ON p.id = m.provider_id WHERE p.key = 'cohere' AND m.key = 'rerank-v3.5';

INSERT INTO ai_models(provider_id, key, name, model_type, context_window, input_price, output_price, currency, api_key_ref, max_monthly_budget_json, enabled, options_json)
SELECT id, 'whisper-1', 'Whisper 1', 'speech_to_text', 0, 0, 0, 'USD', NULL, '{"amount":0,"currency":"CHF"}', 0, '{"catalog":true}' FROM ai_providers WHERE key = 'openai'
ON CONFLICT(provider_id, key) DO UPDATE SET name=excluded.name, model_type=excluded.model_type, currency=excluded.currency, api_key_ref=COALESCE(ai_models.api_key_ref, excluded.api_key_ref), max_monthly_budget_json=excluded.max_monthly_budget_json, enabled=excluded.enabled, options_json=excluded.options_json, updated_at=CURRENT_TIMESTAMP;

DELETE FROM ai_model_usages WHERE model_id IN (SELECT m.id FROM ai_models m JOIN ai_providers p ON p.id = m.provider_id WHERE p.key = 'openai' AND m.key = 'whisper-1');
INSERT OR IGNORE INTO ai_model_usages(model_id, usage_key, sort_order) SELECT m.id, 'transcription', 10 FROM ai_models m JOIN ai_providers p ON p.id = m.provider_id WHERE p.key = 'openai' AND m.key = 'whisper-1';

INSERT INTO ai_models(provider_id, key, name, model_type, context_window, input_price, output_price, currency, api_key_ref, max_monthly_budget_json, enabled, options_json)
SELECT id, 'flux-1.1-pro', 'Flux 1.1 Pro', 'image', 0, 0, 0, 'USD', NULL, '{"amount":0,"currency":"CHF"}', 0, '{"catalog":true}' FROM ai_providers WHERE key = 'black_forest_labs'
ON CONFLICT(provider_id, key) DO UPDATE SET name=excluded.name, model_type=excluded.model_type, currency=excluded.currency, api_key_ref=COALESCE(ai_models.api_key_ref, excluded.api_key_ref), max_monthly_budget_json=excluded.max_monthly_budget_json, enabled=excluded.enabled, options_json=excluded.options_json, updated_at=CURRENT_TIMESTAMP;

DELETE FROM ai_model_usages WHERE model_id IN (SELECT m.id FROM ai_models m JOIN ai_providers p ON p.id = m.provider_id WHERE p.key = 'black_forest_labs' AND m.key = 'flux-1.1-pro');
INSERT OR IGNORE INTO ai_model_usages(model_id, usage_key, sort_order) SELECT m.id, 'image', 10 FROM ai_models m JOIN ai_providers p ON p.id = m.provider_id WHERE p.key = 'black_forest_labs' AND m.key = 'flux-1.1-pro';

INSERT INTO ai_models(provider_id, key, name, model_type, context_window, input_price, output_price, currency, api_key_ref, max_monthly_budget_json, enabled, options_json)
SELECT id, 'eleven-multilingual-v2', 'Eleven multilingual v2', 'audio', 0, 0, 0, 'USD', NULL, '{"amount":0,"currency":"CHF"}', 0, '{"catalog":true}' FROM ai_providers WHERE key = 'elevenlabs'
ON CONFLICT(provider_id, key) DO UPDATE SET name=excluded.name, model_type=excluded.model_type, currency=excluded.currency, api_key_ref=COALESCE(ai_models.api_key_ref, excluded.api_key_ref), max_monthly_budget_json=excluded.max_monthly_budget_json, enabled=excluded.enabled, options_json=excluded.options_json, updated_at=CURRENT_TIMESTAMP;

DELETE FROM ai_model_usages WHERE model_id IN (SELECT m.id FROM ai_models m JOIN ai_providers p ON p.id = m.provider_id WHERE p.key = 'elevenlabs' AND m.key = 'eleven-multilingual-v2');
INSERT OR IGNORE INTO ai_model_usages(model_id, usage_key, sort_order) SELECT m.id, 'voice', 10 FROM ai_models m JOIN ai_providers p ON p.id = m.provider_id WHERE p.key = 'elevenlabs' AND m.key = 'eleven-multilingual-v2';
