# Changelog

All notable changes to the "Gemini Test Data Generator" plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.9] - 2026-09-17

### Added
- Dedicated API conversation logger (`var/log/test_data_generator_api.log`) recording complete Gemini API requests, models, prompts, schemas, headers (with masked API keys), execution duration, response payloads, and error details.
- Correlation IDs (`call_id`) attached to each request/response/error log pair for easy tracing of individual API conversations.
- REST endpoint `GET /api/test-data-generator/api-log` to retrieve recent API log entries.

### Fixed
- Replaced generic "Invalid response from Gemini API" exceptions with detailed error reports extracting HTTP status codes, error messages, finish reasons (e.g. `SAFETY`, `MAX_TOKENS`), and safety feedback.
- Truncated massive base64 payloads in image generation response logs to keep log files readable and lightweight.

### Fixed
- Include the complete root-to-target category path in product generation prompts so ambiguous names such as Cabinets retain their Office/Furniture context.
- Resolve ancestor names through DAL parent relationships, including inactive ancestors and newly generated categories without indexed paths. Report missing ancestors and cycles explicitly.

### Added
- Regression tests for category path ordering, translated names, inactive ancestors, missing parents and cycles.

## [1.0.7] - 2026-09-17

### Changed
- Documented the test/demo purpose, broad display coverage objective and intentional translation replacement based on the base language.
- Reassessed the audit: accepted overwrites by design, made channel targeting optional and prioritized deterministic base-language translation.
- Corrected the README preservation claim and documented current translation limitations. Runtime code is unchanged.

## [1.0.6] - 2026-09-17

### Added
- Code audit with prioritized findings, source references, verification scope, and a remediation tracker in AUDIT.md.
- README link to the audit for future adjustments. Runtime code is unchanged.

## [1.0.5] - 2026-07-23

### Added
- **Generate Manufacturers / Brands Option**: Added a new option to generate realistic or well-known brands tailored to a user-specified industry/branch (e.g. Fashion, Electronics, Outdoor & Sports).
- **Brand Details & Logos**: Generated manufacturers include official website URLs, translated HTML descriptions, and brand logo images centered on a crisp white background.
- **Logo Generation Retry Mechanism**: Retries logo generation up to 2 times upon failure before falling back to a clean GD white background placeholder logo.
- **Product Brand Association**: Automatically assigns available/generated manufacturers to newly created products.
- **CLI Options**: Added `--manufacturers`, `--manufacturers-count`, and `--manufacturers-branch` options to the `test-data:generate` console command.
