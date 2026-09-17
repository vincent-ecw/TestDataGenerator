# TestDataGenerator code audit

Audit date: 2026-09-17  
Audited version: 1.0.5  
Audited commit: `17fe18ddd14f749ab430ad44c1890dd5c8d99ac3`  
Environment inspected: Shopware 6.7.13.1, dev, Docker container `shopware67`.  
Report branch: `audit/test-data-generator-2026-09-17`

## Scope and interpretation

Reviewed all PHP services, controller, command, message/handler, XML configuration, administration source, snippets, composer metadata and documentation. Checked relevant installed Shopware implementations for routing, translated-field serialization, HTTP authentication, component compatibility and DAL criteria. Generated administration assets were inventoried, but their reproducibility was not established.

This is a source audit with framework boot/registration checks, not an end-to-end generation test or penetration test. No Gemini requests, catalog generation, translation writes or deletion runs were executed. Findings marked **confirmed** are supported by code paths; their reproduction steps below are proposed regression checks, not executed experiments. Model availability is separately qualified. No application fixes are included in this audit delivery.

Priorities: **P1** = address first (data loss, unsafe scope, repeat side effects); **P2** = correctness/reliability; **P3** = maintainability/usability. TDG-001 is **Accepted by design**, TDG-005 is an **Optional enhancement**, and other findings are **Open**. Paths and line numbers refer to the audited commit; `Importer` means `src/Service/DataImporter.php`, `Page` means `src/Resources/app/administration/src/module/test-data-generator/page/test-data-generator-index/`.

## Product intent clarified by the owner (2026-09-17)

The plugin quickly populates test and demo environments with large amounts of richly populated content so every kind of display can be tested or demonstrated. Production catalog preservation is outside the intended use. Broad coverage of properties, variants, languages, media, brands and reviews takes priority over production editorial workflows.

All translations should derive from the base language. Existing translated text may be overwritten intentionally; field-by-field preservation is not required. The exact base-language identifier (system language, channel default or explicit configuration) remains to be defined during implementation; it must not depend on collection order.

This clarification changes the assessment, not the code. The current implementation only selects missing/incomplete locales and takes the first translation with a name. It does not guarantee regeneration of all targets from the base language. Comprehensive display coverage is an objective, not a verified guarantee that every Shopware field is generated.

## Findings

### TDG-001 — Accepted by design — Existing translated fields may be overwritten
**Evidence (confirmed):** Importer:840–848, 896–904, 1145–1166 selects an incomplete locale and writes all its translated fields.

**Reassessment:** The owner explicitly permits replacing translated content in test/demo catalogs. The original P1 classification and proposed field-preservation fix are withdrawn. The README's preservation promise was incorrect and has been corrected.

**Required behavior:** All target translations must derive from the base language; overwriting targets is expected. Track the remaining source-selection and refresh gap under TDG-008.

**Acceptance:** No field-preservation regression check is required. Use TDG-008's base-language checks instead.

### TDG-002 — P1 — Hidden deletion flag survives switching to translations only
**Evidence (confirmed):** Page template:15–25 hides the deletion control without clearing its value; Page index.js:132–143 always sends it. Controller:54–59 accepts both flags. Importer:80–82 deletes before the translation-only branch at 101–103.

**Impact / trigger:** In dev, enable deletion, then enable translations-only and submit. All products and property groups are deleted before translation begins, although the destructive option is hidden.

**Adjustment:** Reject incompatible modes server-side and make translation-only execution precede/exclude destructive setup. Clear incompatible UI state as additional protection.

**Acceptance:** Submitting both flags returns a validation error and performs no writes. Switching modes in the UI cannot submit a retained deletion flag.

### TDG-003 — P1 — Whole-job retries repeat non-idempotent writes and deletion
**Evidence (confirmed):** Handler:28–46 rethrows errors; Importer creates random IDs at 222, 558, 658 and 1570 with individual repository writes. Message has no job ID/checkpoints. Importer:80–82 repeats deletion on every invocation.

