# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/).
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

