.. include:: /Includes.rst.txt

.. _backend-module:

==============
Backend module
==============

The backend module (Pillar A) is for the "I have backend admin but no SSH" case. It exposes an
admin-only *Snapshot* entry under the *Tools* area that prepares an anonymized, single-use download
of this instance's database and/or fileadmin.

It is **disabled by default** and hardened by several independent controls.

.. _backend-enabling:

Enabling and configuring
========================

All controls are configured through **environment variables**, deliberately kept out of the backend
so a compromised admin cannot weaken them.

.. confval:: SNAPSHOT_BACKEND_ENABLED

   :Default: unset (off)

   Master kill-switch. Set to ``1`` to enable the module at all.

.. confval:: SNAPSHOT_ALLOWED_IPS

   :Default: unset (no restriction)

   Comma-separated IP addresses / ranges allowed to use the module. When unset, the IP control is
   not enforced.

.. confval:: SNAPSHOT_REQUIRE_MFA

   :Default: ``1`` (required)

   Multi-factor authentication must be active on the backend account. Set to ``0`` to drop this
   check — only in trusted / local contexts.

.. confval:: SNAPSHOT_ALLOW_UNSCRUBBED

   :Default: unset (off)

   Set to ``1`` to unlock an opt-in, clearly marked **raw** (un-anonymized) database export for
   local debugging. See :ref:`backend-raw`.

Security model
==============

The module combines several independent layers:

- **Admin-only** by module registration.
- **Kill-switch** — off unless explicitly enabled.
- **IP allowlist** — optional, configured in the environment.
- **Mandatory MFA** — active two-factor authentication required.
- **GDPR anonymization** — the database export is scrubbed server-side (:ref:`scrubbing`).
- **Single-use, expiring download tokens** — artifacts are stored outside the web root under a
  SHA-256-derived name (never the plaintext token), served only through the authenticated backend
  route, consumed atomically, and deleted after download.
- **Audit log** — every prepare, download and rejection is recorded to a dedicated log channel.

.. note::

   Notification mail and step-up re-authentication are planned for a later release.

The database export
===================

Selecting *Database* produces an anonymized SQL dump. The live database is **never modified**: the
export copies the live database into a throwaway temporary database, scrubs that copy, dumps it, and
drops it again. Anonymization only ever runs against the copy.

.. important::

   The anonymized database export needs the ``CREATE`` privilege so it can create the temporary
   working database. On restricted hosting where the application's database user lacks that
   privilege, the module reports a clear message and still offers the fileadmin export. If you
   control the database grants, allowing the app user to create databases with your prefix (for
   example ``GRANT ALL ON \`db\_%\`.* TO ...``) is enough.

.. _backend-raw:

Raw (un-anonymized) export
--------------------------

Sometimes you need the exact, un-scrubbed dataset locally to reproduce a bug on a specific record.
When ``SNAPSHOT_ALLOW_UNSCRUBBED=1`` is set, the module shows an additional, clearly marked
**Database (raw)** card. It is never pre-selected, is re-checked server-side (a crafted request
cannot bypass the missing card), and every raw export is audit-logged. The raw export dumps the live
database directly, so it needs no temporary database and no ``CREATE`` privilege.

Because the switch lives in the environment and not in the backend, a backend admin can never obtain
un-anonymized data unless someone with server / ``.env`` access has explicitly opted in.
