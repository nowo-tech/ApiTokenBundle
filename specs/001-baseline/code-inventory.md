# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/api-token-bundle`  
**Last audited**: 2026-11-12 (S0)

Scope: every file under `src/`. Audit command: `find src -type f | wc -l` → **12**.

## Bundle and dependency injection

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/NowoApiTokenBundle.php` | Bundle | FR-BUNDLE-001 |
| `src/DependencyInjection/NowoApiTokenExtension.php` | Configuration | FR-CONFIG-001, FR-CONFIG-002 |
| `src/DependencyInjection/Configuration.php` | Configuration | FR-CONFIG-001 |
| `src/DependencyInjection/ConfigNodes.php` | Configuration | FR-CONFIG-001 |

## Resources

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Resources/config/doctrine/README.md` | Persistence (mapping folder, empty in S0) | FR-DB-001 |
| `src/Resources/translations/NowoApiTokenBundle.en.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoApiTokenBundle.es.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoApiTokenBundle.it.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoApiTokenBundle.fr.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoApiTokenBundle.pt.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoApiTokenBundle.de.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoApiTokenBundle.nl.yaml` | Translations | FR-I18N-001 |

| Total production sources | 12 |
| --- | --- |
