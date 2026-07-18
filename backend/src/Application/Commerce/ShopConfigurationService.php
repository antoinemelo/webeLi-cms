<?php

declare(strict_types=1);

namespace App\Application\Commerce;

use App\Application\Business\StorefrontProjectionService;
use App\Core\Database;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Coordinates the Core-owned Shop system page with an existing Sale channel.
 * Public route, menu and cart visibility all derive from the same active row.
 */
final class ShopConfigurationService
{
    public function __construct(
        private readonly Database $core,
        private readonly SaleDatabaseConnection $sale,
        private readonly StorefrontProjectionService $projections,
        private readonly ?StorefrontMerchandisingService $merchandising = null,
    ) {}

    /** @return array<string,mixed> */
    public function configuration(int $siteId, string $locale): array
    {
        $locale = $this->assertScope($siteId, $locale);
        $row = $this->core->one(
            'SELECT * FROM cms_shop_configurations WHERE site_id = ? AND language_code = ? LIMIT 1',
            [$siteId, $locale]
        );
        return $row ? $this->contract($row) : $this->defaults($siteId, $locale);
    }

    /** @return list<array<string,mixed>> */
    public function configuredForSite(int $siteId): array
    {
        return array_map(
            fn(array $row): array => $this->contract($row),
            $this->core->all('SELECT * FROM cms_shop_configurations WHERE site_id = ? ORDER BY language_code', [$siteId])
        );
    }