**Impact:** Any redelivery after a later API/write failure recreates successful earlier entities and repeats paid requests. With deletion enabled it deletes again, including products added since the previous attempt. No worker-time environment check exists in the importer, so a queued dev deletion request also remains destructive if later processed in a different environment.

**Adjustment:** Durable job identity, bounded resumable steps, deduplication/checkpoints and worker-side environment/scope enforcement. Finish preflight checks before cleanup; make cleanup an explicit once-only step.

**Acceptance:** Fail after the first product, then redeliver the same message: no duplicates, repeated cleanup or repeated completed API work. A deletion-bearing message is refused by a non-dev worker.

### TDG-004 — P1 — Request and CLI validation allow unsafe workload and invalid mode combinations
**Evidence (confirmed):** Controller:36–63 casts arbitrary input; no upper bounds, strict boolean checks or UUID/category validation. Enabling manufacturers bypasses category/product count validation entirely. Command:42–60 casts counts without validation. Importer:277 divides by categoriesCount.

**Impact:** Authorized callers can enqueue excessive paid work. A zero category count with manufacturers enabled can reach division by zero after earlier writes. The string `"false"` casts to true, including for deletion in dev. Invalid JSON can silently become default generation.

**Adjustment:** Shared validated options object for API/CLI/worker: strict JSON object/types, positive bounded counts, bounded branch text, UUID/existence checks and explicit allowed mode combinations. Support intentionally large runs with bounded batches and configurable workload limits including variants/reviews/images; avoid small fixed limits that defeat demo population.

**Acceptance:** Reject malformed JSON, string booleans, negative/zero/oversized counts and invalid UUIDs before dispatch or writes; test manufacturer combinations and direct CLI use.

### TDG-005 — P3 / Optional enhancement — Explicit sales-channel scope
**Evidence (confirmed):** Importer:1180–1204 picks the first active channel for navigation but assigns visibility to all active channels. Products and reviews are immediately visible (579, 741); category search is global (114–161).

**Reassessment:** Visible demo content across a test installation supports the intended purpose. Production publication risk is not a P1 defect within that scope. Channel targeting is an optional convenience for separate demo scenarios, not a required restriction. Source/language inconsistency remains TDG-008.

**Optional adjustment:** Offer all-active-channel and selected-channel modes while preserving broad demo population as a supported workflow. Align navigation and languages with the selected scope.

**Acceptance if implemented:** All-channel mode covers intended demo channels; selected-channel mode honors the selection. No production publication approval workflow is required.

### TDG-006 — P2 — Status cannot reliably identify queued or concurrent jobs
**Evidence (confirmed):** Handler:24–45 stores one global system-config status. Controller:66–83 returns no job ID and creates no queued status. Page polls config every three seconds (80–87, 112–117); button disabling excludes running status (template:93).

**Impact:** Before a worker starts, users see idle or an older success. Concurrent tasks overwrite each other's status. A worker crash can leave running indefinitely, and repeat submission is unrestricted.

**Adjustment:** Per-job durable queued/running/completed/partial/failed state with progress, timestamps and owner. Return the job ID on dispatch, poll a dedicated authorized endpoint, and coordinate concurrent runs with a server-side lock or queue policy.

**Acceptance:** Two users can independently follow their jobs; queued work is visible without a worker; a crashed worker becomes recoverable rather than permanently running.

### TDG-007 — P2 — Requested product count is not honored
**Evidence (confirmed):** Importer:276–284 forces at least one product per category. Category responses are not checked against requested count (214–221), and product responses are not checked against chunkSize (435–459); remaining is reduced before validation (408–409).

**Impact / trigger:** Five categories with two requested products produces at least five parent products. Short or oversized model responses silently alter totals. Variants add further product rows without a separate visible count.

**Adjustment:** Define counts as parent products versus all records, and exact totals versus coverage targets. One product per category can be useful demo behavior if documented. Validate response cardinality and report actual totals/coverage.

**Acceptance:** Five categories/two requested products follows the documented exact-total or minimum-coverage policy. Show actual parent/variant counts; incomplete responses must not silently claim full coverage.

