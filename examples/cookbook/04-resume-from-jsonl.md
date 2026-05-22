# 04 — Resume a session from another CLI's jsonl

Goal: pull a Claude Code or Codex session transcript off disk and seed a new
SuperAICore-backed chat with it.

## Why

You ran a Claude Code session yesterday, hit context limit, want to continue
in Codex (or vice versa). SuperAICore 0.9.7's HarnessImporter wraps SuperAgent
SDK's `ClaudeCodeImporter` / `CodexImporter` so the import is one call.

Gated by `AI_CORE_RESUME_ENABLED=true` — off by default on shared machines.

## Enable

```dotenv
# .env
AI_CORE_RESUME_ENABLED=true
```

```php
// In your service provider — wire what happens after the importer returns.
config(['super-ai-core.resume.on_load' => function ($harness, $sessionId, $messages) {
    // Persist as a new chat row, return the URL the dashboard should redirect to.
    $chat = ChatSession::create([
        'source_harness' => $harness,
        'source_session_id' => $sessionId,
        'messages' => $messages,  // SDK Message[] — serialize as needed
    ]);
    return [
        'url' => route('chat.show', $chat),
    ];
}]);
```

## List available sessions

```bash
# Claude Code transcripts under ~/.claude/projects/<hash>/<uuid>.jsonl
curl -s http://localhost/super-ai-core/resume/claude_code

# Codex transcripts under ~/.codex/sessions/**/*.jsonl
curl -s http://localhost/super-ai-core/resume/codex
```

Response:

```json
[
  {
    "session_id": "abc123...",
    "project": "/Users/me/Projects/SuperTeam",
    "started_at": "2026-05-22T10:11:12+00:00",
    "message_count": 47,
    "tokens_estimated": 35200
  },
  ...
]
```

## Load a session

```bash
curl -X POST http://localhost/super-ai-core/resume/claude_code/load \
  -H 'Content-Type: application/json' \
  -d '{"session_id": "abc123..."}'
```

Response (when `on_load` is wired):

```json
{
  "ok": true,
  "host_payload": { "url": "/chat/42" },
  "transcript": null
}
```

Without `on_load` the response includes the full Message[] JSON so the host
can do whatever it likes with it.

## Code: invoke the resolver directly

```php
use SuperAICore\Services\HarnessSessionResolver;

$resolver = app(HarnessSessionResolver::class);

$sessions = $resolver->listSessions('claude_code');
// list<array{session_id, project, started_at, message_count, tokens_estimated}>

$result = $resolver->loadSession('claude_code', $sessions[0]['session_id']);
// array{ok: bool, messages: list<Message>, host_payload?: mixed}
```

## Security note

Importer can see every operator's `~/.claude` / `~/.codex` history. On
multi-tenant deployments leave `AI_CORE_RESUME_ENABLED=false` unless you have
authorization checks in the controller or middleware.

## See also

- SuperAICore CHANGELOG.md 0.9.7 entry
- SuperAgent SDK `HarnessImporter` source
