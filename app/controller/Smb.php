<?php
declare(strict_types=1);

namespace app\controller;

use think\Request;
use think\Response;
use think\facade\Cache;

class Smb {
    /* ================== 环境与账号配置（建议迁到 config/.env） ================== */
    private $env;
    private $baseUrl;

    // 这些只在服务端配置，前端不要传
    //private  $appKey     = '10134176';
    //private  $appSecret  = '090f24ae-ead3-4d78-8898-ac7824d78506';
    //private  $username   = 'admin_r9ngprgb7gqh9';
    //private  $password   = '3M966c6k';
    //private string $userSalt   = 'd05f76902ffb4b2ca5f4a7b94af5ddd4';

    private $appKey = '1002948';
    private $appSecret = '223998c6-5b76-4724-b5c9-666ff4215b45';
    private $username = 'admin_3sylog6ryv8cs';
    private $password = 'Aa2345678@';
    private $userSalt = '521c0eea19f04367ad20a3be12c9b4bc';

    private $orgAuthCode = null; // 第三方应用必填；企业内部应用可留空

    // 销方与票种固定（后端维护）
    //private string $sellerTaxNo         = '91310117134164422W'; // 你的销方税号
    private $sellerTaxNo = '338888888888SMB'; // 你的销方税号
    private $defaultInvoiceType = '1';    // 1=蓝票
    private $defaultTypeCode = '026';  // 电子普通发票
    private $defaultPriceTaxMark = '1';    // 1=含税；0=不含税（按需改）
    private $defaultTaxRate = '0'; // 默认税率（可被前端覆盖为 0 表示免税）

    // 缓存前缀
    private $cachePrefix = 'smb:auth:';

    public function __construct() {
        $this->env = 'sandbox';//env('SMB_ENV', 'sandbox'); // sandbox / prod
        $this->baseUrl = $this->env === 'prod' ? 'https://openapi.baiwang.com/router/rest' : 'https://sandbox-openapi.baiwang.com/router/rest';
    }

    /* ================== 通用工具 ================== */
    private function uuid(): string {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }

    private function nowMs(): string {
        return (string)round(microtime(true) * 1000);
    }

    // 支持 $body 传 array 或 string(JSON)
    private function httpPostJson(string $url, array $query, $body, int $timeout = 20): array {
        $q = http_build_query($query);
        $payload = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url . '?' . $q,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($err)
            throw new \RuntimeException("HTTP error: $err");
        $json = json_decode((string)$resp, true);
        if (!is_array($json))
            throw new \RuntimeException("Non-JSON response (HTTP $code): $resp");
        return $json;
    }


