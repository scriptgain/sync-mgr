# Changelog

All notable changes to SyncMGR are documented in this file.
This project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- **Cross-platform sync agent — master transport surface (Phase 1)**. An `agent`-type Device is now a real, out-dialing endpoint. The master can enroll an agent, hand it a per-pairing job spec + remote credentials, and record the SyncEvents it reports back:
  - New agent API under `/api/v1/agent`: open `POST enroll` (one-time token to permanent key), and behind the new `agent.auth` bearer middleware: `POST heartbeat` (poll interval + signed license blob + optional update offer), `GET poll` (job spec per enabled pairing: op push/pull/bisync, local path, the remote endpoint's rclone config + path, schedule, watch flag, pending Sync-Now), `POST runs/report` (creates a SyncEvent + rolls the pairing forward), `POST command-ack`.
  - Agent device **enrollment UI**: one-time enrollment code (shown once), per-OS install commands, and live status (version / OS / last check-in with an online/offline dot) on the device page; "New Enrollment Code" / "Re-Pair Agent" action.
  - Dispatcher now **skips agent-managed pairings** (the agent self-schedules); panel **Sync Now** on an agent pairing raises a `pending_sync_now` flag the agent claims on its next poll.
  - `agent:sign` artisan command signs a release (`version|sha256`) with the ScriptGain vendor key and emits the four update settings; General Settings gains **Release SHA-256** + **Release Signature** fields.
- **Pausable Device Groups**: pause / resume a group (single row toggle + group page action) and **bulk Pause / Resume** on the index. A paused group contributes no peers, so fan-out pairings skip its members without erroring.

### Changed
- Agent endpoints no longer show a "coming soon" notice; the device page renders the agent enrollment + status panel instead.

### Security
- Agent enrollment tokens and API keys stored hashed (sha256) and hidden from serialization; remote credentials are delivered to the agent per-poll only, never persisted server-side.

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
