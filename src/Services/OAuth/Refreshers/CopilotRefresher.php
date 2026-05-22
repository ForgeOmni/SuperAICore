<?php

declare(strict_types=1);

namespace SuperAICore\Services\OAuth\Refreshers;

use GuzzleHttp\Client;
use SuperAICore\Models\AiProviderAccount;
use SuperAICore\Services\OAuth\OAuthRefresherInterface;

/**
 * GitHub Copilot session-token refresh.
 *
 * TODO: verify endpoint shape. The `/copilot_internal/v2/token` URL is
 * what every reverse-engineered Copilot client uses (including
 * neovim-cmp-copilot, official VS Code Copilot extension), but GitHub
 * has never published it in its formal API docs. The endpoint is
 * stable in practice; treat changes as breaking.
 *
 * Copilot's flow is unusual: the GitHub OAuth access token is
 * long-lived but you exchange it for a SHORT-lived Copilot session
 * token via:
 *
 *   GET https://api.github.com/copilot_internal/v2/token
 *   Authorization: token <GITHUB_OAUTH_TOKEN>
 *
 * The response contains `token` (session) + `expires_at`. We don't
 * refresh the GitHub OAuth token here (it doesn't expire on the
 * normal-user time scale); we refresh the session token.
 *
 * auth_payload shape:
 *
 *   {
 *     "github_oauth_token": "ghu_...",  // long-lived; persists across refreshes
 *     "session_token":      "...",
 *     "expires_at":         1730000000
 *   }
 */
final class CopilotRefresher implements OAuthRefresherInterface
{
    public function backendName(): string
    {
        return 'copilot_cli';
    }

    public function refresh(AiProviderAccount $account): bool
    {
        $payload = $account->auth_payload ?? [];
        if (!is_array($payload)) return false;
        $ghToken = (string) ($payload['github_oauth_token'] ?? '');
        if ($ghToken === '') return false;

        try {
            $client = new Client(['timeout' => 20]);
            $resp = $client->get('https://api.github.com/copilot_internal/v2/token', [
                'headers' => [
                    'Authorization' => 'token ' . $ghToken,
                    'Accept'        => 'application/json',
                    'User-Agent'    => 'super-ai-core/oauth-refresh',
                ],
            ]);
            $body = json_decode((string) $resp->getBody(), true);
            if (!is_array($body) || empty($body['token'])) return false;

            $payload['session_token'] = (string) $body['token'];
            // expires_at is a UNIX timestamp in Copilot's response
            $payload['expires_at'] = (int) ($body['expires_at'] ?? (time() + 1800));
            $account->auth_payload = $payload;
            $account->save();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
