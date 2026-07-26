# SyncMGR

**Continuous file sync across your servers, with a control panel and an audit
trail.** Self-hosted, by [ScriptGain](https://scriptgain.com).

**[Try the live demo →](https://sync-demo.scriptgain.com)** No signup required.

## Who it's for

Anyone keeping the same files on more than one machine: web servers behind a load
balancer that need the same uploads directory, an office file store mirrored to a
DR site, a fleet of branch servers pulling the same configuration, or a media
pipeline moving finished work off editing machines.

## What it does

**Register your machines**
Each server, workstation, or NAS is a device. Group devices so a folder can be
shared with "all web servers" rather than named one at a time.

**Define what syncs**
A folder is a sync set: a path, the devices it lives on, and how it moves. Add a
device to the group and it picks up the folder.

**Let it run**
Changes propagate continuously. Scheduled dispatch handles anything that should
move in a window rather than immediately.

**See what happened**
Every transfer, conflict, and failure is an event you can search, usually the
thing you actually need at 2am, and the thing peer-to-peer sync tools don't keep.

**Run it like production**
Users and roles, two-factor authentication, an IP firewall with an escape hatch,
API tokens, a full audit log, database backups, host and SSL settings, and
in-place signed updates.

## Current state

**Version 1.2.2.** The control plane (devices, device groups, folders, event
history) and the whole operations shell are complete and in production use.

The **sync agent** is a separate cross-platform binary that runs on each device.
The Linux agent is proven; Windows and macOS builds are not published yet, so in
practice this is a Linux-to-Linux tool today.

## Why not just Syncthing

Syncthing is free, excellent, and the right answer for a handful of personal
machines. It is peer-to-peer by design, which means there is no central place to
say "these forty servers all get this folder", no central audit trail, no
role-based access for the people managing it, and no single panel showing which
device last fell behind.

SyncMGR takes the opposite trade: a central control plane, a searchable event
history, and admin accounts with permissions. Syncing two laptops? Use Syncthing.
Answerable for forty servers? That is what this is for.

## Install

Point a fresh Debian or Ubuntu server at your domain and run, as root:

```
curl -fsSL https://install.scriptgain.com | sudo bash -s -- sync-mgr DOMAIN=sync.example.com SSL=1 EMAIL=you@example.com
```

Then open `https://your.domain/setup` to create the first account and enter your
licence key. Install the agent on each device from the Devices screen.

## Where things live

| Surface | Path |
| --- | --- |
| Control panel | `/` |
| First-run setup | `/setup` |
| Agent and API endpoints | `/api` |

## Running it

Everything an operator changes (branding, email, notifications, firewall rules,
retention, backup schedule) is edited in the panel rather than in files on the
server.

Maintenance tasks from the command line:

| Command | What it does |
| --- | --- |
| `php artisan sync:run` | Runs a sync pass now. |
| `php artisan sync:dispatch-due` | Dispatches folders whose window has arrived. Runs on a timer. |
| `php artisan sync:maintenance` | Prunes old events and marks stale devices disconnected. |
| `php artisan agent:sign` | Signs an agent build so devices will accept it. |
| `php artisan license:check-online` | Re-validates your licence. |
| `php artisan app:update` | Applies a signed release. |
| `php artisan db-backup:run` | Backs up the database. |
| `php artisan firewall:clear` | Gets you back in if an IP rule locks you out. |

## Requirements

A Linux server with PHP 8.3 and MySQL or MariaDB for the control plane, plus the
agent on each device you sync. Bandwidth between sites matters far more than CPU.

## Licensing

One activation per licence by default, validated against
`https://scriptgain.com/v1`. Buy or manage yours at
[scriptgain.com/products/syncmgr](https://scriptgain.com/products/syncmgr).
