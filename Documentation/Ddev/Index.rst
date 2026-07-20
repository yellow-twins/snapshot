.. include:: /Includes.rst.txt

.. _ddev:

================
DDEV integration
================

Snapshot ships a DDEV add-on that wraps the CLI in native ``ddev`` commands, so you can pull without
remembering the ``vendor/bin/typo3`` invocations.

Install the add-on
==================

.. code-block:: bash

   ddev add-on get yellow-twins/snapshot

This registers three web commands:

.. code-block:: bash

   ddev snapshot-pull --from=live
   ddev snapshot-doctor --from=stage
   ddev snapshot-list-envs

They accept the same options as the underlying :ref:`CLI commands <cli-usage>`.

SSH access from the container
=============================

Pulls run inside the DDEV web container, so the container needs your SSH key. Authorize it once per
session:

.. code-block:: bash

   ddev auth ssh

Then run ``ddev snapshot-doctor --from=<env>`` to confirm the connection before pulling.
