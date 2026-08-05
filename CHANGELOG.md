# Changelog

All notable changes to the "Gemini Test Data Generator" plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.5] - 2026-07-23

### Added
- **Generate Manufacturers / Brands Option**: Added a new option to generate realistic or well-known brands tailored to a user-specified industry/branch (e.g. Fashion, Electronics, Outdoor & Sports).
- **Brand Details & Logos**: Generated manufacturers include official website URLs, translated HTML descriptions, and brand logo images centered on a crisp white background.
- **Logo Generation Retry Mechanism**: Retries logo generation up to 2 times upon failure before falling back to a clean GD white background placeholder logo.
- **Product Brand Association**: Automatically assigns available/generated manufacturers to newly created products.
- **CLI Options**: Added `--manufacturers`, `--manufacturers-count`, and `--manufacturers-branch` options to the `test-data:generate` console command.
