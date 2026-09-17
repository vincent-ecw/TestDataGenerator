# Gemini Test Data Generator

The **Gemini Test Data Generator** is a premium Shopware 6.7 plugin designed to quickly populate test and demo environments with realistic, high-quality test data. It integrates with the **Google Gemini API** for generating categories, products, and variants, and utilizes **gemini-2.5-flash-image** for generating product cover images.

## Purpose and translation policy

This plugin quickly fills test and demo environments with large amounts of richly populated content so every kind of product display can be tested or demonstrated. Broad coverage includes properties, variants, languages, media, brands and reviews. Products are not intended for production use. Complete display coverage is an objective; not every Shopware field or display scenario is currently guaranteed.

All translations should be generated from the base language. Existing translated content may be replaced intentionally. Base content is the source of truth; preserving previous target descriptions is not a requirement.

Current limitation: translation-only mode processes missing/incomplete locales and selects the first available named translation. Deterministic base-language selection and refreshing already complete targets remain adjustments to implement (TDG-008 in [AUDIT.md](AUDIT.md)).

Administration generation runs asynchronously through the message queue; the CLI command runs generation directly.

---

## Features

- **Full Category Context**: Product prompts include the complete root-to-target hierarchy, for example `Furniture > Office > Cabinets`, together with the target category description. Ancestor names use the import context's translations/fallbacks. This applies to existing and newly generated categories; deterministic base-language selection remains tracked under TDG-008.
- **Dynamic Category & Product Generation**: Uses Gemini to generate realistic names, descriptions, pricing, stock, properties (e.g. Color, Size), and variant products.
- **Multilingual Support**: Automatically detects all active languages in the default Sales Channel and generates translations for all of them.
- **Generate Products for Existing Categories**: Option to generate products dynamically for child categories of a selected category, automatically falling back to generating directly under the selected category if it has no children.
- **Clean Test Environments (DEV only)**: Provides a development-only option to clear all products and property groups from the store before starting generation to keep test environments clean.
- **Translation-Only Mode**: Scans your database for missing translations on existing categories and products, translates them using Gemini, and may overwrite existing translated fields intentionally. See the base-language policy and current limitation above.
- **Product Cover Images**: Optionally generates professional studio product cover images using Google's **gemini-2.5-flash-image** (with automatic pastel GD-generated images as a fallback).
- **Manufacturer & Brand Generation**: Generates realistic brands relevant to a specified industry/branch with brand names, URLs, translated descriptions, and logo graphics on a crisp white background.
- **Realistic Product Reviews**: Generates 1–10 reviews per product in matching languages, with varied star ratings (1.0 to 5.0) and randomized dates in the past (1 to 60 days) to test sorting and storefront layouts.
- **Asynchronous Execution**: Offloads heavy tasks to the Symfony Messenger queue. Users can safely close the administration page while the task executes in the background.
- **Progress Tracking**: Polls the current task status and displays visual feedback (running, completed, or failed with error logs) directly in the Admin panel.

---

## Installation

1. Copy the `TestDataGenerator` plugin folder into your Shopware directory under `custom/plugins/`.
2. Run the following commands inside the container to install, activate, and refresh:
   ```bash
   bin/console plugin:refresh
   bin/console plugin:install --activate TestDataGenerator
   bin/console cache:clear
   ```
3. Recompile the administration panel to bundle Vue 3 assets:
   ```bash
   ./bin/build-administration.sh
   ```

---

## Configuration

Navigate to **Settings > System > Plugins > Gemini Test Data Generator** in the Shopware Admin:
- **API Key**: Enter your Google Gemini API Key.
- **LLM Model**: Select your preferred Gemini model version (e.g. `gemini-3.5-flash`, `gemini-2.5-flash`, `gemini-2.5-pro`, `gemini-1.5-flash`, or `gemini-1.5-pro`).

---

## Usage

### Direct Access
Open the generator page from **Catalogues > Gemini Data Generator** in the main menu.

### Standard Generation
1. Configure settings such as number of categories, number of products, and whether to generate images.
2. Toggle **Add reviews for products** if you want to generate randomized reviews for each created product.
3. If in `dev` mode, toggle **Delete all products and product properties before generation** if you wish to clear existing catalog data first.
4. Click **Generate Test Data**.
5. A success toast will appear and the status card will change to **Running**.
6. Run the message queue consumer to execute the task (see below).

### Create Translations Only
1. Toggle the **Create translations only** switch.
2. This hides other parameters.
3. Click **Generate Test Data**. The worker processes missing/incomplete translations on active categories/products and property groups/options. Existing fields in those locales may be replaced. Currently, complete targets are skipped and base-language source selection is not guaranteed (TDG-008).

### CLI Command
You can also trigger generation directly from the terminal inside the container:
```bash
bin/console test-data:generate
```
Options:
- `-r`, `--reviews`: Automatically generate 1–10 reviews per product with randomized timestamps in the past.

---

## Processing Background Tasks

Since tasks are dispatched to the async queue, you must run the Symfony Messenger worker to process them:
```bash
bin/console messenger:consume async -vv
```
For continuous test or demo generation, you can run the worker with a limit or inside a supervisor process:
```bash
bin/console messenger:consume async --time-limit=60 --memory-limit=512M -vv
```

---

## Technical Architecture

- **Controller**: `GeneratorController.php` exposes the `/api/test-data-generator/generate` endpoint.
- **Queue Messaging**: `GenerateTestDataMessage.php` implements `AsyncMessageInterface` to serialize payloads.
- **Queue Handler**: `GenerateTestDataHandler.php` handles status updates and triggers the importer.
- **Importer Service**: `DataImporter.php` handles language/locale resolution, Gemini client prompts, tax/category mapping, repository creation, and persists products, media, and reviews (using `product_review.repository`).
- **API Client**: `GeminiClient.php` handles curl requests to Gemini JSON Schema and Imagen prediction endpoints.

## Code audit

See [AUDIT.md](AUDIT.md) for the 2026-09-17 review of version 1.0.5, prioritized findings, source references, and regression acceptance checks. Version 1.0.7 incorporates the clarified test/demo purpose and base-language policy. Version 1.0.8 adds full category context (TDG-015). The audit distinguishes open findings, accepted behavior and optional enhancements.

### Category context regression checks

From the Shopware directory inside the container, run:

```bash
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php custom/plugins/TestDataGenerator/tests/CategoryPathTest.php
```
