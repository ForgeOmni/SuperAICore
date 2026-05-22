<?php

declare(strict_types=1);

namespace SuperAICore\Services\OAuth\Refreshers;

use GuzzleHttp\Client;
use SuperAICore\Models\AiProviderAccount;
use SuperAICore\Services\OAuth\OAuthRefresherInterface;

/**
 * OpenAI Codex CLI OAuth refresh — VERIFIED against the upstream source.
 *
 * Codex CLI uses ChatGPT-account OAuth or API key. The OAuth flow:
 *
 *   POST https://auth.openai.com/oauth/token
 *   {
 *     "grant_type":    "refresh_token",
 *     "refresh_token": "<token>",
 *     "client_id":     "app_EMoamEEZ73f0CkXaXp7hrann",   ← Codex's public client id
 *     "scope":         "openid profile email"
 *   }
 *
 * Source: openai/codex codex-rs/login/src/auth/manager.rs:
 *   const REFRESH_TOKEN_URL: &str = "https://auth.openai.com/oauth/token";
 *   pub const CLIENT_ID: &str = "app_EMoamEEZ73f0CkXaXp7hrann";
 *
 * auth_payload shape:
 *   {
 *     "access_token":  "...",
 *     "refresh_token": "...",
 *     "id_token":      "...",          (optional, OIDC)
 *     "expires_at":    1730000000,
 *     "token_url":     "..."           (optional override, eg enterprise)
 *   }
 *
 * For API-key accounts (no refresh_token), returns false — there's
 * nothing to refresh.
 */
final class CodexRefresher implements OAuthRefresherInterface
{
    private const CLIENT_ID = 'app_EMoamEEZ73f0CkXaXp7hrann';

    public function backendName(): string
    {
        return 'codex_cli';
    }

    public function refresh(AiProviderAccount $account): bool
    {
        $payload = $account->auth_payload ?? [];
        if (!is_array($payload)) return false;
        $refreshToken = (string) ($payload['refresh_token'] ?? '');
        if ($refreshToken === '') return false;

        $tokenUrl = (string) ($payload['token_url'] ?? 'https://auth.openai.com/oauth/token');
        $clientId = (string) ($payload['client_id'] ?? self::CLIENT_ID);

        try {
            $client = new Client(['timeout' => 20]);
            $resp = $client->post($tokenUrl, [
                'json' => [
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $refreshToken,
                    'client_id'     => $clientId,
                    'scope'         => 'openid profile email',
                ],
                'headers' => ['Accept' => 'application/json'],
            ]);
            $body = json_decode((string) $resp->getBody(), true);
            if (!is_array($body) || empty($body['access_token'])) return false;

            $payload['access_token'] = (string) $body['access_token'];
            if (!empty($body['refresh_token'])) {
                $payload['refresh_token'] = (string) $body['refresh_token'];
            }
            $payload['expires_at'] = time() + (int) ($body['expires_in'] ?? 3600);
            $account->auth_payload = $payload;
            $account->save();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
