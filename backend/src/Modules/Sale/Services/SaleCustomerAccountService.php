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

    /** @return array<string,mixed> */
    public function mergeAccounts(int $siteId, int $sourceUserId, int $targetUserId, int $actorId, string $reason): array
    {
        if ($sourceUserId === $targetUserId || $actorId < 1 || trim($reason)==='') throw new SaleValidationException('sale.customer.merge_invalid');
        $source = $this->saleDb()->one("SELECT id FROM sale_customer_account_links WHERE site_id=? AND iam_user_id=? AND status='active'", [$siteId, $sourceUserId]);
        $target = $this->saleDb()->one("SELECT id FROM sale_customer_account_links WHERE site_id=? AND iam_user_id=? AND status='active'", [$siteId, $targetUserId]);
        if ($source === null || $target === null) {
            throw new SaleValidationException('sale.customer.merge_account_not_found');
        }
        return $this->saleDb()->transaction(function(Database $db) use($siteId,$sourceUserId,$targetUserId,$actorId,$reason):array {
            $before = ['source_orders'=>$db->all('SELECT order_id FROM sale_customer_order_links WHERE site_id=? AND iam_user_id=? AND status=\'active\'',[$siteId,$sourceUserId]),'target_orders'=>$db->all('SELECT order_id FROM sale_customer_order_links WHERE site_id=? AND iam_user_id=? AND status=\'active\'',[$siteId,$targetUserId])];
            $db->run('UPDATE sale_customer_order_links SET iam_user_id=?,link_source=\'merge\',linked_by_iam_user_id=? WHERE site_id=? AND iam_user_id=?',[$targetUserId,$actorId,$siteId,$sourceUserId]);
            $db->run('UPDATE sale_customer_addresses SET iam_user_id=? WHERE site_id=? AND iam_user_id=?',[$targetUserId,$siteId,$sourceUserId]);
            $db->run("UPDATE sale_customer_account_links SET status='merged',merged_into_iam_user_id=?,updated_at=CURRENT_TIMESTAMP WHERE site_id=? AND iam_user_id=?",[$targetUserId,$siteId,$sourceUserId]);
            $after=['target_orders'=>$db->all('SELECT order_id FROM sale_customer_order_links WHERE site_id=? AND iam_user_id=? AND status=\'active\'',[$siteId,$targetUserId])];
            $db->run('INSERT INTO sale_customer_merge_audit(site_id,source_iam_user_id,target_iam_user_id,reason,before_json,after_json,actor_iam_user_id) VALUES(?,?,?,?,?,?,?)',[$siteId,$sourceUserId,$targetUserId,trim($reason),json_encode($before),json_encode($after),$actorId]);
            $this->iam->run("UPDATE iam_customer_site_accounts SET status='merged',merged_into_user_id=?,updated_at=CURRENT_TIMESTAMP WHERE user_id=? AND site_id=?",[$targetUserId,$sourceUserId,$siteId]);
            return $db->one('SELECT * FROM sale_customer_merge_audit WHERE id=?',[(int)$db->lastInsertId()]) ?? [];
        });
    }

    /** @param array<string,mixed>|null $contact */
    private function linkOrder(int $siteId,int $userId,int $orderId,int $proofId,?array $contact,string $source):void
    {
        $existing = $this->saleDb()->one('SELECT iam_user_id FROM sale_customer_order_links WHERE order_id=?', [$orderId]);
        if ($existing !== null && (int) $existing['iam_user_id'] !== $userId) {
            throw new SaleValidationException('sale.customer.order_already_claimed');
        }
        $this->saleDb()->run('INSERT INTO sale_customer_account_links(site_id,iam_user_id,crm_company_id,crm_contact_id,status,linked_by) VALUES(?,?,?,?,\'active\',?) ON CONFLICT(site_id,iam_user_id) DO UPDATE SET crm_company_id=COALESCE(excluded.crm_company_id,crm_company_id),crm_contact_id=COALESCE(excluded.crm_contact_id,crm_contact_id),status=\'active\',updated_at=CURRENT_TIMESTAMP',[$siteId,$userId,$contact['company_id']??null,$contact['id']??null,$source]);
        $this->saleDb()->run('INSERT INTO sale_customer_order_links(site_id,order_id,iam_user_id,claim_proof_id,link_source) VALUES(?,?,?,?,?) ON CONFLICT(order_id) DO NOTHING',[$siteId,$orderId,$userId,$proofId,$source]);
    }

    /** @param array<string,mixed> $identity @param array<string,mixed> $order @return array<string,mixed> */
    private function createOrLinkContact(int $siteId,int $userId,array $identity,array $order):array
    {
        $linked = $this->contacts->activeByIamUser($siteId, $userId);
        if ($linked !== null) {
            return $linked;
        }
        $contactId=(int)($order['customer_contact_id']??0);
        $contact=$contactId>0?$this->contacts->find($siteId,$contactId):null;
        if($contact!==null){
            if(($contact['iam_user_id']??null)!==null && (int)$contact['iam_user_id']!==$userId) throw new SaleValidationException('sale.customer.crm_contact_already_linked');
            $contact=$this->contacts->update($siteId,$contactId,['iam_user_id'=>$userId],$userId)??$contact;
        } else {
            $company=$this->companies->ensureSystemIndividualsCompany($siteId,$userId);
            $contact=$this->contacts->create($siteId,['company_id'=>(int)$company['id'],'iam_user_id'=>$userId,'first_name'=>$identity['first_name']??null,'last_name'=>$identity['last_name']??null,'email'=>$identity['email']??null,'phone'=>$identity['phone']??null,'status'=>'client'],$userId);
        }
        $email=$this->email((string)($identity['email']??''));
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
    private function saleDb():Database{return $this->sale->database()??throw new SaleValidationException('sale.database_unavailable');}
}