    /** @return list<array<string,mixed>> */
    public function channels(int $siteId): array
    {
        $db = $this->sale->database();
        if ($db === null || !$db->tableExists('sale_channels')) return [];
        return array_map(static fn(array $row): array => [
            'channel_id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'currency' => (string) $row['currency'],
            'default_language' => (string) $row['default_language'],
            'status' => (string) $row['status'],
            'is_public' => (bool) $row['is_public'],
        ], $db->all(
            "SELECT id,code,name,currency,default_language,status,is_public
             FROM sale_channels
             WHERE site_id = ? AND channel_kind = 'storefront' AND status <> 'archived'
             ORDER BY is_default DESC,id",
            [$siteId]
        ));
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function saveDraft(int $siteId, string $locale, array $input, int $actorId): array
    {
        $locale = $this->assertScope($siteId, $locale);
        $existing = $this->core->one('SELECT * FROM cms_shop_configurations WHERE site_id=? AND language_code=?', [$siteId,$locale]);
        $channelId = max(0, (int) ($input['channel_id'] ?? $existing['channel_id'] ?? $this->defaultChannelId($siteId, $locale)));
        $channelRow = $channelId > 0 ? $this->requireChannel($siteId, $channelId, false) : null;

        $title = $this->text($input['title'] ?? $this->draftValue($existing, 'title', $locale === 'en' ? 'Shop' : 'Boutique'), 160);
        if ($title === '') throw new InvalidArgumentException('shop.title_required');
        $introduction = $this->text($input['introduction'] ?? $this->draftValue($existing, 'introduction', ''), 2000);
        $seo = is_array($input['seo'] ?? null) ? $input['seo'] : [];
        $draft = [
            'title' => $title,
            'introduction' => $introduction,
            'seo' => [
                'title' => $this->text($seo['title'] ?? $input['seo_title'] ?? $title, 180),
                'description' => $this->text($seo['description'] ?? $input['seo_description'] ?? $introduction, 320),
                'robots' => in_array((string) ($seo['robots'] ?? 'index,follow'), ['index,follow','noindex,follow','noindex,nofollow'], true)
                    ? (string) ($seo['robots'] ?? 'index,follow') : 'index,follow',
            ],
            'sections' => $this->sections(
                $input['sections'] ?? $this->draftValue($existing, 'sections', $this->defaultSections($locale)),
                $locale
            ),
            'analytics' => $this->analytics(
                $input['analytics'] ?? $this->draftValue($existing, 'analytics', [])
            ),
        ];

        $currency = strtoupper($this->text($input['currency'] ?? $existing['currency'] ?? $this->channelCurrency($siteId, $channelId), 3));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) throw new InvalidArgumentException('shop.currency_invalid');
        $themeKey = $this->key($input['theme_key'] ?? $existing['theme_key'] ?? 'default');
        $this->requireTheme($themeKey);
        $menuKey = $this->key($input['menu_key'] ?? $existing['menu_key'] ?? 'main');
        $menuLabel = $this->text($input['menu_label'] ?? $existing['menu_label'] ?? $title, 80);
        $json = $this->json($draft);

        $this->core->run(
            "INSERT INTO cms_shop_configurations(
                site_id,language_code,channel_id,channel_code,status,currency,theme_key,menu_key,menu_label,menu_position,
                cart_visible,show_quantities,last_available_threshold,draft_json,config_version,updated_by_iam_user_id
             ) VALUES(?,?,?,?,'inactive',?,?,?,?,?,?,?,?,?,1,?)
             ON CONFLICT(site_id,language_code) DO UPDATE SET
                channel_id=excluded.channel_id,channel_code=excluded.channel_code,currency=excluded.currency,theme_key=excluded.theme_key,
                menu_key=excluded.menu_key,menu_label=excluded.menu_label,menu_position=excluded.menu_position,
                cart_visible=excluded.cart_visible,show_quantities=excluded.show_quantities,
                last_available_threshold=excluded.last_available_threshold,draft_json=excluded.draft_json,
                config_version=cms_shop_configurations.config_version+1,
                updated_by_iam_user_id=excluded.updated_by_iam_user_id,updated_at=CURRENT_TIMESTAMP",
            [
                $siteId,$locale,$channelId > 0 ? $channelId : null,$channelRow['code']??null,$currency,$themeKey,$menuKey,$menuLabel,
                max(0, min(1000, (int) ($input['menu_position'] ?? $existing['menu_position'] ?? 100))),
                $this->boolInt($input, 'cart_visible', $existing, true),
                $this->boolInt($input, 'show_quantities', $existing, false),
                max(0, min(9999, (int) ($input['last_available_threshold'] ?? $existing['last_available_threshold'] ?? 1))),
                $json,$actorId > 0 ? $actorId : null,
            ]
        );
        return $this->configuration($siteId, $locale);
    }

    /** @return array<string,mixed> */
    public function publish(int $siteId, string $locale, int $actorId): array
    {
        $row = $this->requireConfiguration($siteId, $locale);
        $this->core->run(
            'UPDATE cms_shop_configurations SET published_json=draft_json,published_version=config_version,
             published_at=CURRENT_TIMESTAMP,updated_by_iam_user_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [$actorId > 0 ? $actorId : null, (int) $row['id']]
        );
        return $this->configuration($siteId, $locale);
    }

    /** @return array<string,mixed> */
    public function activate(int $siteId, string $locale, int $actorId, bool $force = false): array
    {
        $row = $this->requireConfiguration($siteId, $locale);
        if (!$force && (string) $row['status'] === 'active' && empty($row['last_error_code'])) return $this->contract($row);
        if ($row['published_json'] === null || (int) ($row['published_version'] ?? 0) < 1) {
            throw new InvalidArgumentException('shop.publish_required');
        }
        $channelId = (int) ($row['channel_id'] ?? 0);
        $channel = $this->requireChannel($siteId, $channelId, true);
        $domain = $this->core->one(
            'SELECT id FROM site_domains WHERE site_id=? AND is_primary=1 AND is_active=1 ORDER BY id LIMIT 1',
            [$siteId]
        );
        $domainId = $domain !== null ? (int) $domain['id'] : null;

        $this->core->transaction(function () use ($row,$siteId,$channelId,$domainId,$actorId): void {
            $this->core->run('UPDATE cms_shop_configurations SET status=\'activating\',last_error_code=NULL,last_error_message=NULL,updated_by_iam_user_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?', [$actorId > 0 ? $actorId : null,(int)$row['id']]);
            $this->core->run('UPDATE cms_sales_channel_storefronts SET is_default=0,updated_at=CURRENT_TIMESTAMP WHERE site_id=? AND channel_id<>?', [$siteId,$channelId]);
            $this->core->run(
                "INSERT INTO cms_sales_channel_storefronts(channel_id,site_id,domain_id,route_prefix,is_default,status)
                 VALUES(?,?,?,'/',1,'active')
                 ON CONFLICT(channel_id) DO UPDATE SET site_id=excluded.site_id,domain_id=excluded.domain_id,
                    route_prefix='/',is_default=1,status='active',updated_at=CURRENT_TIMESTAMP",
                [$channelId,$siteId,$domainId]
            );
        });

        try {
            $result = $this->projections->rebuild($siteId, $channelId, (string) $row['language_code']);
            $this->core->run(
                "UPDATE cms_shop_configurations SET status='active',activated_at=COALESCE(activated_at,CURRENT_TIMESTAMP),
                 last_rebuild_at=CURRENT_TIMESTAMP,last_error_code=NULL,last_error_message=NULL,
                 updated_by_iam_user_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?",
                [$actorId > 0 ? $actorId : null,(int)$row['id']]
            );
            return $this->configuration($siteId, $locale) + ['rebuild' => $result, 'channel' => $channel];
        } catch (Throwable $e) {
            $this->core->run(
                "UPDATE cms_shop_configurations SET status='error',last_error_code='projection_rebuild_failed',
                 last_error_message='La reconstruction du catalogue a échoué. Utilisez Réparer.',
                 updated_by_iam_user_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?",
                [$actorId > 0 ? $actorId : null,(int)$row['id']]
            );
            $this->restoreActiveChannelMapping($siteId, (int) $row['id']);
            throw new RuntimeException('shop.activation_failed', 0, $e);
        }
    }

    /** @return array<string,mixed> */
    public function deactivate(int $siteId, string $locale, int $actorId): array
    {
        $row = $this->requireConfiguration($siteId, $locale);
        $channelId = (int) ($row['channel_id'] ?? 0);
        $this->core->transaction(function () use ($row,$siteId,$channelId,$actorId): void {
            $this->core->run(
                "UPDATE cms_shop_configurations SET status='inactive',last_error_code=NULL,last_error_message=NULL,
                 updated_by_iam_user_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?",
                [$actorId > 0 ? $actorId : null,(int)$row['id']]
            );
            $this->restoreActiveChannelMapping($siteId, (int) $row['id']);
        });
        return $this->configuration($siteId, $locale);
    }

    /** @return array<string,mixed> */
    private function requireConfiguration(int $siteId, string $locale): array
    {
        $locale = $this->assertScope($siteId, $locale);
        $row = $this->core->one('SELECT * FROM cms_shop_configurations WHERE site_id=? AND language_code=?', [$siteId,$locale]);
        if ($row === null) throw new InvalidArgumentException('shop.configuration_missing');
        return $row;
    }

    /** @return array<string,mixed> */
    private function requireChannel(int $siteId, int $channelId, bool $mustBePublic): array
    {
        $db = $this->sale->database();
        $row = $db?->one(
            "SELECT * FROM sale_channels WHERE id=? AND site_id=? AND channel_kind='storefront' LIMIT 1",
            [$channelId,$siteId]
        );
        if ($row === null) throw new InvalidArgumentException('shop.channel_invalid');
        if ($mustBePublic && ((string)$row['status'] !== 'active' || (int)$row['is_public'] !== 1)) {
            throw new InvalidArgumentException('shop.channel_not_public');
        }
        return $row;
    }

    private function defaultChannelId(int $siteId, string $locale): int
    {
        foreach ($this->channels($siteId) as $channel) {
            if ($channel['default_language'] === $locale) return (int) $channel['channel_id'];
        }
        return (int) ($this->channels($siteId)[0]['channel_id'] ?? 0);
    }

    private function channelCurrency(int $siteId, int $channelId): string
    {
        if ($channelId < 1) return 'CHF';
        return (string) ($this->requireChannel($siteId, $channelId, false)['currency'] ?? 'CHF');
    }

    private function requireTheme(string $themeKey): void
    {
        if (!$this->core->one('SELECT 1 FROM themes WHERE theme_key=? AND is_active=1 LIMIT 1', [$themeKey])) {
            throw new InvalidArgumentException('shop.theme_invalid');
        }
    }

    private function assertScope(int $siteId, string $locale): string
    {
        $locale = strtolower(trim($locale));
        if ($siteId < 1 || !preg_match('/^[a-z]{2}(?:-[a-z0-9]{2,8})?$/', $locale)) throw new InvalidArgumentException('shop.scope_invalid');
        if (!$this->core->one('SELECT 1 FROM site_languages WHERE site_id=? AND language_code=? AND is_active=1', [$siteId,$locale])) {
            throw new InvalidArgumentException('shop.language_not_enabled');
        }
        return $locale;
    }

    /** @return array<string,mixed> */
    private function defaults(int $siteId, string $locale): array
    {
        $channelId = $this->defaultChannelId($siteId, $locale);
        $draft = ['title'=>$locale === 'en' ? 'Shop' : 'Boutique','introduction'=>'','seo'=>['title'=>$locale === 'en' ? 'Shop' : 'Boutique','description'=>'','robots'=>'index,follow'],'sections'=>$this->defaultSections($locale),'analytics'=>$this->analytics([])];
        return [
            'id'=>null,'site_id'=>$siteId,'language_code'=>$locale,'channel_id'=>$channelId ?: null,'channel_code'=>$channelId>0?(string)($this->requireChannel($siteId,$channelId,false)['code']??''):null,'status'=>'not_initialized',
            'currency'=>$this->channelCurrency($siteId,$channelId),'route_path'=>'/shop','theme_key'=>'default','menu_key'=>'main',
            'menu_label'=>$locale === 'en' ? 'Shop' : 'Boutique','menu_position'=>100,'cart_visible'=>true,'show_quantities'=>false,
            'last_available_threshold'=>1,'draft'=>$draft,'published'=>null,'config_version'=>0,'published_version'=>null,
            'is_initialized'=>false,'is_published'=>false,'public_visible'=>false,'last_rebuild_at'=>null,'last_error_code'=>null,
            'last_error_message'=>null,'updated_at'=>null,
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function contract(array $row): array
    {
        $draft = json_decode((string) $row['draft_json'], true);
        $published = $row['published_json'] !== null ? json_decode((string) $row['published_json'], true) : null;
        $draft=is_array($draft)?$draft:[];
        $draft['sections']=$this->sections($draft['sections']??[] ,(string)$row['language_code']);
        $draft['analytics']=$this->analytics($draft['analytics']??[]);
        if (is_array($published)) {
            $published['sections']=$this->sections($published['sections']??[] ,(string)$row['language_code']);
            $published['analytics']=$this->analytics($published['analytics']??[]);
        }
        $contract = [
            'id'=>(int)$row['id'],'site_id'=>(int)$row['site_id'],'language_code'=>(string)$row['language_code'],
            'channel_id'=>$row['channel_id'] !== null ? (int)$row['channel_id'] : null,'channel_code'=>$row['channel_code']??null,'status'=>(string)$row['status'],
            'currency'=>(string)$row['currency'],'route_path'=>(string)$row['route_path'],'theme_key'=>(string)$row['theme_key'],
            'menu_key'=>(string)$row['menu_key'],'menu_label'=>(string)$row['menu_label'],'menu_position'=>(int)$row['menu_position'],
            'cart_visible'=>(bool)$row['cart_visible'],'show_quantities'=>(bool)$row['show_quantities'],
            'last_available_threshold'=>(int)$row['last_available_threshold'],'draft'=>$draft,
            'published'=>is_array($published)?$published:null,'config_version'=>(int)$row['config_version'],
            'published_version'=>$row['published_version'] !== null?(int)$row['published_version']:null,
            'is_initialized'=>true,'is_published'=>$row['published_json'] !== null,
            'public_visible'=>(string)$row['status']==='active' && $row['published_json'] !== null,
            'activated_at'=>$row['activated_at']??null,'published_at'=>$row['published_at']??null,
            'last_rebuild_at'=>$row['last_rebuild_at']??null,'last_error_code'=>$row['last_error_code']??null,
            'last_error_message'=>$row['last_error_message']??null,'updated_at'=>$row['updated_at']??null,
        ];
        $channelId=(int)($row['channel_id']??0);
        if ($this->merchandising!==null && $channelId>0 && is_array($draft)) {
            $contract['preview']=$this->merchandising->preview((int)$row['site_id'],$channelId,(string)$row['language_code'],$draft);
        }
        return $contract;
    }

    private function draftValue(?array $row, string $key, mixed $fallback): mixed
    {
        if (!$row) return $fallback;
        $draft = json_decode((string) ($row['draft_json'] ?? '{}'), true);
        return is_array($draft) && array_key_exists($key, $draft) ? $draft[$key] : $fallback;
    }

    /** @return list<array<string,mixed>> */
    private function sections(mixed $value, string $locale): array
    {
        if (!is_array($value)) return $this->defaultSections($locale);
        $defaults=[];
        foreach ($this->defaultSections($locale) as $section) $defaults[$section['key']]=$section;
        $result = [];
        foreach (array_slice($value, 0, 20) as $index => $section) {
            if (!is_array($section)) continue;
            $key = $this->key($section['key'] ?? 'section-' . $index);
            if ($key === '' || !isset($defaults[$key])) continue;
            $base=$defaults[$key];
            $display=(string)($section['display']??$base['display']);
            if (!in_array($display,['grid','carousel','list','chips'],true)) $display=(string)$base['display'];
            $rule=(string)($section['rule']??$base['rule']);
            $allowedRules=match($key){'promotions'=>['amount','percent'],'popular'=>['views'],'new'=>['first_published'],default=>['automatic']};
            if (!in_array($rule,$allowedRules,true)) $rule=(string)$base['rule'];
            $empty=(string)($section['empty_state']??$base['empty_state']);
            if (!in_array($empty,['hide','message'],true)) $empty='hide';
            $manual=[];
            foreach ((array)($section['manual_product_ids']??[]) as $id) if ((int)$id>0) $manual[]=(int)$id;
            $result[] = array_merge($base,[
                'key'=>$key,
                'enabled'=>!array_key_exists('enabled',$section)||(bool)$section['enabled'],
                'title'=>$this->text($section['title']??$base['title'],120),
                'limit'=>max(1,min(24,(int)($section['limit']??$base['limit']))),
                'display'=>$display,
                'rule'=>$rule,
                'empty_state'=>$empty,
                'empty_message'=>$this->text($section['empty_message']??$base['empty_message'],240),
                'view_all_label'=>$this->text($section['view_all_label']??$base['view_all_label'],80),
                'window_days'=>max(1,min(365,(int)($section['window_days']??$base['window_days']))),
                'manual_product_ids'=>array_slice(array_values(array_unique($manual)),0,24),
            ]);
        }
        return $result ?: $this->defaultSections($locale);
    }

    /** @return list<array<string,mixed>> */
    private function defaultSections(string $locale): array
    {
        $en = $locale === 'en';
        $empty=$en ? 'Nothing to show yet.' : 'Aucun élément à afficher pour le moment.';
        $all=$en ? 'View all' : 'Tout voir';
        return [
            ['key'=>'search','enabled'=>true,'title'=>$en ? 'Search, filters and sorting' : 'Recherche, filtres et tri','limit'=>24,'display'=>'list','rule'=>'automatic','empty_state'=>'hide','empty_message'=>$empty,'view_all_label'=>'','window_days'=>30,'manual_product_ids'=>[]],
            ['key'=>'groups','enabled'=>true,'title'=>$en ? 'Product groups' : 'Groupes de produits','limit'=>8,'display'=>'chips','rule'=>'automatic','empty_state'=>'hide','empty_message'=>$empty,'view_all_label'=>$all,'window_days'=>30,'manual_product_ids'=>[]],
            ['key'=>'promotions','enabled'=>true,'title'=>$en ? 'Best offers' : 'Meilleures promotions','limit'=>6,'display'=>'grid','rule'=>'percent','empty_state'=>'hide','empty_message'=>$empty,'view_all_label'=>$all,'window_days'=>30,'manual_product_ids'=>[]],
            ['key'=>'collections','enabled'=>true,'title'=>$en ? 'Explore categories' : 'Explorer les catégories','limit'=>8,'display'=>'grid','rule'=>'automatic','empty_state'=>'hide','empty_message'=>$empty,'view_all_label'=>$all,'window_days'=>30,'manual_product_ids'=>[]],
            ['key'=>'popular','enabled'=>true,'title'=>$en ? 'Popular products' : 'Produits populaires','limit'=>6,'display'=>'grid','rule'=>'views','empty_state'=>'hide','empty_message'=>$empty,'view_all_label'=>$all,'window_days'=>30,'manual_product_ids'=>[]],
            ['key'=>'keywords','enabled'=>true,'title'=>$en ? 'Popular keywords' : 'Mots-clés populaires','limit'=>8,'display'=>'chips','rule'=>'automatic','empty_state'=>'hide','empty_message'=>$empty,'view_all_label'=>'','window_days'=>30,'manual_product_ids'=>[]],
            ['key'=>'new','enabled'=>true,'title'=>$en ? 'New products' : 'Nouveaux produits','limit'=>6,'display'=>'grid','rule'=>'first_published','empty_state'=>'hide','empty_message'=>$empty,'view_all_label'=>$all,'window_days'=>30,'manual_product_ids'=>[]],
            ['key'=>'catalog','enabled'=>true,'title'=>$en ? 'All products' : 'Tous les produits','limit'=>24,'display'=>'grid','rule'=>'automatic','empty_state'=>'message','empty_message'=>$empty,'view_all_label'=>'','window_days'=>30,'manual_product_ids'=>[]],
        ];
    }

    /** @return array{dedupe_minutes:int,retention_days:int} */
    private function analytics(mixed $value): array
    {
        $value=is_array($value)?$value:[];
        return [
            'dedupe_minutes'=>max(1,min(1440,(int)($value['dedupe_minutes']??30))),
            'retention_days'=>max(7,min(365,(int)($value['retention_days']??90))),
        ];
    }

    /** Restore the site-level compatibility mapping from remaining active locale Shops. */
    private function restoreActiveChannelMapping(int $siteId, int $excludedConfigurationId): void
    {
        $replacement = $this->core->one(
            "SELECT channel_id FROM cms_shop_configurations
             WHERE site_id=? AND id<>? AND status='active' AND channel_id IS NOT NULL
             ORDER BY activated_at DESC,id LIMIT 1",
            [$siteId,$excludedConfigurationId]
        );
        $this->core->run(
            'UPDATE cms_sales_channel_storefronts SET is_default=0,updated_at=CURRENT_TIMESTAMP WHERE site_id=?',
            [$siteId]
        );
        if ($replacement !== null) {
            $this->core->run(
                "UPDATE cms_sales_channel_storefronts SET is_default=1,status='active',updated_at=CURRENT_TIMESTAMP WHERE site_id=? AND channel_id=?",
                [$siteId,(int)$replacement['channel_id']]
            );
            return;
        }
        $this->core->run(
            "UPDATE cms_sales_channel_storefronts SET status='disabled',updated_at=CURRENT_TIMESTAMP WHERE site_id=?",
            [$siteId]
        );
    }

    private function boolInt(array $input, string $key, ?array $existing, bool $fallback): int
    {
        if (array_key_exists($key,$input)) return (int)(bool)$input[$key];
        if ($existing && array_key_exists($key,$existing)) return (int)(bool)$existing[$key];
        return (int)$fallback;
    }

    private function text(mixed $value, int $max): string { return mb_substr(trim((string)$value),0,$max); }
    private function key(mixed $value): string { return trim((string)(preg_replace('/[^a-z0-9_-]+/','-',strtolower(trim((string)$value)))??''),'-_'); }
    private function json(array $value): string { return json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); }
}
