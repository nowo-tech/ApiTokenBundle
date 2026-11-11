# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- S0 scaffold (contract 221-A, [CONTRACT.md](CONTRACT.md)): the bundle class `NowoApiTokenBundle`, the strict configuration
  tree `nowo_api_token` with every key of the contract (`enabled: false` by default; nothing is registered in S0) and the
  container parameters `nowo_api_token.enabled`, `nowo_api_token.api_prefix`, `nowo_api_token.table_prefix` and
  `nowo_api_token.config`.
- Quality gates: PHPUnit (100 % coverage gate), PHPStan level 8 with the FrankenPHP worker rulesets, PHP-CS-Fixer,
  Rector, Igor, CI matrix (PHP 8.2 to 8.5, Symfony 7.4 and 8.1), a SQLite / MySQL / PostgreSQL job, a worker test (one
  kernel kept alive, 2,000 requests) and the FrankenPHP demo smoke test.
- Docs set, Spec Kit baseline, Flex recipe, demo skeleton (Symfony 8.1, FrankenPHP worker mode, MySQL).
