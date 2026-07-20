.. include:: /Includes.rst.txt

.. _configuration:

=============
Configuration
=============

The CLI is configured through ``.snapshot.yaml`` in the project root. It holds **structure only** —
anything sensitive (hosts, users, paths, credentials) belongs in ``.env`` and is referenced with
``%env(NAME)%`` placeholders, which are resolved at runtime.

.. code-block:: yaml
   :caption: .snapshot.yaml

   environments:
     live:
       transport: ssh
       host: "%env(SNAP_LIVE_HOST)%"
       user: "%env(SNAP_LIVE_USER)%"
       port: 22
       path: "%env(SNAP_LIVE_PATH)%"
       file_source: rsync

   defaults:
     scrub: true

Environments
============

Each entry under ``environments`` describes one source.

.. confval:: transport

   :Default: ``ssh``

   How Snapshot reaches the environment. ``ssh`` in v1; ``kubectl`` is planned.

.. confval:: host / user / port

   SSH connection details. ``port`` defaults to ``22``.

.. confval:: path

   Absolute path to the TYPO3 project root on the server.

.. confval:: file_source

   :Default: ``rsync``

   How the fileadmin is transferred. ``rsync`` in v1; ``s3`` is planned.

.. confval:: db
   :name: env-db

   Optional explicit database connection block (``host``, ``port``, ``name``, ``user``,
   ``password``). Recommended for the common hosting setup where the real credentials are injected
   as web-context environment variables (via ``additional.php``) and are therefore **not** present
   in the SSH shell — in that case reading ``settings.php`` is not enough. If omitted, Snapshot
   reads the connection from the remote ``settings.php``.

   .. code-block:: yaml

      db:
        host: "%env(SNAP_STAGE_DB_HOST)%"
        port: 3306
        name: "%env(SNAP_STAGE_DB_NAME)%"
        user: "%env(SNAP_STAGE_DB_USER)%"
        password: "%env(SNAP_STAGE_DB_PASSWORD)%"

Defaults
========

.. confval:: scrub

   :Default: ``true``

   Whether pulled databases are anonymized. Keep this on for any production source. See
   :ref:`scrubbing`.

.. confval:: db_exclude

   Table-name patterns (fnmatch) whose **data** is skipped in the dump; their structure is kept.
   Sensible defaults: ``cache_*``, ``[bf]e_sessions``, ``sys_log``, ``sys_history``,
   ``sys_file_processedfile``.

.. confval:: rsync_excludes

   fileadmin path patterns skipped during the transfer (e.g. ``_processed_/**``, ``_temp_/**``).

.. confval:: scrub_rules

   Extra or overriding anonymization rules, merged over the built-in defaults. See
   :ref:`scrubbing-rules`.

.. _config-post-pull:

.. confval:: post_pull

   Steps run automatically after a successful pull, to make the site immediately runnable:

   ``database_schema_update``
      Runs ``database:updateschema "*.add"`` so tables/fields the local code expects but the pulled
      schema lacks are created. Only additive changes — type changes are excluded because they can
      fail on legacy data. Runs only when the database was pulled.

   ``referenceindex``
      Rebuilds the reference index.

   ``reset_admin_password``
      Resets all backend admin passwords to a known development password
      (``SnapshotDev.1234!``). Runs only when the database was pulled.

   ``cache_flush``
      Flushes all caches.

Guards
======

.. confval:: push_to_live

   :Default: ``false``

   Snapshot is pull-first. Pushing to an environment is disabled unless explicitly enabled.
