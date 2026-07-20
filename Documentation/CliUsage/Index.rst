.. include:: /Includes.rst.txt

.. _cli-usage:

=========
CLI usage
=========

All commands are TYPO3 console commands, run through ``vendor/bin/typo3``.

``snapshot:pull``
=================

Pulls the database and/or fileadmin from a configured environment onto your local machine.

.. code-block:: bash

   vendor/bin/typo3 snapshot:pull --from=live

Options
-------

.. confval:: --from (-f)

   :Required: yes

   Source environment name, as defined under ``environments`` in ``.snapshot.yaml``.

.. confval:: --db
   :name: pull-db

   Pull the database only.

.. confval:: --files

   Pull the fileadmin only. When neither ``--db`` nor ``--files`` is given, both are pulled.

.. confval:: --dry-run

   Show what would happen — including a transfer-size preview — without writing anything locally.

.. confval:: --no-scrub

   Skip GDPR anonymization of the imported database. Not recommended; the local copy then contains
   real personal data from the source. See :ref:`scrubbing`.

.. confval:: --yes (-y)

   Do not ask for confirmation (useful in non-interactive / CI contexts).

What a pull does
----------------

#. Shows a size preview and asks for confirmation (unless ``--yes``).
#. **Database:** exported on the remote with ``typo3 database:export`` (credential-free) and
   imported locally with ``typo3 database:import``. A live byte counter is shown for the dump and a
   real percentage for the import. Excluded tables (``defaults.db_exclude``) keep their structure
   but lose their data.
#. **Fileadmin:** synced with ``rsync`` (incremental, with a live progress bar). This is a *merge*,
   not a mirror: remote files overwrite matching local ones and new files are added, but
   local-only files are kept.
#. **Scrubbing:** the imported database is anonymized (unless ``--no-scrub``).
#. **Post-pull hooks:** schema alignment, reference index, admin password reset, cache flush — see
   :ref:`config-post-pull`.

.. warning::

   A pull **replaces your local database** and merges into your local fileadmin. Make sure you are
   pointing at the right local instance.

``snapshot:doctor``
===================

Preflight check for an environment: verifies the local tools, the SSH connection, the remote
``settings.php`` / fileadmin path, and remote database reachability.

.. code-block:: bash

   vendor/bin/typo3 snapshot:doctor --from=stage

Run this first whenever a pull misbehaves — it pinpoints missing tools, SSH problems, or a
container-internal database host that needs an explicit ``db:`` block.

``snapshot:list-envs``
======================

Lists the environments defined in ``.snapshot.yaml``.

.. code-block:: bash

   vendor/bin/typo3 snapshot:list-envs