### TDG-008 — P1 — Translations do not reliably use the base language
**Evidence (confirmed):** Importer:751–778 selects the first active channel and uses collection order as default (99), rather than its configured default language. Source lookup labels languages outside the selected mapping as en-GB (788), as does entity fallback (802). Top-level names use that first collection language (242–246, 586–590).

**Impact:** A Dutch source translation outside the chosen channel can be described to Gemini as English. If the system language is absent from the generated mapping, top-level fields populate that system language with another language's text. Installed TranslatedFieldSerializer:43–48 confirms top-level fields fill a missing context-language entry; explicit translations are not overwritten by that serializer.

**Additional evidence:** getSourceTranslation (781–810) selects the first translation with a name, not a defined base language. Missingness filters (840–848, 896–904, 951–954, 1001–1004) skip already complete targets, which can remain stale when base content changes.

**Adjustment:** Define and resolve a deterministic base-language ID and actual locale. Use that translation as the source for every target, permitting existing target content to be overwritten. Support regenerating complete targets to propagate base changes. Keep base content as the source of truth; report missing base fields instead of silently choosing another target language.

**Acceptance:** Seed conflicting translations and change collection order: targets always derive from the defined base language. Update base text and regenerate: previously complete targets refresh. Missing base content is reported rather than substituted from another target. Include non-English base/system languages and differing channel defaults.

### TDG-009 — P2 — Invalid or failed optional output can still produce success
**Evidence (confirmed):** Importer:438–440 skips invalid product output; 1124–1126 returns for malformed translation output; 1523–1533 returns for manufacturer API/structure errors. Handler:43 and Command:63–64 still report success.

**Impact:** A run can create no requested manufacturers, skip a whole product batch or leave translations missing while reporting completion. Review points, nested translation types, variant uniqueness and numeric ranges lack comprehensive local validation (e.g. 526–534, 638–681, 708–747).

**Adjustment:** Validate decoded responses before writes and return structured counts/warnings/errors to the job. Detect duplicate variant option combinations/SKUs. Distinguish partial results from complete success; cap nested arrays and field lengths.

**Acceptance:** Stub invalid JSON, missing locales, duplicate variants, negative prices and out-of-range reviews. Reject/repair predictably before related writes; a skipped requested stage yields partial/failed status.

### TDG-010 — P2 — Image model configuration differs from public documentation
**Evidence:** GeminiClient:85 hardcodes `gemini-3.1-flash-lite-image`. Google's inspected official model page documents `gemini-3.1-flash-image`. README:3/16 names `gemini-2.5-flash-image`. Text fallback (GeminiClient:24) also differs from config.xml:19.

**Confidence:** Code/config/documentation inconsistency is confirmed. The image model identifier is likely incorrect, but no authenticated model-list or generation request was made, so account-specific availability and actual HTTP failure are unverified.

**Adjustment:** Configurable image model, one shared text-model default, capability validation/preflight, current documentation and explicit fallback warnings. Validate all offered model IDs against the intended account during implementation.

**Acceptance:** Contract tests assert the configured model endpoint; an authorized live smoke test verifies text and image capabilities. An unavailable model is reported before a large job.

