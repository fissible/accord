# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/).
## [1.4.0] - 2026-06-17

### Added
- Wildcard media-type matching (exact > type/* > */*) for request + response (#10)
- Servers base-path fallback in operation lookup (method-aware, effective-path threading) (#10)

### Fixed
- Validate exploded array query params (form/explode repeated keys) (#13)
## [1.3.0] - 2026-06-17

### Added
- Add runtime-gate skip reasons (excluded/response-disabled/not-sampled) (#8)
- Add RuntimeOptions (glob exclusions, response toggle, sampling) (#8)
- ContractValidator applies runtime gates (exclude/toggle/sample) as early skips (#8)
- Laravel config for exclude/validate_responses/response_sample_rate (#8)
- Laravel provider builds RuntimeOptions from config (#8)
- AccordFactory builds RuntimeOptions from config (#8)
- Optional PSR-16 cache for FileSpecSource (mtime-keyed JSON round-trip) (#7)
- Add spec_cache config knob; correct spec_cache_ttl doc (#7)
- Provider resolves spec cache and wires it into file + url sources (#7)
- AccordFactory wires spec_cache into the file source (#7)
## [1.2.0] - 2026-06-15

### Added
- Add SkipReason enum for validation diagnostics (#9)
- ValidationResult carries skip reason + wasValidated/wasSkipped (#9)
- ContractValidator emits skip reasons + optional debug logging (#9)
- Add documented ACCORD_DEBUG config knob (#9)
- Laravel provider passes debug flag to validator (#9)
- AccordFactory honors debug (bool-safe) + optional logger key (#9)
- AssertResponseWasValidated trait assertion catches silent skips (#9)
## [1.1.0] - 2026-06-15

### Added
- Validate OpenAPI request parameters
- Add Direction enum for request/response failure routing
- Add FailureMode::resolvePair for string|array config (#5)
- ContractViolationException carries Direction (trailing param, ABI preserved) (#5)
- Direction-aware handleFailure with response failure mode + direction log context (#5)
- PSR-15 middleware routes request/response violations per direction (#5)
- Laravel config for array failure_mode + request_violation_status (#5, #6)
- Render request violations as JSON 4xx; response violations stay server errors (#6)
- Provider resolves per-direction modes and binds middleware with guarded status (#5, #6)
- AccordFactory parses string|array failure_mode via resolvePair (#5)

### Fixed
- Restore Laravel logging and templated path matching
- Pin composer platform to php 8.2 so lock installs on the support matrix

### Ci
- Add Packagist auto-update to release workflow
## [1.0.0] - 2026-03-24

### Fix
- Callable is not a valid PHP property type, use mixed with phpdoc

### Fixed
- Support symfony/psr-http-message-bridge ^7.0

### README
- Expand suite section to include fissible/watch

### Refactor
- SpecResolver → SpecSourceInterface + FileSpecSource + UrlSpecSource; add YAML support; update README

### Ci
- Add PHP 8.2/8.3 test workflow
- Add release workflow (calls fissible reusable workflow)

