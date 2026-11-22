# DLQ diagnostic agent

## Modes

`DlqDoctorAgentInterface` separates diagnostics and patch suggestions from policy and message processing. The default `APP_DEMO_DETERMINISTIC=true` mode is offline and deterministic: it proposes required fields with explicit JSON Schema `default` values when their current values differ, otherwise it returns a diagnosis without a patch. It never calls an external provider.

Set `APP_DEMO_DETERMINISTIC=false` to use the Symfony AI adapter. The provider is selected by `DLQ_AI_PROVIDER`; the model is selected separately by `DLQ_AI_MODEL`. A missing selected key or unsupported provider fails explicitly before a request. Network/provider failures are surfaced to the caller.

## External providers

| `DLQ_AI_PROVIDER` | API key variable | Example model in the installed catalog |
|---|---|---|
| `openai` (default) | `OPENAI_API_KEY` | `gpt-4o-mini` (default) |
| `anthropic` | `ANTHROPIC_API_KEY` | `claude-sonnet-4-6` |
| `gemini` | `GEMINI_API_KEY` | `gemini-2.5-flash` |

Set a model that supports structured output and is available for your provider account. When changing providers, set `DLQ_AI_MODEL` as well; the default is an OpenAI model, not an automatic provider-specific choice. An Anthropic API key is required for Claude; a Claude Code subscription is not an API key.

For example, for a local Docker demo with Claude, export the following **in the shell that starts Compose**, then rebuild and recreate the containers:

```bash
read -rs -p 'Anthropic API key: ' ANTHROPIC_API_KEY; printf '\n'
export ANTHROPIC_API_KEY
export APP_DEMO_DETERMINISTIC=false DLQ_AI_PROVIDER=anthropic DLQ_AI_MODEL=claude-sonnet-4-6
make rebuild
unset ANTHROPIC_API_KEY
```

For Gemini, use `DLQ_AI_PROVIDER=gemini`, `DLQ_AI_MODEL=gemini-2.5-flash` and `GEMINI_API_KEY`; for OpenAI, use `DLQ_AI_PROVIDER=openai` and `OPENAI_API_KEY`. The same variables must be present when recreating containers. Compose passes them to the app (including the watcher and MCP) and worker; the migration service shares the app environment. If using `make MODE=dev`, pass that mode to each Make command. Keep keys out of committed files and images. For non-Compose deployments, use environment variables, `.env.local`, or the Symfony secrets vault.

Only a patch-eligible processing path invokes the external agent; listing failures or running a patch dry-run does not. After changing configuration, inject failures and run the watcher as described in the [README](../README.md). Check provider access, billing and model availability separately: bridge tests use mocked HTTP, not paid API calls.

The external adapter requests Symfony AI structured output for [`SymfonyAiAgentResponse`](../src/DlqDoctor/Agent/SymfonyAiAgentResponse.php), whose optional `proposedPayloadJson` field contains the proposed JSON object encoded as a string. Malformed or oversized output is rejected. Each input payload and schema and each proposed payload are capped at 65,536 bytes, combined requests at 131,072 bytes, and diagnoses at 2,048 bytes. Output is a suggestion only: the agent has no tools and cannot select tenants, decide policy, authorize, replay, acknowledge, or discard messages. The application applies a suggestion only when its configured PATCH policy permits it, and only after deserialization into the original message class and Symfony validation; MEDIUM and HIGH replay paths remain approval-gated. Operator-submitted suggestions use the separate patch dry-run workflow.

## Data handling

External mode sends the message class, failure exception class, normalized payload, and JSON Schema to the selected provider. Enable it only when that data transfer is acceptable under the application's privacy and provider requirements. Deterministic mode has no API-key or network requirement and is suitable for offline demos and tests.

## Package compatibility

The implementation was verified with PHP 8.5, Symfony 8.1, and Symfony AI bundle, agent, platform, and OpenAI, Anthropic and Gemini bridges `0.15.0`. All three structured-output paths are exercised with mocked provider HTTP responses. The bridges are installed as Composer dependencies, but no external call is made unless deterministic mode is disabled and the adapter is invoked.
