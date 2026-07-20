.. include:: /Includes.rst.txt

.. _introduction:

============
Introduction
============

What Snapshot is
================

Snapshot replaces the throwaway shell scripts teams keep for "get me a fresh local database from
the server". It pulls the **database** and **fileadmin** from a configured environment (Dev, Stage,
Live) onto your local machine, anonymizes the data on the way in, and leaves you with a runnable
site.

What Snapshot is not
====================

Snapshot is **not a backup tool**. It has no scheduler, no off-site storage, and no
restore-to-production. Backups are the job of your hosting or DevOps pipeline. Positioning this
clearly is deliberate: the "export production data" category has a poor security track record, and
Snapshot is built to be the safe, developer-facing counterpart — not another backup plugin.

The two pillars
===============

Pillar B — CLI over SSH (the primary path)
------------------------------------------

Run from the developer's own machine, installed as a ``require-dev`` dependency. Snapshot connects
to the source environment over SSH, exports its database and rsyncs its fileadmin, and imports both
locally. **Production is never touched** beyond read-only export commands, and the extension does
not need to be installed on the server at all.

Pillar A — backend module over HTTP
-----------------------------------

For the "backend admin, but no SSH" situation (restricted/managed hosting). The extension is
installed on the server and exposes an admin-only *Snapshot* module that prepares an anonymized,
single-use download of this instance's data. It is **off by default** and hardened by several
independent controls; see :ref:`backend-module`.

Scope of version 1
==================

Included:

- SSH transport and rsync file source.
- MySQL / MariaDB.
- Local fileadmin.
- Pull-first: pushing to an environment is disabled by default.

Planned for later releases (not in v1):

- ``kubectl`` transport and S3 / object-storage file source.
- PostgreSQL / SQLite.
- Notification mail and step-up re-authentication for the backend module.
- Cross-version pulls with an automatic upgrade wizard run.

Requirements
============

- TYPO3 13.4 LTS or 14.
- PHP 8.2 or newer.
- For CLI pulls: SSH access to the source, and ``rsync`` available locally and remotely.
- `helhum/typo3-console <https://packagist.org/packages/helhum/typo3-console>`__ (a hard dependency;
  used for the credential-free database export/import).
