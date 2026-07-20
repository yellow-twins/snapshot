.. include:: /Includes.rst.txt

.. _troubleshooting:

===============
Troubleshooting
===============

Run ``snapshot:doctor --from=<env>`` first — it pinpoints most connection and tool problems before a
pull.

"Could not read remote database configuration"
==============================================

The real credentials are often injected as web-context environment variables (via
``additional.php``) and are **not** present in the SSH shell, so reading ``settings.php`` yields only
container defaults. Add an explicit ``db:`` block to the environment in ``.snapshot.yaml`` (see
:ref:`configuration`). This is also the fix when ``doctor`` reports a container-internal database
host such as ``db``.

Backend database export: "needs the CREATE privilege"
=====================================================

The anonymized backend export creates a temporary working database and therefore needs the
``CREATE`` privilege. Managed hosting often denies this to the application's database user. Options:

- Grant the app user permission to create databases with a prefix, e.g.
  ``GRANT ALL ON \`db\_%\`.* TO '<user>'@'%';``
- Or use the CLI pull (:ref:`cli-usage`) from a machine that has broader database rights.
- The module always still offers the fileadmin export when the database export is unavailable.

Database export fails with "Access denied" although the site works
==================================================================

A ``~/.my.cnf`` with a ``[client]`` ``password=`` entry overrides ``MYSQL_PWD`` (option files take
precedence), which can send the wrong password to ``mysql`` / ``mysqldump``. Snapshot runs these
tools with ``--no-defaults`` to avoid exactly this, so an up-to-date version is not affected. If you
script around Snapshot's clients, apply the same flag.

SSH connection fails
====================

Snapshot connects non-interactively (``BatchMode=yes``). Make sure key-based authentication works
without a passphrase prompt:

.. code-block:: bash

   ssh -o BatchMode=yes <user>@<host>

Inside DDEV, run ``ddev auth ssh`` first (see :ref:`ddev`).

Database transfer is slow or "size unknown"
===========================================

Snapshot prefers ``typo3 database:export`` on the remote, which resolves credentials itself but
cannot report an information-schema size up front (so the preview may show "size unknown"). This is
expected and does not affect the transfer.
