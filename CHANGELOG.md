# Changelog

All notable changes to SyncMGR are documented in this file.
This project adheres to [Semantic Versioning](https://semver.org/).

## [1.1.0] - 2026-07-18

### Added
- Real rclone-backed **sync engine**: endpoints (FTP, SFTP, S3, local; Agent transport stubbed), role-based pairings, **Sync Now**, and per-run **SyncEvents** (files, bytes, duration, status).
- **Device roles** (Syncthing-style): Send Only / Receive Only / Send & Receive, with a designated **Main** endpoint driving direction.
- **Device Groups**: named sets of endpoints with membership management and bulk-delete.
- **Multi-peer pairings**: a Main endpoint fans a one-way sync out to many peers (ad-hoc devices and/or whole groups), recording one SyncEvent per peer.
- **Schedule modes**: Manual, Scheduled (interval), and **On Change** (continuous near-real-time polling).
- Bundled **rclone** binary (fetched via `deploy/local/fetch-rclone.sh`) as the engine.
- Per-minute scheduler wiring (`sync:dispatch-due` + queue drain) with `WithoutOverlapping`.

### Changed
- Nav order: **Device Groups** now sits before Devices.
- Member Endpoints and run tables render flush (edge-to-edge) in their cards.
- System info "App Version" now reads the VERSION file.

### Fixed
- Password-manager autofill no longer clobbers endpoint credential fields (LastPass/1Password/Bitwarden opt-out attributes + readonly-until-focus).

### Security
- Endpoint secrets stored with encrypted casts; rclone passwords obscured per-run, never written to a persistent config file.

## [1.0.1]
- Bulk-delete on all admin index tables; shared modal component fix (scrolling body, gradient header, wrapping title).

## [1.0.0]
- Initial SyncMGR scaffold (ScriptGain -MGR suite): panel, auth, settings, licensing guard.