**Primary sources checked 2026-09-17:** [Google model page](https://ai.google.dev/gemini-api/docs/models/gemini-3.1-flash-image), [image generation guide](https://ai.google.dev/gemini-api/docs/image-generation), [model catalog](https://ai.google.dev/gemini-api/docs/models). Model availability is time-sensitive; recheck before fixing.

### TDG-011 — P2 — Image bytes are saved under assumed MIME types; failed saves leave media rows
**Evidence (confirmed):** GeminiClient:124–137 discards MIME metadata and decodes base64 non-strictly. Importer:1332–1333 labels every product image JPEG, while 1630–1634 labels every logo PNG. Media rows are created before file persistence (1378/1382 and 1645/1649); catches do not remove them, and product-image persistence errors are swallowed (1390–1391).

**Impact:** Responses in another format receive incorrect metadata/extensions; corrupt payloads reach media handling. Save failures can leave orphan records while the job reports success. Later product/manufacturer write failures can also leave unattached media.

**Adjustment:** Return validated bytes plus actual MIME; detect format, enforce size/dimension limits, derive extension, and compensate failed media/entity writes with useful job warnings. Keep temporary-file cleanup in finally.

**Acceptance:** PNG product images and JPEG logos retain their real MIME/extension; malformed base64 and oversized data fail cleanly; simulated file-save/entity failures leave no new orphan media.

### TDG-012 — P2 — Large catalog reads are unbounded
**Evidence (confirmed):** Importer:115–117, 816–819, 876–879, 932–934 and 981–984 use unlimited searches. Installed Criteria has a null default limit. Translation entities and work arrays are fully materialized before array_chunk.

**Impact:** Chunking Gemini prompts into 15 items does not bound database reads or worker memory. Large catalogs, including property options, can exhaust memory before translation begins.

**Adjustment:** Stable ID/keyset pagination with bounded DAL reads and per-page translation work; scope by selected channel/category. Add resumable cursor and cancellation support.

**Acceptance:** A dataset larger than several pages completes without skipped/repeated IDs and with bounded peak memory.

### TDG-013 — P2 — Custom Admin requests omit initial bearer authentication
**Evidence (confirmed source omission; browser behavior untested):** Page index.js:90–96 and 129–144 call the raw init httpClient without authorization headers. Installed core ApiService:77 adds the bearer token explicitly; http.factory.js's 401 interceptor at 382–425 can refresh and retry with a header.

**Impact:** Initial env/generate requests can receive 401 and rely on token-refresh recovery. With unavailable/expired refresh credentials, the dev controls fail silently or generation fails despite a usable access token. Even successful recovery adds unnecessary refresh traffic.

**Adjustment:** Register an API service using Shopware ApiService/loginService basic headers; surface environment/polling errors appropriately. Add matching navigation/route privilege metadata (currently absent in module index.js:18–43).

**Acceptance:** Inspect requests in a logged-in browser: initial env and generate calls carry bearer auth without relying on 401 recovery; restricted users receive consistent UI and server authorization.

### TDG-014 — P3 — Documentation and testability lag implementation
**Evidence (confirmed):** README:5/78 implies all work is asynchronous, but Command:50 calls importer synchronously. README:95 says curl/Imagen prediction endpoints, while the client uses Guzzle/generateContent. No dedicated automated test suite is tracked. Importer combines prompts, catalog policy, translations, image processing and cleanup in 1,750 lines; GeminiClient constructs Guzzle internally.

**Adjustment:** Correct CLI/model/architecture documentation, list all CLI options and clarify cleanup scope (all products/groups, not only generated records; media/categories/manufacturers remain). Introduce focused services and injectable HTTP transport as fixes require them, then add regression coverage for the higher-priority IDs.

**Acceptance:** Documentation matches actual invocation and cleanup behavior; Gemini failures and worker redelivery can be tested without external calls.

### TDG-015 — P2 — Full category hierarchy missing from product prompts — Implemented in 1.0.8

**Original evidence:** Audited Importer:156–160 and 411–428 retained only the target category name/description. Ancestor IDs were used for filtering, not semantic context. A Cabinets prompt did not identify its Furniture > Office ancestry.

**Fix:** Resolve each selected category and its ancestors through DAL parent IDs using the import context; add the ordered name path to the prompt and instruct Gemini to use the entire hierarchy. Shared ancestors are loaded together per level; inactive ancestors remain context. Parent relationships also support new categories without indexed paths. Missing categories or cycles raise explicit errors. Base-language selection remains separately open under TDG-008.

**Verification:** Four PHPUnit regression tests passed (16 assertions), covering ordering, translated names, inactive ancestors, unset indexed paths, shared ancestors, root/empty selections, missing parents and cycles. PHP syntax lint passed. No paid Gemini generation was executed; prompt relevance remains model-dependent.

**Branch:** `fix/category-path-context`. No commit yet.

## Security review notes and remaining verification

- Generate route is in authenticated API scope and carries system.plugin_maintenance ACL; router output confirms registration. No anonymous generation bypass was identified. Permission granularity deserves a dedicated plugin privilege, but existing ACL must not be removed.
- DAL repositories are used for writes/deletion; no raw SQL was found. No hardcoded business entity UUIDs were found; Shopware Defaults constants are distinct from installation-specific IDs.
- Google requests target a fixed HTTPS host and send the key in x-goog-api-key. No direct key interpolation into request URLs was found.
- Translation responses are matched to requested IDs and requested target locales before writes (Importer:1133–1150), limiting model-selected write scope.
- Generated HTML and manufacturer URLs are untrusted external data. DAL AllowHtml and downstream storefront behavior must be checked with hostile markup and javascript:/data: URLs before claiming complete XSS protection. This audit did not demonstrate an exploitable XSS or SSRF path.
- API error bodies are included in exception messages and stored in global status (GeminiClient:60–66, Handler:45). Review redaction/length limits; a credential leak was not demonstrated. Page repeatedly loads the whole config domain to get status, coupling monitoring to configuration access and API-key presence.
- Property caches reset only in cleanup (Importer:1725–1726); assess stale IDs if records change between messages. Existing property matches return without filling new translations (1258–1260, 1301–1303).
- Manufacturer selection samples up to 500 existing brands, not only newly generated ones (111, 582–583, 1716–1720). Confirm intended association policy.
- No exact dependency vulnerability advisory audit, authenticated browser test, cross-version matrix, storefront render test or full build was performed. No absence-of-vulnerabilities claim is made.

## Verification performed

All commands ran inside shopware67 (host Docker commands only controlled the container).
- PHP syntax lint: all seven plugin PHP files passed.
- Shopware boot: version 6.7.13.1, dev.
- lint:container: passed.
- debug:router api.test_data_generator.generate: correct POST route and ACL.
- debug:messenger: message handler registered.
- test-data:generate --help: command registered; no generation executed.
- No dedicated test suite found in the plugin.
- Composer JSON, configuration XML and all 14 audit finding headings validated.
- git diff --check: passed.
- bin/console cache:clear: passed (dev cache cleared and warmed).
- No Admin/Storefront sources changed, so their builds are not required for this documentation-only delivery.

## Suggested implementation sequence

1. TDG-002–004 and TDG-008: prevent unintended deletion, validate modes, make retries safe and ensure base-language translation.
2. TDG-006–007, TDG-009 and TDG-012: reliable jobs, transparent counts/coverage, validated responses and bounded reads for large catalogs.
3. TDG-010–011 and TDG-013: verified models, media integrity and Admin API integration.
4. TDG-014: align documentation and refactor alongside regression tests. TDG-005 is optional; TDG-001 requires no preservation fix.

Use these IDs in future branches and changelog entries. Before closing an item, record fix commit, executed regression check, result and remaining limitations below.

| Finding | Status | Fix commit | Executed verification |
| --- | --- | --- | --- |
| TDG-001 | Accepted by design | — | Owner clarification; no fix required |
| TDG-002 | Open | — | — |
| TDG-003 | Open | — | — |
| TDG-004 | Open | — | — |
| TDG-005 | Optional enhancement | — | Reassessed for test/demo scope |
| TDG-006 | Open | — | — |
| TDG-007 | Open | — | — |
| TDG-008 | Open | — | — |
| TDG-009 | Open | — | — |
| TDG-010 | Open | — | — |
| TDG-011 | Open | — | — |
| TDG-012 | Open | — | — |
| TDG-013 | Open | — | — |
| TDG-014 | Open | — | — |
| TDG-015 | Implemented in 1.0.8 | Not committed | 4 tests / 16 assertions; PHP lint |

## Audit delivery

Report and README/CHANGELOG updates only; plugin composer version incremented from 1.0.5 to 1.0.6 in accordance with repository instructions. Runtime source remains unchanged. The pre-existing untracked src/.DS_Store was left untouched. No commit or push was performed.

### Intent clarification delivery

Version 1.0.7 documents the owner's test/demo purpose and base-language policy. TDG-001 is accepted by design, TDG-005 is optional and TDG-008 is prioritized at P1. No runtime changes or generation tests were performed.
