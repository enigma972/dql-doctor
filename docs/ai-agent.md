# DLQ diagnostic agent

## Modes

`DlqDoctorAgentInterface` separates diagnostics and patch suggestions from policy and message processing. The default `APP_DEMO_DETERMINISTIC=true` mode is offline and deterministic: it proposes only missing required fields with explicit JSON Schema `default` values, otherwise it returns a diagnosis without a patch. It never calls an external provider.

Set `APP_DEMO_DETERMINISTIC=false` to select the optional Symfony AI/OpenAI adapter. Configure `OPENAI_API_KEY` and, optionally, `DLQ_AI_MODEL` (default `gpt-4o-mini`) in the local environment, not in committed secrets. If deterministic mode is disabled without an API key, the adapter fails explicitly before making a request. Network/provider failures are surfaced to the caller.

The external adapter requests Symfony AI structured output for [`SymfonyAiAgentResponse`](../src/DlqDoctor/Agent/SymfonyAiAgentResponse.php), whose optional `proposedPayloadJson` field contains the proposed JSON object encoded as a string. Malformed or oversized output is rejected. Each input payload and schema and each proposed payload are capped at 65,536 bytes, combined requests at 131,072 bytes, and diagnoses at 2,048 bytes. Output is a suggestion only: the agent has no tools and cannot select tenants, decide policy, authorize, replay, acknowledge, or discard messages. Any proposed payload must go through contract inspection, patch dry-run validation, and the separate approval workflow before use.

## Data handling

External mode sends the message class, failure exception class, normalized payload, and JSON Schema to the configured OpenAI endpoint. Enable it only when that data transfer is acceptable under the application's privacy and provider requirements. Deterministic mode has no API-key or network requirement and is suitable for offline demos and tests.

## Package compatibility

The implementation was verified with PHP 8.5, Symfony 8.1, and Symfony AI bundle, agent, platform, and OpenAI bridge `0.15.0`. The bundle and provider are optional at runtime but installed as Composer dependencies to make the external mode available; no paid API call is made unless deterministic mode is disabled and the adapter is invoked.
