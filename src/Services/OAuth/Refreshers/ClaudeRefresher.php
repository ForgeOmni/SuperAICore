<?php

declare(strict_types=1);

namespace SuperAICore\Services\OAuth\Refreshers;

use GuzzleHttp\Client;
use SuperAICore\Models\AiProviderAccount;
use SuperAICore\Services\OAuth\OAuthRefresherInterface;

/**
 * Anthropic / Claude Code OAuth refresh.
 *
 * TODO: verify — the token endpoint URL below is what the public Claude
 * Code OAuth flow uses based on standard OAuth 2.0 conventions and
 * community-published flows. Anthropic hasn't published a canonical
 * docs page at the time this was written. Operators on enterprise /
 * Bedrock / Vertex tenants MUST set token_url in auth_payload to point
 * at the correct host for their setup.
 *
 * Claude Code's OAuth flow follows the standard authorization-code +
 * refresh-token pattern. The relevant fields in auth_payload:
 *
 *   {
 *     "access_token":  "eyJ...",
 *     "refresh_token": "rt_...",
 *     "expires_at":    1730000000,
 *     "client_id":     "anthropic-claude-cli",  (optional, defaults below)
 *     "token_url":     "https://...",            (optional override)
 *   }
 *
 * Endpoint defaults to console.anthropic.com's token URL; override
 * via auth_payload.token_url for Bedrock / enterprise tenants.
 *
 * On success: writes back access_token + expires_at + (potentially
 * rotated) refresh_token, and saves the model. The refresher is
 * idempotent — repeated calls within the validity window are safe;
 * the endpoint will hand back the same access_token + a new short
 * window.
 */
final class ClaudeRefresher implements OAuthRefresherInterface
{
    public function backendName(): string
    {
        return 'claude_cli';
    }

    public function refresh(AiProviderAccount $account): bool
    {
        $payload = $account->auth_payload ?? [];
        if (!is_array($payload)) return false;
        $refreshToken = (string) ($payload['refresh_token'] ?? '');
        if ($refreshToken === '') return false;

        $tokenUrl = (string) ($payload['token_url'] ?? 'https://console.anthropic.com/v1/oauth/token');
        $clientId = (string) ($payload['client_id'] ?? 'anthropic-claude-cli');

        try {
            $client = new Client(['timeout' => 20]);
            $resp = $client->post($tokenUrl, [
                'json' => [
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $refreshToken,
                    'client_id'     => $clientId,
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
