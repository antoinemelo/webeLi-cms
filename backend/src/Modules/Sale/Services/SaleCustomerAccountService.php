<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Core\Database;
use App\Modules\Business\Repositories\BusinessCompanyRepository;
use App\Modules\Business\Repositories\BusinessConsentRepository;
use App\Modules\Business\Repositories\BusinessContactRepository;
use App\Modules\Sale\Contracts\CmsAccountBridge;
use App\Modules\Sale\Exceptions\SaleValidationException;

final class SaleCustomerAccountService implements CmsAccountBridge
{
    public function __construct(
        private readonly Database $iam,
        private readonly SaleDatabaseConnection $sale,
        private readonly BusinessCompanyRepository $companies,
        private readonly BusinessContactRepository $contacts,
        private readonly BusinessConsentRepository $consents,
        private readonly SaleReturnService $returns,
        private readonly ?Database $business = null,
        private readonly ?SaleEventService $events = null,
    ) {}

    /** @return array{token:string,expires_at:string} */
    public function issueClaimProof(int $orderId): array
    {
        $order = $this->saleDb()->one('SELECT * FROM sale_orders WHERE id=?', [$orderId]);
        if ($order === null) {
            throw new SaleValidationException('sale.customer.order_not_found');
        }
        $identity = json_decode((string) $order['customer_snapshot_json'], true);
        $email = $this->email((string) ($identity['email'] ?? ''));
        $token = $this->token();
        $expiresAt = gmdate('Y-m-d H:i:s', time() + 86400 * 7);
        $this->saleDb()->transaction(function (Database $db) use ($order, $orderId, $email, $token, $expiresAt): void {
            $db->run("UPDATE sale_order_claim_proofs SET status='revoked' WHERE order_id=? AND status='active'", [$orderId]);
            $db->run(
                "INSERT INTO sale_order_claim_proofs(site_id,order_id,token_hash,email_hash,status,expires_at) VALUES(?,?,?,?, 'active', ?)",
                [(int) $order['site_id'], $orderId, hash('sha256', $token), hash('sha256', $email), $expiresAt]
            );
        });
        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    /** @return array{user:array<string,mixed>,session_token:string,crm_contact_id:int|null} */
    public function registerWithProof(int $siteId, string $proofToken, string $password): array
    {
        if (strlen($password) < 10 || strlen($password) > 200) {
            throw new SaleValidationException('sale.customer.password_invalid');
        }
        $proof = $this->proof($siteId, $proofToken);
        $order = $this->saleDb()->one('SELECT * FROM sale_orders WHERE id=? AND site_id=?', [(int) $proof['order_id'], $siteId]);
        if ($order === null) {
            throw new SaleValidationException('sale.customer.claim_invalid');
        }
        $identity = json_decode((string) $order['customer_snapshot_json'], true);
        $email = $this->email((string) ($identity['email'] ?? ''));
        if (!hash_equals((string) $proof['email_hash'], hash('sha256', $email))) {
            throw new SaleValidationException('sale.customer.claim_invalid');
        }
        $now = gmdate('Y-m-d H:i:s');
        $existingUser = $this->iam->one('SELECT * FROM iam_users WHERE email_normalized=?', [$email]);
        $createdUser = $existingUser === null;
        if ($existingUser !== null) {
            if ((int) $existingUser['is_active'] !== 1
                || $existingUser['email_verified_at'] === null
                || !password_verify($password, (string) $existingUser['password_hash'])) {
                throw new SaleValidationException('sale.customer.account_exists_login_required');
            }
            $userId = (int) $existingUser['id'];
        } else {
            $this->iam->run(
                'INSERT INTO iam_users(email,email_normalized,email_verified_at,password_hash,first_name,last_name,locale,is_active,login_mode,created_at,updated_at)
                 VALUES(?,?,?,?,?,?,?,?,\'password\',?,?)',
                [$email, $email, $now, password_hash($password, PASSWORD_DEFAULT), $identity['first_name'] ?? null, $identity['last_name'] ?? null, 'fr-CH', 1, $now, $now]
            );
            $userId = (int) $this->iam->lastInsertId();
        }
        $siteAccount = $this->iam->one('SELECT status FROM iam_customer_site_accounts WHERE user_id=? AND site_id=?', [$userId, $siteId]);
        if ($siteAccount !== null && (string) $siteAccount['status'] !== 'active') {
            throw new SaleValidationException('sale.customer.site_account_disabled');
        }
        $createdSiteAccount = $siteAccount === null;
        if ($createdSiteAccount) {
            $this->iam->run("INSERT INTO iam_customer_site_accounts(user_id,site_id,status) VALUES(?,?,'active')", [$userId, $siteId]);
        }
        try {
            $contact = $this->createOrLinkContact($siteId, $userId, $identity, $order);
            $this->linkOrder($siteId, $userId, (int) $order['id'], (int) $proof['id'], $contact, 'post_purchase_proof');
            $this->saveOrderAddresses($siteId, $userId, $order);
            $this->saleDb()->run("UPDATE sale_order_claim_proofs SET status='consumed',consumed_by_iam_user_id=?,consumed_at=CURRENT_TIMESTAMP WHERE id=?", [$userId, (int) $proof['id']]);
            if ($createdUser || $createdSiteAccount) {
                $this->events?->emit($siteId, 'customer.account.created', 'customer_account', $userId, [
                    'site_id' => $siteId,
                    'iam_user_id' => $userId,
                    'customer_contact_id' => isset($contact['id']) ? (int) $contact['id'] : null,
                    'customer_company_id' => isset($contact['company_id']) ? (int) $contact['company_id'] : null,
                    'language_code' => (string) ($identity['locale'] ?? 'fr-CH'),
                ], $userId, 'customer-account:' . $siteId . ':' . $userId);
            }
        } catch (\Throwable $e) {
            if ($createdSiteAccount) {
                $this->iam->run('DELETE FROM iam_customer_site_accounts WHERE user_id=? AND site_id=?', [$userId, $siteId]);
            }
            if ($createdUser) {
                $this->iam->run('DELETE FROM iam_users WHERE id=?', [$userId]);
            }
            throw $e;
        }
        $session = $this->openSession($siteId, $userId);
        return ['user' => $this->publicUser($userId), 'session_token' => $session, 'crm_contact_id' => $contact['id'] ?? null];
    }

    /** @return array<string,mixed> */
    public function claimForAuthenticatedAccount(int $siteId, int $userId, string $proofToken): array
    {
        $proof = $this->proof($siteId, $proofToken);
        $user = $this->iam->one('SELECT * FROM iam_users WHERE id=? AND is_active=1 AND email_verified_at IS NOT NULL', [$userId]);
        if ($user === null || !hash_equals((string) $proof['email_hash'], hash('sha256', (string) $user['email_normalized']))) {
            throw new SaleValidationException('sale.customer.claim_invalid');
        }
        $order = $this->saleDb()->one('SELECT * FROM sale_orders WHERE id=? AND site_id=?', [(int) $proof['order_id'], $siteId]);
        if ($order === null) {
            throw new SaleValidationException('sale.customer.claim_invalid');
        }
        $existing = $this->saleDb()->one('SELECT * FROM sale_customer_order_links WHERE order_id=?', [(int) $order['id']]);
        if ($existing !== null && (int) $existing['iam_user_id'] !== $userId) {
            throw new SaleValidationException('sale.customer.order_already_claimed');
        }
        $account = $this->saleDb()->one('SELECT * FROM sale_customer_account_links WHERE site_id=? AND iam_user_id=?', [$siteId, $userId]);
        $contact = $account === null ? null : ['id' => $account['crm_contact_id'], 'company_id' => $account['crm_company_id']];
        $this->linkOrder($siteId, $userId, (int) $order['id'], (int) $proof['id'], $contact, 'verified_email');
        $this->saleDb()->run("UPDATE sale_order_claim_proofs SET status='consumed',consumed_by_iam_user_id=?,consumed_at=CURRENT_TIMESTAMP WHERE id=?", [$userId, (int) $proof['id']]);
        return $this->orderPayload($order);
    }

    /** @return array{user:array<string,mixed>,session_token:string} */
    public function login(int $siteId, string $email, string $password): array
    {
        $email = $this->email($email);
        $user = $this->iam->one('SELECT * FROM iam_users WHERE email_normalized=? AND is_active=1', [$email]);
        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            throw new SaleValidationException('sale.customer.invalid_credentials');
        }
        $account = $this->iam->one("SELECT id FROM iam_customer_site_accounts WHERE user_id=? AND site_id=? AND status='active'", [(int) $user['id'], $siteId]);
        if ($account === null) {
            throw new SaleValidationException('sale.customer.site_account_not_found');
        }
        return ['user' => $this->publicUser((int) $user['id']), 'session_token' => $this->openSession($siteId, (int) $user['id'])];
    }

    /** @return array<string,mixed> */
    public function authenticate(int $siteId, ?string $token): array
    {
        if ($token === null || !preg_match('/^[A-Za-z0-9_-]{40,128}$/', $token)) {
            throw new SaleValidationException('sale.customer.authentication_required');
        }
        $row = $this->iam->one(
            "SELECT s.*,u.email,u.email_normalized,u.first_name,u.last_name,u.locale,u.is_active
             FROM iam_customer_sessions s JOIN iam_users u ON u.id=s.user_id
             JOIN iam_customer_site_accounts a ON a.user_id=u.id AND a.site_id=s.site_id
             WHERE s.token_hash=? AND s.site_id=? AND s.revoked_at IS NULL AND s.expires_at>CURRENT_TIMESTAMP
               AND u.is_active=1 AND a.status='active'", [hash('sha256', $token), $siteId]
        );
        if ($row === null) {
            throw new SaleValidationException('sale.customer.authentication_required');
        }
        $this->iam->run('UPDATE iam_customer_sessions SET last_seen_at=CURRENT_TIMESTAMP WHERE id=?', [(int) $row['id']]);
        return $row;
    }

    public function logout(int $siteId, string $token): void
    {
        $this->iam->run('UPDATE iam_customer_sessions SET revoked_at=CURRENT_TIMESTAMP WHERE site_id=? AND token_hash=?', [$siteId, hash('sha256', $token)]);
    }

    /** @return list<array<string,mixed>> */
    public function orders(int $siteId, int $userId): array
    {
        return array_map(fn(array $row): array => $this->orderPayload($row), $this->saleDb()->all(
            "SELECT o.* FROM sale_customer_order_links l JOIN sale_orders o ON o.id=l.order_id
             WHERE l.site_id=? AND l.iam_user_id=? AND l.status='active' ORDER BY o.created_at DESC,o.id DESC", [$siteId, $userId]
        ));
    }

    /** @return array<string,mixed> */
    public function order(int $siteId, int $userId, int $orderId): array
    {
        $row = $this->saleDb()->one(
            "SELECT o.* FROM sale_customer_order_links l JOIN sale_orders o ON o.id=l.order_id
             WHERE l.site_id=? AND l.iam_user_id=? AND l.status='active' AND o.id=?", [$siteId, $userId, $orderId]
        );
        if ($row === null) {
            throw new SaleValidationException('sale.customer.order_not_found');
        }
        $row['lines'] = $this->saleDb()->all('SELECT * FROM sale_order_lines WHERE order_id=? ORDER BY line_number', [$orderId]);
        return $this->orderPayload($row);
    }

    /** @return list<array<string,mixed>> */
    public function addresses(int $siteId, int $userId): array
    {
        $rows = $this->saleDb()->all('SELECT * FROM sale_customer_addresses WHERE site_id=? AND iam_user_id=? AND archived_at IS NULL ORDER BY is_default DESC,id', [$siteId,$userId]);
        foreach ($rows as &$row) { $row['address'] = json_decode((string) $row['address_json'], true) ?: []; unset($row['address_json']); }
        return $rows;
    }

    /** @param array<string,mixed> $address @return array<string,mixed> */
    public function saveAddress(int $siteId, int $userId, string $label, string $type, array $address, bool $default): array
    {
        $label = trim($label); if ($label === '') throw new SaleValidationException('sale.customer.address_label_required');
        if (!in_array($type, ['billing','shipping','both'], true)) throw new SaleValidationException('sale.customer.address_type_invalid');
        foreach (['line1','postal_code','city','country_code'] as $field) if (trim((string) ($address[$field] ?? '')) === '') throw new SaleValidationException('sale.customer.address_invalid');
        if ($default) $this->saleDb()->run('UPDATE sale_customer_addresses SET is_default=0 WHERE site_id=? AND iam_user_id=?', [$siteId,$userId]);
        $this->saleDb()->run('INSERT INTO sale_customer_addresses(site_id,iam_user_id,label,address_type,address_json,is_default) VALUES(?,?,?,?,?,?)', [$siteId,$userId,$label,$type,json_encode($address, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$default?1:0]);
        return $this->addresses($siteId,$userId)[0] ?? [];
    }

    /** @param list<array{order_line_id:int,quantity:int,restock?:bool,reason?:string}> $lines @return array<string,mixed> */
    public function requestReturn(int $siteId, int $userId, int $orderId, array $lines, ?string $reason, ?string $key): array
    {
        $this->order($siteId,$userId,$orderId);
        return $this->returns->request($orderId,$lines,$reason,$userId,$key);
    }

    /** @return array<string,mixed> */
    public function changeEmail(int $siteId, int $userId, string $currentPassword, string $newEmail): array
    {
        $user = $this->iam->one('SELECT * FROM iam_users WHERE id=? AND is_active=1', [$userId]);
        $newEmail = $this->email($newEmail);
        if ($user === null || !password_verify($currentPassword,(string)$user['password_hash'])) throw new SaleValidationException('sale.customer.current_password_invalid');
        if ($this->iam->one('SELECT id FROM iam_users WHERE email_normalized=? AND id<>?',[$newEmail,$userId])!==null) throw new SaleValidationException('sale.customer.email_in_use');
        $this->iam->transaction(function(Database $db) use($userId,$user,$newEmail):void {
            $db->run('INSERT INTO iam_customer_email_changes(user_id,previous_email_normalized,new_email_normalized,verification_method) VALUES(?,?,?,\'current_password\')',[$userId,$user['email_normalized'],$newEmail]);
            $db->run('UPDATE iam_users SET email=?,email_normalized=?,email_verified_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$newEmail,$newEmail,$userId]);
        });
        return $this->publicUser($userId);
    }

    /** @return array<string,mixed>|null */
    public function customerForAccount(int $siteId, int $iamUserId): ?array
    {
        $link = $this->saleDb()->one("SELECT * FROM sale_customer_account_links WHERE site_id=? AND iam_user_id=? AND status='active'", [$siteId, $iamUserId]);
        if ($link === null) {
            return null;
        }
        $link['profile'] = $this->publicUser($iamUserId);
        $link['crm_contact'] = $link['crm_contact_id'] === null ? null : $this->contacts->find($siteId, (int) $link['crm_contact_id']);
        return $link;
    }

    /** @param array<string,mixed> $profile @return array<string,mixed> */
    public function createOrUpdateProfile(int $siteId, array $profile): array
    {
        $userId = (int) ($profile['iam_user_id'] ?? 0);
        if ($userId < 1 || $this->iam->one('SELECT id FROM iam_users WHERE id=? AND is_active=1', [$userId]) === null) {
            throw new SaleValidationException('sale.customer.account_not_found');
        }
        $firstName = trim((string) ($profile['first_name'] ?? '')) ?: null;
        $lastName = trim((string) ($profile['last_name'] ?? '')) ?: null;
        $locale = trim((string) ($profile['locale'] ?? 'fr-CH')) ?: 'fr-CH';
        $this->iam->run('UPDATE iam_users SET first_name=?,last_name=?,locale=?,updated_at=CURRENT_TIMESTAMP WHERE id=?', [$firstName, $lastName, $locale, $userId]);
        $contact = $this->contacts->activeByIamUser($siteId, $userId);
        if ($contact !== null) {
            $contact = $this->contacts->update($siteId, (int) $contact['id'], ['first_name' => $firstName, 'last_name' => $lastName, 'preferred_language' => $locale], $userId);
        }
        return ['user' => $this->publicUser($userId), 'crm_contact' => $contact];
    }

    /** @return list<array<string,mixed>> */
    public function reviewIdentities(int $siteId, bool $allowVerifiedPhone = false, int $limit = 100): array
    {
        $business=$this->business??throw new SaleValidationException('sale.customer.identity_review_unavailable');
        $orders=$this->saleDb()->all("SELECT o.* FROM sale_orders o LEFT JOIN sale_customer_order_links l ON l.order_id=o.id AND l.status='active' WHERE o.site_id=? AND l.id IS NULL ORDER BY o.id DESC LIMIT ?",[$siteId,max(1,min(200,$limit))]);
        $contacts=$business->all('SELECT c.*,co.name AS organization_name FROM business_contacts c JOIN business_companies co ON co.id=c.company_id WHERE c.site_id=? AND c.archived_at IS NULL',[$siteId]);
        foreach($orders as $order){
            $identity=json_decode((string)$order['customer_snapshot_json'],true);$identity=is_array($identity)?$identity:[];
            $email=strtolower(trim((string)($identity['email']??'')));$phone=$this->phone((string)($identity['phone']??''));$name=strtolower(trim(((string)($identity['first_name']??'')).' '.((string)($identity['last_name']??''))));
            $iam=$email!==''?$this->iam->one('SELECT id,email,first_name,last_name,email_verified_at FROM iam_users WHERE email_normalized=? AND is_active=1',[$email]):null;
            $matched=[];
            foreach($contacts as $contact){
                $score=0;$signals=[];$contactEmail=strtolower(trim((string)($contact['email']??'')));$contactPhone=$this->phone((string)($contact['mobile']??$contact['phone']??''));
                $verifiedEmail=$email!==''&&(int)($business->one("SELECT COUNT(*) AS count FROM crm_contact_channels WHERE contact_id=? AND channel='email' AND normalized_value=? AND is_verified=1 AND archived_at IS NULL",[(int)$contact['id'],$email])['count']??0)>0;
                $verifiedPhone=$allowVerifiedPhone&&$phone!==''&&(int)($business->one("SELECT COUNT(*) AS count FROM crm_contact_channels WHERE contact_id=? AND normalized_value=? AND is_verified=1 AND archived_at IS NULL",[(int)$contact['id'],$phone])['count']??0)>0;
                if($email!==''&&$contactEmail===$email){$score+=$verifiedEmail?30:20;$signals[]=['rule'=>$verifiedEmail?'verified_crm_email':'crm_email','weight'=>$verifiedEmail?30:20,'explanation'=>$verifiedEmail?'E-mail CRM vérifié concordant':'E-mail CRM concordant mais non vérifié'];}
                if($verifiedPhone){$score+=25;$signals[]=['rule'=>'verified_phone','weight'=>25,'explanation'=>'Téléphone normalisé et vérifié concordant'];}
                $contactName=strtolower(trim((string)$contact['display_name']));if($name!==''&&$contactName===$name){$score+=5;$signals[]=['rule'=>'name','weight'=>5,'explanation'=>'Nom concordant, jamais suffisant seul'];}
                if($score>=20)$matched[]=['contact'=>$contact,'score'=>$score,'signals'=>$signals];
            }
            if($iam!==null&&$iam['email_verified_at']!==null){$baseScore=65;$baseSignals=[['rule'=>'verified_iam_email','weight'=>65,'explanation'=>'Compte avec e-mail vérifié concordant']];}else{$baseScore=0;$baseSignals=[];$iam=null;}
            if($matched===[])$matched=[['contact'=>null,'score'=>0,'signals'=>[]]];
            foreach($matched as $match){$contact=$match['contact'];$score=min(100,$baseScore+(int)$match['score']);$signals=array_merge($baseSignals,$match['signals']);if($score===0&&$iam===null&&$contact===null)$signals[]=['rule'=>'no_safe_match','weight'=>0,'explanation'=>'Aucune concordance sûre; le nom seul est ignoré'];
                $profiles=['transactional_customer'=>['type'=>'TransactionalCustomer','id'=>(int)$order['id'],'order_number'=>$order['order_number'],'fields'=>$identity],'iam_account'=>$iam===null?null:['type'=>'IamAccount','id'=>(int)$iam['id'],'fields'=>['email'=>$iam['email'],'first_name'=>$iam['first_name'],'last_name'=>$iam['last_name']]],'crm_contact'=>$contact===null?null:['type'=>'CrmContact','id'=>(int)$contact['id'],'fields'=>['email'=>$contact['email'],'phone'=>$contact['phone']?:$contact['mobile'],'first_name'=>$contact['first_name'],'last_name'=>$contact['last_name']]],'organization'=>$contact===null?null:['type'=>'Organization','id'=>(int)$contact['company_id'],'fields'=>['name'=>$contact['organization_name']]]];
                $divergences=$this->divergences($identity,$profiles);$provenance=$this->provenance($order,$identity,$iam,$contact);
                $this->saleDb()->run("INSERT INTO sale_identity_review_cases(site_id,order_id,candidate_iam_user_id,candidate_crm_contact_id,candidate_organization_id,confidence_score,confidence_level,signals_json,divergences_json,profiles_json,provenance_json) VALUES(?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT DO UPDATE SET confidence_score=excluded.confidence_score,confidence_level=excluded.confidence_level,signals_json=excluded.signals_json,divergences_json=excluded.divergences_json,profiles_json=excluded.profiles_json,provenance_json=excluded.provenance_json,updated_at=CURRENT_TIMESTAMP",[$siteId,(int)$order['id'],$iam['id']??null,$contact['id']??null,$contact['company_id']??null,$score,$score>=80?'high':($score>=50?'medium':'low'),$this->json($signals),$this->json($divergences),$this->json($profiles),$this->json($provenance)]);
                $this->recordProvenance($siteId,(int)$order['id'],'transactional_customer',$identity,'checkout','order:'.(int)$order['id'],70,false);
                $this->recordProvenance($siteId,(int)$order['id'],'transactional_customer',['commercial_event'=>'order_observed'],'event','order:'.(int)$order['id'],60,false);
                if($iam!==null)$this->recordProvenance($siteId,(int)$iam['id'],'iam_account',['email'=>$iam['email'],'first_name'=>$iam['first_name'],'last_name'=>$iam['last_name']],'account','iam:'.(int)$iam['id'],90,true);
                if($contact!==null)$this->recordProvenance($siteId,(int)$contact['id'],'crm_contact',['email'=>$contact['email'],'phone'=>$contact['phone']?:$contact['mobile'],'first_name'=>$contact['first_name'],'last_name'=>$contact['last_name']],'import','crm:'.(int)$contact['id'],50,false);
            }
        }
        $rows=$this->saleDb()->all('SELECT c.*,o.order_number,o.grand_total_minor,o.currency,(SELECT COUNT(*) FROM sale_identity_resolution_audit a WHERE a.review_case_id=c.id) AS decision_count FROM sale_identity_review_cases c JOIN sale_orders o ON o.id=c.order_id WHERE c.site_id=? ORDER BY CASE c.status WHEN \'pending\' THEN 0 WHEN \'postponed\' THEN 1 ELSE 2 END,c.confidence_score DESC,c.id DESC LIMIT ?',[$siteId,max(1,min(200,$limit))]);
        foreach($rows as &$row){foreach(['signals_json'=>'signals','divergences_json'=>'divergences','profiles_json'=>'profiles','provenance_json'=>'provenance'] as $column=>$key){$row[$key]=json_decode((string)$row[$column],true)?:[];unset($row[$column]);}$row['orders']=[['id'=>(int)$row['order_id'],'order_number'=>$row['order_number'],'grand_total_minor'=>(int)$row['grand_total_minor'],'currency'=>$row['currency']]];$row['activities']=($row['candidate_crm_contact_id']??null)!==null?$business->all('SELECT id,activity_type,summary,status,occurred_at FROM crm_sale_activities WHERE site_id=? AND related_contact_id=? ORDER BY occurred_at DESC LIMIT 20',[$siteId,(int)$row['candidate_crm_contact_id']]):[];}
        return $rows;
    }

    /** @param array<string,mixed> $fieldDecisions @return array<string,mixed> */
    public function decideIdentity(int $siteId,int $caseId,string $action,int $actorId,string $reason,array $fieldDecisions=[]):array
    {
        if(!in_array($action,['link','do_not_link','postpone'],true)||$actorId<1||trim($reason)==='')throw new SaleValidationException('sale.customer.identity_decision_invalid');
        $case=$this->saleDb()->one("SELECT * FROM sale_identity_review_cases WHERE id=? AND site_id=? AND status IN ('pending','postponed')",[$caseId,$siteId]);if($case===null)throw new SaleValidationException('sale.customer.identity_case_not_found');$before=$case;
        $status=['link'=>'linked','do_not_link'=>'not_linked','postpone'=>'postponed'][$action];
        if($action==='link'){$userId=(int)($case['candidate_iam_user_id']??0);$contactId=(int)($case['candidate_crm_contact_id']??0);$contact=$contactId>0?$this->contacts->find($siteId,$contactId):null;if($userId<1&&$contact===null)throw new SaleValidationException('sale.customer.identity_link_target_required');if($userId>0){if($contact!==null&&($contact['iam_user_id']??null)!==null&&(int)$contact['iam_user_id']!==$userId)throw new SaleValidationException('sale.customer.crm_contact_already_linked');if($contact!==null&&($contact['iam_user_id']??null)===null)$contact=$this->contacts->update($siteId,$contactId,['iam_user_id'=>$userId],$actorId);$this->linkOrder($siteId,$userId,(int)$case['order_id'],null,$contact,'admin',$actorId);}else{$order=$this->saleDb()->one('SELECT customer_company_id,customer_contact_id,customer_snapshot_json FROM sale_orders WHERE id=? AND site_id=?',[(int)$case['order_id'],$siteId])??throw new SaleValidationException('sale.customer.order_not_found');$snapshot=(string)$order['customer_snapshot_json'];$correlation='identity-review:'.$caseId.':'.bin2hex(random_bytes(4));$this->saleDb()->transaction(function(Database $db)use($case,$contact,$order,$reason,$correlation,$actorId):void{$db->run('UPDATE sale_orders SET customer_company_id=?,customer_contact_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[(int)$contact['company_id'],(int)$contact['id'],(int)$case['order_id']]);$db->run('INSERT INTO sale_order_customer_reconciliations(order_id,previous_company_id,previous_contact_id,company_id,contact_id,reason,correlation_id,linked_by_iam_user_id) VALUES(?,?,?,?,?,?,?,?)',[(int)$case['order_id'],$order['customer_company_id'],$order['customer_contact_id'],(int)$contact['company_id'],(int)$contact['id'],trim($reason),$correlation,$actorId]);});if((string)$this->saleDb()->one('SELECT customer_snapshot_json FROM sale_orders WHERE id=?',[(int)$case['order_id']])['customer_snapshot_json']!==$snapshot)throw new SaleValidationException('sale.customer_snapshot_changed');}}
        $this->recordProvenance($siteId,(int)$case['order_id'],'transactional_customer',['identity_resolution'=>$action],'operator','review:'.$caseId,100,true);
        $this->saleDb()->run('UPDATE sale_identity_review_cases SET status=?,reviewed_by_iam_user_id=?,decision_reason=?,reviewed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$status,$actorId,trim($reason),$caseId]);$after=$this->saleDb()->one('SELECT * FROM sale_identity_review_cases WHERE id=?',[$caseId])??[];
        $this->saleDb()->run('INSERT INTO sale_identity_resolution_audit(site_id,review_case_id,action,reason,before_json,after_json,field_decisions_json,actor_iam_user_id) VALUES(?,?,?,?,?,?,?,?)',[$siteId,$caseId,$action,trim($reason),$this->json($before),$this->json($after),$this->json($fieldDecisions),$actorId]);return $after;
    }

    /** @return array<string,mixed> */
    public function mergePreview(int $siteId,int $sourceUserId,int $targetUserId):array
    {
        if($sourceUserId===$targetUserId)throw new SaleValidationException('sale.customer.merge_invalid');$source=$this->identityProfile($siteId,$sourceUserId);$target=$this->identityProfile($siteId,$targetUserId);$fields=[];
        foreach(['email','first_name','last_name','phone','crm_contact_id','organization_id'] as $field){$left=$source['fields'][$field]??null;$right=$target['fields'][$field]??null;$conflict=$left!==null&&$right!==null&&(string)$left!==(string)$right;$fields[$field]=['source'=>$left,'target'=>$right,'conflict'=>$conflict,'allowed_choices'=>in_array($field,['first_name','last_name'],true)?['source','target']:['target']];}
        return ['source'=>$source,'target'=>$target,'fields'=>$fields,'conflicts'=>array_keys(array_filter($fields,static fn(array $f):bool=>$f['conflict'])),'orders_affected'=>count($source['orders']),'snapshot_policy'=>'immutable'];
    }

    /** @return array<string,mixed> */
    public function mergeAccounts(int $siteId, int $sourceUserId, int $targetUserId, int $actorId, string $reason, array $fieldDecisions=[]): array
    {
        if ($sourceUserId === $targetUserId || $actorId < 1 || trim($reason)==='') throw new SaleValidationException('sale.customer.merge_invalid');
        $source = $this->saleDb()->one("SELECT id FROM sale_customer_account_links WHERE site_id=? AND iam_user_id=? AND status='active'", [$siteId, $sourceUserId]);
        $target = $this->saleDb()->one("SELECT id FROM sale_customer_account_links WHERE site_id=? AND iam_user_id=? AND status='active'", [$siteId, $targetUserId]);
        if ($source === null || $target === null) {
            throw new SaleValidationException('sale.customer.merge_account_not_found');
        }
        $preview=$this->mergePreview($siteId,$sourceUserId,$targetUserId);foreach($preview['conflicts'] as $field){$choice=$fieldDecisions[$field]??null;if(!in_array($choice,$preview['fields'][$field]['allowed_choices'],true))throw new SaleValidationException('sale.customer.merge_field_decision_required');}
        return $this->saleDb()->transaction(function(Database $db) use($siteId,$sourceUserId,$targetUserId,$actorId,$reason,$fieldDecisions,$preview):array {
            $before = ['source_orders'=>$db->all('SELECT order_id FROM sale_customer_order_links WHERE site_id=? AND iam_user_id=? AND status=\'active\'',[$siteId,$sourceUserId]),'target_orders'=>$db->all('SELECT order_id FROM sale_customer_order_links WHERE site_id=? AND iam_user_id=? AND status=\'active\'',[$siteId,$targetUserId]),'source_addresses'=>$db->all('SELECT id FROM sale_customer_addresses WHERE site_id=? AND iam_user_id=?',[$siteId,$sourceUserId]),'source_account'=>$db->one('SELECT * FROM sale_customer_account_links WHERE site_id=? AND iam_user_id=?',[$siteId,$sourceUserId]),'target_account'=>$db->one('SELECT * FROM sale_customer_account_links WHERE site_id=? AND iam_user_id=?',[$siteId,$targetUserId]),'target_user'=>$this->publicUser($targetUserId)];
            $targetUser=$before['target_user'];foreach(['first_name','last_name'] as $field)if(($fieldDecisions[$field]??'target')==='source')$targetUser[$field]=$preview['fields'][$field]['source'];$this->iam->run('UPDATE iam_users SET first_name=?,last_name=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$targetUser['first_name'],$targetUser['last_name'],$targetUserId]);
            $db->run('UPDATE sale_customer_order_links SET iam_user_id=?,link_source=\'merge\',linked_by_iam_user_id=? WHERE site_id=? AND iam_user_id=?',[$targetUserId,$actorId,$siteId,$sourceUserId]);
            $db->run('UPDATE sale_customer_addresses SET iam_user_id=? WHERE site_id=? AND iam_user_id=?',[$targetUserId,$siteId,$sourceUserId]);
            $db->run("UPDATE sale_customer_account_links SET status='merged',merged_into_iam_user_id=?,updated_at=CURRENT_TIMESTAMP WHERE site_id=? AND iam_user_id=?",[$targetUserId,$siteId,$sourceUserId]);
            $after=['target_orders'=>$db->all('SELECT order_id FROM sale_customer_order_links WHERE site_id=? AND iam_user_id=? AND status=\'active\'',[$siteId,$targetUserId])];
            $db->run('INSERT INTO sale_customer_merge_audit(site_id,source_iam_user_id,target_iam_user_id,reason,before_json,after_json,field_decisions_json,actor_iam_user_id) VALUES(?,?,?,?,?,?,?,?)',[$siteId,$sourceUserId,$targetUserId,trim($reason),$this->json($before),$this->json($after),$this->json($fieldDecisions),$actorId]);
            $this->iam->run("UPDATE iam_customer_site_accounts SET status='merged',merged_into_user_id=?,updated_at=CURRENT_TIMESTAMP WHERE user_id=? AND site_id=?",[$targetUserId,$sourceUserId,$siteId]);
            return $db->one('SELECT * FROM sale_customer_merge_audit WHERE id=?',[(int)$db->lastInsertId()]) ?? [];
        });
    }

    public function separateMerge(int $siteId,int $auditId,int $actorId,string $reason):array
    {
        if($actorId<1||trim($reason)==='')throw new SaleValidationException('sale.customer.separation_invalid');$audit=$this->saleDb()->one("SELECT * FROM sale_customer_merge_audit WHERE id=? AND site_id=? AND status='applied'",[$auditId,$siteId]);if($audit===null)throw new SaleValidationException('sale.customer.merge_not_found');$before=json_decode((string)$audit['before_json'],true)?:[];$source=(int)$audit['source_iam_user_id'];$target=(int)$audit['target_iam_user_id'];
        return $this->saleDb()->transaction(function(Database $db)use($auditId,$siteId,$actorId,$reason,$before,$source,$target):array{foreach($before['source_orders']??[] as $row)$db->run("UPDATE sale_customer_order_links SET iam_user_id=?,link_source='admin',linked_by_iam_user_id=? WHERE site_id=? AND order_id=? AND iam_user_id=?",[$source,$actorId,$siteId,(int)$row['order_id'],$target]);foreach($before['source_addresses']??[] as $row)$db->run('UPDATE sale_customer_addresses SET iam_user_id=? WHERE site_id=? AND id=? AND iam_user_id=?',[$source,$siteId,(int)$row['id'],$target]);$db->run("UPDATE sale_customer_account_links SET status='active',merged_into_iam_user_id=NULL,updated_at=CURRENT_TIMESTAMP WHERE site_id=? AND iam_user_id=?",[$siteId,$source]);$db->run("UPDATE sale_customer_merge_audit SET status='reversed',reversed_at=CURRENT_TIMESTAMP,reversed_by_iam_user_id=?,reversal_reason=? WHERE id=?",[$actorId,trim($reason),$auditId]);$this->iam->run("UPDATE iam_customer_site_accounts SET status='active',merged_into_user_id=NULL,updated_at=CURRENT_TIMESTAMP WHERE user_id=? AND site_id=?",[$source,$siteId]);if(is_array($before['target_user']??null))$this->iam->run('UPDATE iam_users SET first_name=?,last_name=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$before['target_user']['first_name']??null,$before['target_user']['last_name']??null,$target]);$after=['source_orders'=>$db->all("SELECT order_id FROM sale_customer_order_links WHERE site_id=? AND iam_user_id=? AND status='active'",[$siteId,$source])];$db->run("INSERT INTO sale_identity_resolution_audit(site_id,action,reason,before_json,after_json,actor_iam_user_id) VALUES(?,'separate',?,?,?,?)",[$siteId,trim($reason),$this->json($before),$this->json($after),$actorId]);return $db->one('SELECT * FROM sale_customer_merge_audit WHERE id=?',[$auditId])??[];});
    }

    /** @param array<string,mixed>|null $contact */
    private function linkOrder(int $siteId,int $userId,int $orderId,?int $proofId,?array $contact,string $source,?int $actorId=null):void
    {
        $existing = $this->saleDb()->one('SELECT iam_user_id FROM sale_customer_order_links WHERE order_id=?', [$orderId]);
        if ($existing !== null && (int) $existing['iam_user_id'] !== $userId) {
            throw new SaleValidationException('sale.customer.order_already_claimed');
        }
        $this->saleDb()->run('INSERT INTO sale_customer_account_links(site_id,iam_user_id,crm_company_id,crm_contact_id,status,linked_by) VALUES(?,?,?,?,\'active\',?) ON CONFLICT(site_id,iam_user_id) DO UPDATE SET crm_company_id=COALESCE(excluded.crm_company_id,crm_company_id),crm_contact_id=COALESCE(excluded.crm_contact_id,crm_contact_id),status=\'active\',updated_at=CURRENT_TIMESTAMP',[$siteId,$userId,$contact['company_id']??null,$contact['id']??null,$source]);
        $this->saleDb()->run('INSERT INTO sale_customer_order_links(site_id,order_id,iam_user_id,claim_proof_id,link_source,linked_by_iam_user_id) VALUES(?,?,?,?,?,?) ON CONFLICT(order_id) DO NOTHING',[$siteId,$orderId,$userId,$proofId,$source,$actorId]);
    }

    /** @param array<string,mixed> $identity @param array<string,mixed> $order @return array<string,mixed> */
    private function createOrLinkContact(int $siteId,int $userId,array $identity,array $order):array
    {
        $linked = $this->contacts->activeByIamUser($siteId, $userId);
        if ($linked !== null) {
            return $linked;
        }
        $email=$this->email((string)($identity['email']??''));
        $contactId=(int)($order['customer_contact_id']??0);
        $contact=$contactId>0?$this->contacts->find($siteId,$contactId):null;
        if($contact===null){
            // A unique verified CRM address is strong evidence. Ambiguous or
            // unverified matches are intentionally left to identity review.
            $contact=$this->consents->uniqueVerifiedContactByChannel($siteId,'email',$email);
            $contactId=(int)($contact['id']??0);
        }
        if($contact!==null){
            if(($contact['iam_user_id']??null)!==null && (int)$contact['iam_user_id']!==$userId) throw new SaleValidationException('sale.customer.crm_contact_already_linked');
            $contact=$this->contacts->update($siteId,$contactId,['iam_user_id'=>$userId],$userId)??$contact;
        } else {
            $company=$this->companies->ensureSystemIndividualsCompany($siteId,$userId);
            $contact=$this->contacts->create($siteId,['company_id'=>(int)$company['id'],'iam_user_id'=>$userId,'first_name'=>$identity['first_name']??null,'last_name'=>$identity['last_name']??null,'email'=>$identity['email']??null,'phone'=>$identity['phone']??null,'status'=>'client'],$userId);
        }
        $this->consents->upsertChannel((int)$contact['id'],'email',$email,$email,true,true,$userId);
        return $contact;
    }

    /** @param array<string,mixed> $order */
    private function saveOrderAddresses(int $siteId,int $userId,array $order):void
    {
        foreach([['Facturation','billing',(string)$order['billing_address_json']],['Livraison','shipping',(string)$order['shipping_address_json']]] as [$label,$type,$json]){
            $address=json_decode($json,true); if(!is_array($address)||$address===[]) continue;
            $this->saleDb()->run('INSERT INTO sale_customer_addresses(site_id,iam_user_id,label,address_type,address_json,is_default) VALUES(?,?,?,?,?,1)',[$siteId,$userId,$label,$type,json_encode($address,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        }
    }

    /** @return array<string,mixed> */
    private function proof(int $siteId,string $token):array
    {
        if(!preg_match('/^[A-Za-z0-9_-]{40,128}$/',$token)) throw new SaleValidationException('sale.customer.claim_invalid');
        $row=$this->saleDb()->one("SELECT * FROM sale_order_claim_proofs WHERE site_id=? AND token_hash=? AND status='active' AND expires_at>CURRENT_TIMESTAMP",[$siteId,hash('sha256',$token)]);
        if($row===null) throw new SaleValidationException('sale.customer.claim_invalid');
        return $row;
    }

    private function openSession(int $siteId,int $userId):string
    {
        $token=$this->token(); $expires=gmdate('Y-m-d H:i:s',time()+86400*30);
        $this->iam->run('INSERT INTO iam_customer_sessions(user_id,site_id,token_hash,expires_at,last_seen_at) VALUES(?,?,?,?,CURRENT_TIMESTAMP)',[$userId,$siteId,hash('sha256',$token),$expires]);
        return $token;
    }
    private function token():string{return rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');}
    private function email(string $value):string{$value=strtolower(trim($value));if(filter_var($value,FILTER_VALIDATE_EMAIL)===false)throw new SaleValidationException('sale.customer.email_invalid');return $value;}
    /** @return array<string,mixed> */
    private function publicUser(int $id):array{$u=$this->iam->one('SELECT id,email,first_name,last_name,locale,is_active,email_verified_at FROM iam_users WHERE id=?',[$id]);if($u===null)throw new SaleValidationException('sale.customer.account_not_found');return $u;}
    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function orderPayload(array $row):array
    {
        return ['id'=>(int)$row['id'],'order_number'=>(string)$row['order_number'],'status'=>(string)$row['status'],'payment_status'=>(string)$row['payment_status'],'fulfillment_status'=>(string)$row['fulfillment_status'],'currency'=>(string)$row['currency'],'grand_total_minor'=>(int)$row['grand_total_minor'],'placed_at'=>$row['placed_at']??null,'lines'=>array_map(static fn(array $l):array=>['id'=>(int)$l['id'],'product_name'=>(string)$l['product_name'],'quantity'=>(int)$l['quantity'],'line_total_minor'=>(int)$l['line_total_minor']],$row['lines']??[]),'fulfillments'=>$this->customerFulfillments((int)$row['id'])];
    }
    /** @return list<array<string,mixed>> */
    private function customerFulfillments(int $orderId):array
    {
        $rows=$this->saleDb()->all('SELECT f.id,f.fulfillment_number,f.fulfillment_type,f.status,f.tracking_reference,f.pickup_code,f.due_at,f.ready_at,f.shipped_at,f.handed_over_at,f.delivered_at,l.code AS location_code,l.name AS location_name FROM sale_fulfillments f LEFT JOIN sale_stock_locations l ON l.id=f.stock_location_id WHERE f.order_id=? ORDER BY f.id',[$orderId]);
        foreach($rows as &$row)$row['lines']=$this->saleDb()->all('SELECT fl.order_line_id,fl.quantity,fl.prepared_quantity,ol.product_name,ol.variant_name FROM sale_fulfillment_lines fl INNER JOIN sale_order_lines ol ON ol.id=fl.order_line_id WHERE fl.fulfillment_id=? ORDER BY fl.id',[(int)$row['id']]);
        return $rows;
    }
    /** @param array<string,mixed> $identity @param array<string,mixed> $profiles @return list<array<string,mixed>> */
    private function divergences(array $identity,array $profiles):array
    {
        $rows=[];foreach(['email','phone','first_name','last_name'] as $field){$values=['checkout'=>$identity[$field]??null,'account'=>$profiles['iam_account']['fields'][$field]??null,'crm'=>$profiles['crm_contact']['fields'][$field]??null];$normalized=array_values(array_unique(array_filter(array_map(static fn(mixed $v):string=>strtolower(trim((string)$v)),$values),static fn(string $v):bool=>$v!=='')));if(count($normalized)>1)$rows[]=['field'=>$field,'values'=>$values,'explanation'=>'Valeurs divergentes : décision humaine requise'];}return $rows;
    }
    /** @param array<string,mixed> $order @param array<string,mixed> $identity @param array<string,mixed>|null $iam @param array<string,mixed>|null $contact @return array<string,mixed> */
    private function provenance(array $order,array $identity,?array $iam,?array $contact):array
    {
        $result=[];foreach($identity as $field=>$value)$result[$field][]=['source'=>'checkout','reference'=>'order:'.(int)$order['id'],'value'=>$value,'verified'=>false];if($iam!==null)foreach(['email','first_name','last_name'] as $field)if(($iam[$field]??null)!==null)$result[$field][]=['source'=>'account','reference'=>'iam:'.(int)$iam['id'],'value'=>$iam[$field],'verified'=>$field==='email'&&$iam['email_verified_at']!==null];if($contact!==null)foreach(['email','phone','first_name','last_name'] as $field)if(($contact[$field]??null)!==null)$result[$field][]=['source'=>'import','reference'=>'crm:'.(int)$contact['id'],'value'=>$contact[$field],'verified'=>false];return $result;
    }
    /** @param array<string,mixed> $fields */
    private function recordProvenance(int $siteId,int $entityId,string $entityType,array $fields,string $source,string $reference,int $confidence,bool $verified):void
    {
        foreach($fields as $field=>$value){if($value===null||$value==='')continue;$this->saleDb()->run('INSERT OR IGNORE INTO sale_identity_field_provenance(site_id,entity_type,entity_id,field_name,field_value_json,source_type,source_reference,confidence_score,is_verified) VALUES(?,?,?,?,?,?,?,?,?)',[$siteId,$entityType,$entityId,(string)$field,$this->json($value),$source,$reference,$confidence,$verified?1:0]);}
    }
    /** @return array<string,mixed> */
    private function identityProfile(int $siteId,int $userId):array
    {
        $user=$this->publicUser($userId);$account=$this->saleDb()->one("SELECT * FROM sale_customer_account_links WHERE site_id=? AND iam_user_id=? AND status='active'",[$siteId,$userId]);if($account===null)throw new SaleValidationException('sale.customer.merge_account_not_found');$contact=($account['crm_contact_id']??null)!==null?$this->contacts->find($siteId,(int)$account['crm_contact_id']):null;return ['type'=>'IamAccount','id'=>$userId,'fields'=>['email'=>$user['email'],'first_name'=>$user['first_name'],'last_name'=>$user['last_name'],'phone'=>$contact['phone']??$contact['mobile']??null,'crm_contact_id'=>$account['crm_contact_id'],'organization_id'=>$account['crm_company_id']],'orders'=>$this->saleDb()->all("SELECT order_id FROM sale_customer_order_links WHERE site_id=? AND iam_user_id=? AND status='active'",[$siteId,$userId]),'provenance'=>['email'=>'account','first_name'=>'account','last_name'=>'account','phone'=>'crm_contact']];
    }
    private function phone(string $value):string{$value=trim($value);if($value==='')return '';$prefix=str_starts_with($value,'+')?'+':'';return $prefix.preg_replace('/\D+/','',$value);}
    private function json(mixed $value):string{return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'null';}
    private function saleDb():Database{return $this->sale->database()??throw new SaleValidationException('sale.database_unavailable');}
}
