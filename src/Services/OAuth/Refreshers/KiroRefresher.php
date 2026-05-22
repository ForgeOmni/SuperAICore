<?php

declare(strict_types=1);

namespace SuperAICore\Services\OAuth\Refreshers;

use GuzzleHttp\Client;
use SuperAICore\Models\AiProviderAccount;
use SuperAICore\Services\OAuth\OAuthRefresherInterface;

/**
 * Kiro CLI OAuth refresh.
 *
 * TODO: verify — Kiro is AWS's coding-agent CLI and its OAuth endpoint
 * is NOT publicly documented. The `kiro.dev/oauth/token` URL below is a
 * best-guess placeholder; the real endpoint may live under
 * `auth.kiro.dev`, `api.kiro.aws`, or `oidc.<region>.amazonaws.com`
 * depending on Kiro's AWS-side wiring. Until verified, operators MUST
 * provide token_url in auth_payload; the default WILL 404 in
 * production. The refresher returns false on 4xx so the daemon falls
 * back to the CLI global refresh cleanly.
 *
 * Kiro is AWS's coding-agent CLI; its session token flow mirrors a
 * standard OAuth refresh-token grant. auth_payload shape:
 *
 *   {
 *     "access_token":  "...",
 *     "refresh_token": "...",
 *     "expires_at":    1730000000,
 *     "token_url":     "https://api.kiro.dev/oauth/token"  (optional)
 *   }
 *
 * Kiro's token endpoint is not as publicly documented as Anthropic's
 * or GitHub's; the token_url override exists so an operator can point
 * at the right host for their region / channel. Defaults to the URL
 * the CLI uses in its public configuration.
 */
final class KiroRefresher implements OAuthRefresherInterface
{
    public function backendName(): string
    {
        return 'kiro_cli';
    }

    public function refresh(AiProviderAccount $account): bool
    {
        $payload = $account->auth_payload ?? [];
        if (!is_array($payload)) return false;
        $refreshToken = (string) ($payload['refresh_token'] ?? '');
        if ($refreshToken === '') return false;

        $tokenUrl = (string) ($payload['token_url'] ?? 'https://api.kiro.dev/oauth/token');

        try {
            $client = new Client(['timeout' => 20]);
            $resp = $client->post($tokenUrl, [
                'json' => [
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $refreshToken,
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
