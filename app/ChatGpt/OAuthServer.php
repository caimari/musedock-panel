<?php
namespace MuseDockPanel\ChatGpt;

/** Authorization-code + mandatory S256 PKCE; manually registered confidential client. */
final class OAuthServer
{
    public const SCOPE = 'musedock:read';
    public function __construct(public readonly OAuthStore $store, private readonly bool $registeredRedirect = false, private readonly array $supportedScopes = [self::SCOPE]) {}
    public function enabled(): bool { return $this->store->get('enabled') === '1'; }
    public function issuer(): string { return $this->store->get('issuer'); }
    public function resource(): string { return $this->store->get('resource'); }
    public static function httpsUrl(string $url, bool $origin = false): bool
    {
        $p = parse_url($url);
        return is_array($p) && ($p['scheme'] ?? '') === 'https' && !empty($p['host'])
            && !isset($p['user'], $p['pass']) && !isset($p['user']) && !isset($p['query']) && !isset($p['fragment'])
            && (!$origin || !isset($p['path']) || $p['path'] === '');
    }
    public static function validRedirect(string $url): bool
    {
        // Copy the exact callback from ChatGPT. No wildcard redirects or other hosts.
        return preg_match('#^https://chatgpt\.com/(?:connector_platform_oauth_redirect|connector/oauth/[A-Za-z0-9_-]+)$#D', $url) === 1;
    }
    public function metadata(): array
    {
        return ['issuer' => $this->issuer(), 'authorization_endpoint' => $this->issuer() . '/api/chatgpt/oauth/authorize',
            'token_endpoint' => $this->issuer() . '/api/chatgpt/oauth/token',
            'revocation_endpoint' => $this->issuer() . '/api/chatgpt/oauth/revoke',
            'response_types_supported' => ['code'], 'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
            'code_challenge_methods_supported' => ['S256'], 'scopes_supported' => $this->supportedScopes,
            'authorization_response_iss_parameter_supported' => true];
    }
    public function protectedResource(): array
    {
        return ['resource' => $this->resource(), 'authorization_servers' => [$this->issuer()],
            'scopes_supported' => $this->supportedScopes, 'bearer_methods_supported' => ['header']];
    }
    public function normalizeScope(mixed $value): string
    {
        if (!is_string($value) || strlen($value)>256) throw new \RuntimeException('invalid_scope');
        $scopes=preg_split('/\s+/',trim($value),-1,PREG_SPLIT_NO_EMPTY);
        if (!$scopes || count($scopes)!==count(array_unique($scopes)) || !in_array(self::SCOPE,$scopes,true)
            || array_diff($scopes,$this->supportedScopes)) throw new \RuntimeException('invalid_scope');
        if (in_array('musedock:dns',$scopes,true) && !in_array('musedock:write',$scopes,true)) throw new \RuntimeException('invalid_scope');
        sort($scopes);return implode(' ',$scopes);
    }
    public function request(array $p): string
    {
        if (!$this->enabled()) throw new \RuntimeException('access_denied');
        foreach (['client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'resource', 'state'] as $key) {
            if (!isset($p[$key]) || !is_string($p[$key]) || strlen($p[$key]) > 2048) throw new \RuntimeException('invalid_request');
        }
        if (!hash_equals($this->store->get('client_id'), $p['client_id'])
            || !hash_equals($this->store->get('redirect_uri'), $p['redirect_uri'])
            || (!$this->registeredRedirect && !self::validRedirect($p['redirect_uri'])) || $p['response_type'] !== 'code'
            || $p['code_challenge_method'] !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $p['code_challenge'])
            || $p['state'] === '' || !hash_equals($this->resource(), $p['resource'])) throw new \RuntimeException('invalid_request');
        $p['scope']=$this->normalizeScope($p['scope']??self::SCOPE);
        return $this->store->issue('request', array_intersect_key($p, array_flip(['client_id','redirect_uri','resource','state','code_challenge','scope'])), 600);
    }
    public function approve(string $request, int $userId, bool $allow): string
    {
        if (!$this->enabled()) throw new \RuntimeException('access_denied');
        $p = $this->store->consume($request, 'request');
        if (!$p) throw new \RuntimeException('invalid_request');
        $q = ['state' => $p['state'], 'iss' => $this->issuer()];
        if ($allow) $q['code'] = $this->store->issue('code', $p + ['user_id' => $userId], 120);
        else $q['error'] = 'access_denied';
        return $p['redirect_uri'] . (str_contains($p['redirect_uri'], '?') ? '&' : '?') . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    }
    public function authenticateClient(string $id, string $secret): bool
    {
        return $id !== '' && $secret !== '' && hash_equals($this->store->get('client_id'), $id)
            && hash_equals($this->store->get('client_secret_hash'), hash('sha256', $secret));
    }
    public function exchange(array $p): array
    {
        if (!$this->enabled()) throw new \RuntimeException('access_denied');
        if (($p['grant_type'] ?? '') === 'refresh_token') return $this->refresh($p);
        if (($p['grant_type'] ?? '') !== 'authorization_code') throw new \RuntimeException('unsupported_grant_type');
        $code = (string)($p['code'] ?? ''); $grant = $this->store->find($code, 'code');
        $verifier = (string)($p['code_verifier'] ?? '');
        if (!$grant || !preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier)
            || !hash_equals($grant['code_challenge'], rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='))
            || !hash_equals($grant['redirect_uri'], (string)($p['redirect_uri'] ?? ''))
            || !hash_equals($grant['resource'], (string)($p['resource'] ?? ''))
            || !hash_equals($grant['client_id'], $this->store->get('client_id'))) throw new \RuntimeException('invalid_grant');
        if (!$this->store->consume($code, 'code')) throw new \RuntimeException('invalid_grant');
        return $this->tokens(['user_id' => $grant['user_id'], 'client_id' => $grant['client_id'],
            'resource' => $grant['resource'], 'scope' => $this->normalizeScope($grant['scope']??self::SCOPE),
            'family' => bin2hex(random_bytes(16)), 'refresh_until' => time() + 604800]);
    }
    private function tokens(array $grant): array
    {
        return ['access_token' => $this->store->issue('access', $grant, 3600), 'token_type' => 'Bearer',
            'expires_in' => 3600, 'scope' => $grant['scope'],
            'refresh_token' => $this->store->issue('refresh', $grant, max(1, $grant['refresh_until'] - time()))];
    }
    private function refresh(array $p): array
    {
        $value = (string)($p['refresh_token'] ?? '');
        if ($replayed = $this->store->usedRefresh($value)) {
            $this->store->revokeFamily($replayed['family']); throw new \RuntimeException('invalid_grant');
        }
        $grant = $this->store->find($value, 'refresh');
        if (!$grant || $grant['refresh_until'] <= time() || !hash_equals($grant['resource'], (string)($p['resource'] ?? ''))
            || !hash_equals($grant['client_id'], $this->store->get('client_id'))
            || (isset($p['scope']) && $this->normalizeScope($p['scope']) !== ($grant['scope']??self::SCOPE))) throw new \RuntimeException('invalid_grant');
        if (!$this->store->consume($value, 'refresh')) throw new \RuntimeException('invalid_grant');
        $grant['scope']=$this->normalizeScope($grant['scope']??self::SCOPE);
        return $this->tokens($grant);
    }

    public function verify(string $token): ?array
    {
        if (!$this->enabled()) return null;
        $p = $this->store->find($token, 'access');
        try { if ($p) $this->normalizeScope($p['scope']??''); } catch (\Throwable) { return null; }
        return $p && hash_equals($this->resource(), $p['resource'] ?? '')
            && hash_equals($this->store->get('client_id'), $p['client_id'] ?? '') ? $p : null;
    }
}
