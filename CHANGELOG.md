# Changelog

All notable changes to this package are documented in this file.

## [0.2.1] - 2026-07-28

### Changed

- Stabilized query-authorization invariants for base grants, deny precedence,
  operator-global access, team-scoped allows, and explicit resource grants.
- Made authorization decisions fail closed for malformed and empty scopes while
  preserving an explicit valid `scope_type = all` wildcard.
- Removed package-owned Composer VCS repository metadata.
- Documented the public Authorization boundary and backward-compatible `0.2.x`
  namespace, configuration, middleware, container, and facade contracts.
- Strengthened CI and release gates with Composer validation and audit, PHPUnit,
  Pint, whitespace validation, and a final clean-tree check.