    private function httpPostForm(string $url, array $query, array $form, int $timeout = 20): array {
        $q = http_build_query($query);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url . '?' . $q,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => json_encode($form),
            CURLOPT_TIMEOUT => $timeout,
        ]);

        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($err)
            throw new \RuntimeException("HTTP error: $err");
        $json = json_decode((string)$resp, true);
        if (!is_array($json))
            throw new \RuntimeException("Non-JSON response (HTTP $code): $resp");
        return $json;
    }


    /* ================== 签名 ================== */
    private function flatten(array $arr, string $prefix = ''): array {
        $flat = [];
        foreach ($arr as $k => $v) {
            if ($k === 'sign')
                continue;
            $key = $prefix === '' ? (string)$k : $prefix . '.' . $k;
            if (is_array($v)) {
                $flat += $this->flatten($v, $key);
            } elseif (is_bool($v)) {
                $flat[$key] = $v ? 'true' : 'false';
            } elseif ($v === null) {
                $flat[$key] = '';
            } else {
                $flat[$key] = (string)$v;
            }
        }
        return $flat;
    }

    /**
     * 百望签名（version>2.0）：sign = UPPER(MD5( secret + concat(sorted(public k+v)) + bodyJsonNoCRLF + secret ))
     * - 排序范围：仅公共参数（method/appKey/token/timestamp/format/version/type/requestId...），排除 sign
     * - body：与实际请求发送的 JSON 完全一致（去掉 \r \n）
     * - 输出：大写 32 位十六进制
     */
    private function calcSignV6(array $public, string $bodyJson, string $secret): string {
        // 1) 排除 sign，按 ASCII 升序拼接 key+value
        unset($public['sign']);
        ksort($public, SORT_STRING);
        $concat = '';
        foreach ($public as $k => $v) {
            if ($v === null || $v === '')
                continue; // 与官方示例一致，空值可跳过
            $concat .= $k . (string)$v;
        }

        // 2) body 去掉换行符（保持与请求发送一致）
        $body = preg_replace("/\r|\n/", "", $bodyJson);

        // 3) secret + concat + body + secret → MD5(UTF-8) → UPPER HEX
        $raw = $secret . $concat . $body . $secret;
        return strtoupper(md5($raw));
    }


    /* ================== OAuth2：获取/刷新 Token ================== */
    private function passwordHash(string $plain, string $salt): string {
        // MD5(明文+盐) -> SHA-1（40位hex）
        return sha1(md5($plain . $salt));
    }

    private function cacheKey(?string $orgAuthCode): string {
        return $this->cachePrefix . ($orgAuthCode ?: 'internal') . ':' . $this->env;
    }

    public function getAccessToken(?string $orgAuthCode = null): array {

        $ck = $this->cacheKey($orgAuthCode);
        $cached = Cache::get($ck);
        if (is_array($cached) && !empty($cached['access_token']) && ($cached['expire_at'] ?? 0) > time() + 60) {
            return $cached;
        }
        $query = [
            'timestamp' => $this->nowMs(),
            'method' => 'baiwang.oauth.token',
            'grant_type' => 'password',
            'version' => '6.0',
            'client_id' => $this->appKey,
        ];
        $body = [
            'client_secret' => $this->appSecret,
            'username' => $this->username,
            'password' => $this->passwordHash($this->password, $this->userSalt),
        ];

        if ($orgAuthCode)
            $body['orgAuthCode'] = $orgAuthCode;


        $json = $this->httpPostJson($this->baseUrl, $query, $body);
        if (!($json['success'] ?? false)) {
            throw new \RuntimeException('Get token failed: ' . json_encode($json, JSON_UNESCAPED_UNICODE));
        }
        $resp = $json['response'] ?? [];
        $ttl = (int)($resp['expires_in'] ?? 0);
        $data = [
            'access_token' => $resp['access_token'] ?? '',
            'refresh_token' => $resp['refresh_token'] ?? '',
            'expire_at' => time() + max(60, $ttl - 60),
        ];
        Cache::set($ck, $data, $ttl ?: 8 * 3600);
        return $data;
    }

    private function refreshAccessToken(string $refreshToken, ?string $orgAuthCode = null): array {
        $query = [
            'method' => 'baiwang.oauth.token',
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->appKey,
            'timestamp' => $this->nowMs(),
            'version' => '6.0',
        ];
        $body = ['client_secret' => $this->appSecret];

        $json = $this->httpPostForm($this->baseUrl, $query, $body);
        if (!($json['success'] ?? false)) {
            throw new \RuntimeException('Refresh token failed: ' . json_encode($json, JSON_UNESCAPED_UNICODE));
        }
        $resp = $json['response'] ?? [];
        $ttl = (int)($resp['expires_in'] ?? 0);
        $data = [
            'access_token' => $resp['access_token'] ?? '',
            'refresh_token' => $resp['refresh_token'] ?? '',
            'expire_at' => time() + max(60, $ttl - 60),
        ];
        Cache::set($this->cacheKey($orgAuthCode), $data, $ttl ?: 8 * 3600);
        return $data;
    }

    /* ================== 统一 API 调用（带一次刷新重试） ================== */
    private function callApi(string $method, array $bizParams, ?string $orgAuthCode = null): array {
        $auth = $this->getAccessToken($orgAuthCode);

        // 公共参数（不要放 sign），这里的值最终会参与签名
        $public = [
            'method' => $method,
            'appKey' => $this->appKey,
            'token' => $auth['access_token'],
            'timestamp' => $this->nowMs(),
            'format' => 'json',
            'version' => '6.0',
            'type' => 'sync',
            'requestId' => $this->uuid(),
        ];

        // 用“最终将发送出去”的 JSON 字符串计算签名（和请求体保持一致）
        $bodyJson = json_encode($bizParams, JSON_UNESCAPED_UNICODE);
        $public['sign'] = $this->calcSignV6($public, $bodyJson, $this->appSecret);

        // 发送也用同一个 bodyJson，确保签名源串 == 实际请求
        $json = $this->httpPostJson($this->baseUrl, $public, $bodyJson);

        // token 失效自动刷新一次（如需）
        if (($json['success'] ?? true) === false && stripos(json_encode($json, JSON_UNESCAPED_UNICODE), 'token') !== false) {
            $auth = $this->refreshAccessToken($auth['refresh_token'] ?? '', $orgAuthCode);
            $public['token'] = $auth['access_token'];
            $public['sign'] = $this->calcSignV6($public, $bodyJson, $this->appSecret);
            $json = $this->httpPostJson($this->baseUrl, $public, $bodyJson);
        }
        return $json;
    }

    /* ================== 1) 开具发票（支持单位/免税/优先使用三金额） ================== */
    public function invoice(Request $request): Response {
        // 必填：购方抬头；金额来源：你可传三金额或传 amount/单价/数量
        $buyerName = trim((string)$request->param('buyerName', ''));
        if ($buyerName === '') {
            return json(['success' => false, 'message' => 'buyerName(购方抬头) 必填'], 400);
        }

        // 幂等键：建议=微信 transaction_id；不传则自动生成
        $orderNo = (string)$request->param('orderNo', 'TP6' . date('YmdHis') . mt_rand(100, 999));

        // 税率（可覆盖默认）；免税请传 "0"
        $taxRate = (string)$request->param('goodsTaxRate', $this->defaultTaxRate);

        // 单位（如：年）
        $goodsUnit = '年';//(string)$request->param('goodsUnit', '');

        // 价税标志：默认走后端配置（含税模式更贴近“实付金额”）
        $priceTaxMark = (string)$request->param('priceTaxMark', $this->defaultPriceTaxMark);

        // —— 金额优先策略：若传了三金额，则直接使用；否则再按 amount/单价*数量 计算 ——
        $tp = $request->param('goodsTotalPrice', null);      // 不含税金额
        $tt = $request->param('goodsTotalTax', null);        // 税额
        $tpt = $request->param('goodsTotalPriceTax', null);   // 含税金额

        $quantity = (float)$request->param('goodsQuantity', 1);
        $price = $request->param('goodsPrice', null); // 单价（与 priceTaxMark 一致）
        $amount = $request->param('amount', null);     // 你也可以只传一个总额（实付金额），常用于含税模式

        // 计算三金额
        $noTax = $taxAmt = $withTax = null;

        if ($tp !== null && $tt !== null && $tpt !== null) {
            // 直接用你传的三金额
            $noTax = round((float)$tp, 2);
            $taxAmt = round((float)$tt, 2);
            $withTax = round((float)$tpt, 2);
        } else {
            if ($price !== null) {
                $lineTotal = round((float)$price * ($quantity > 0 ? $quantity : 1), 2);
                if ($priceTaxMark === '1') {
                    // 含税单价
                    if ($taxRate === '0' || (float)$taxRate == 0.0) {
                        $withTax = $lineTotal;
                        $noTax = $lineTotal;
                        $taxAmt = 0.0;
                    } else {
                        $withTax = $lineTotal;
                        $noTax = round($withTax / (1 + (float)$taxRate), 2);
                        $taxAmt = round($withTax - $noTax, 2);
                    }
                } else { // 不含税单价
                    if ($taxRate === '0' || (float)$taxRate == 0.0) {
                        $noTax = $lineTotal;
                        $taxAmt = 0.0;
                        $withTax = $lineTotal;
                    } else {
                        $noTax = $lineTotal;
                        $taxAmt = round($noTax * (float)$taxRate, 2);
                        $withTax = round($noTax + $taxAmt, 2);
                    }
                }
            } elseif ($amount !== null) {
                $amt = round((float)$amount, 2);
                if ($priceTaxMark === '1') {
                    // 总额视为含税金额
                    if ($taxRate === '0' || (float)$taxRate == 0.0) {
                        $withTax = $amt;
                        $noTax = $amt;
                        $taxAmt = 0.0;
                    } else {
                        $withTax = $amt;
                        $noTax = round($withTax / (1 + (float)$taxRate), 2);
                        $taxAmt = round($withTax - $noTax, 2);
                    }
                } else {
                    // 总额视为不含税金额
                    if ($taxRate === '0' || (float)$taxRate == 0.0) {
                        $noTax = $amt;
                        $taxAmt = 0.0;
                        $withTax = $amt;
                    } else {
                        $noTax = $amt;
                        $taxAmt = round($noTax * (float)$taxRate, 2);
                        $withTax = round($noTax + $taxAmt, 2);
                    }
                }
            } else {
                return json([
                    'success' => false,
                    'message' => '请传入三金额(优先)或 amount / goodsPrice+goodsQuantity 之一'
                ], 400);
            }
        }

        // 商品名与备注、交付
        $goodsName = '墓穴维护费';
        $remarks = (string)$request->param('remarks', '');
        $pushEmail = (string)$request->param('pushEmail', '');
        $pushPhone = (string)$request->param('pushPhone', '');

        // 免税自动补齐（你也可显式传 freeTaxMark / preferentialMark / vatSpecialManagement 覆盖）
        $freeTaxMark = (string)$request->param('freeTaxMark', '');
        $preferentialMark = (string)$request->param('preferentialMark', '');
        $vatSpecialManagement = (string)$request->param('vatSpecialManagement', '');
        if ($taxRate === '0' || (float)$taxRate == 0.0) {
            if ($freeTaxMark === '')
                $freeTaxMark = '1';      // 1=免税
            if ($preferentialMark === '')
                $preferentialMark = '1'; // 使用优惠政策
            if ($vatSpecialManagement === '')
                $vatSpecialManagement = '免税';
        }

        // 组装业务参数（固定的税号/票种/价税标志在后端）
        $detail = [
            'goodsName' => $goodsName,
            'goodsTaxRate' => $taxRate,
            'invoiceLineNature' => '0',
            'goodsQuantity' => (string)($quantity > 0 ? $quantity : 1),
            // 单价与价税标志保持一致：若未传单价，这里用整张金额映射到单价，避免平台自动推算带来误差
            'goodsPrice' => $price !== null ? (string)$price : ($priceTaxMark === '1' ? (string)$withTax : (string)$noTax),
            'goodsTotalPrice' => number_format($noTax, 2, '.', ''),
            'goodsTotalTax' => number_format($taxAmt, 2, '.', ''),
            'goodsTotalPriceTax' => number_format($withTax, 2, '.', ''),
        ];
        if ($goodsUnit !== '')
            $detail['goodsUnit'] = $goodsUnit; // 单位（如：年）
        if ($freeTaxMark !== '')
            $detail['freeTaxMark'] = $freeTaxMark;
        if ($preferentialMark !== '')
            $detail['preferentialMark'] = $preferentialMark;
        if ($vatSpecialManagement !== '')
            $detail['vatSpecialManagement'] = $vatSpecialManagement;

        $biz = [
            'orderNo' => $orderNo,
            'orderDateTime' => date('Y-m-d H:i:s'),
            'taxNo' => $this->sellerTaxNo,
            'buyerName' => $buyerName,
            'invoiceType' => $this->defaultInvoiceType,
            'invoiceTypeCode' => $this->defaultTypeCode,
            'priceTaxMark' => $priceTaxMark,
            'invoiceDetailList' => [$detail],
        ];
        if ($remarks !== '')
            $biz['remarks'] = $remarks;
        if ($pushEmail !== '')
            $biz['pushEmail'] = $pushEmail;
        if ($pushPhone !== '')
            $biz['pushPhone'] = $pushPhone;

        $json = $this->callApi('baiwang.s.outputinvoice.invoice', $biz);
        return json($json);
    }

    /* ================== 2) 分页查询 ================== */
    public function query(Request $request): Response {
        $biz = array_filter([
            'taxNo' => $request->param('taxNo', $this->sellerTaxNo), // 若你更想固定，也可直接用 $this->sellerTaxNo
            'serialNo' => $request->param('serialNo', ''),
            'orderNo' => $request->param('orderNo', ''),
            'invoiceCode' => $request->param('invoiceCode', ''),
            'invoiceNo' => $request->param('invoiceNo', ''),
            'digitInvoiceNo' => $request->param('digitInvoiceNo', ''),
            'dateType' => $request->param('dateType', ''),
            'beginDate' => $request->param('beginDate', ''),
            'endDate' => $request->param('endDate', ''),
            'pageNo' => $request->param('pageNo', '1'),
            'pageSize' => $request->param('pageSize', '10'),
            'detailMark' => $request->param('detailMark', '0'),
        ], fn($v) => $v !== '');
        $json = $this->callApi('baiwang.s.outputinvoice.queryPage', $biz);
        return json($json);
    }

    /* ================== 3) 交付（补发） ================== */
    public function push(Request $request): Response {
        $biz = ['taxNo' => $request->param('taxNo', $this->sellerTaxNo)];
        if ($request->param('digitInvoiceNo')) {
            $biz['digitInvoiceNo'] = $request->param('digitInvoiceNo');
        } else {
            $biz['invoiceCode'] = $request->param('invoiceCode');
            $biz['invoiceNo'] = $request->param('invoiceNo');
        }
        if ($email = $request->param('pushEmail'))
            $biz['pushEmail'] = $email;
        if ($phone = $request->param('pushPhone'))
            $biz['pushPhone'] = $phone;

        $json = $this->callApi('baiwang.s.outputinvoice.push', $biz);
        return json($json);
    }

    /* ================== 4) 作废 ================== */
    public function invalid(Request $request): Response {
        $code = $request->param('invoiceCode');
        $no = $request->param('invoiceNo');
        if (!$code || !$no) {
            return json(['success' => false, 'message' => 'invoiceCode & invoiceNo 必填'], 400);
        }
        $biz = [
            'taxNo' => $request->param('taxNo', $this->sellerTaxNo),
            'invoiceCode' => $code,
            'invoiceNo' => $no,
            'invalidOperator' => $request->param('invalidOperator', '系统'),
        ];
        if ($x = $request->param('serialNo', ''))
            $biz['serialNo'] = $x;
        if ($x = $request->param('orderNo', ''))
            $biz['orderNo'] = $x;

        $json = $this->callApi('baiwang.s.outputinvoice.invalid', $biz);
        return json($json);
    }
}
