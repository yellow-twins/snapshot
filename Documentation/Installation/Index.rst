.. include:: /Includes.rst.txt

.. _installation:

============
Installation
============

Snapshot is installed with Composer. How you require it depends on which pillar you use.

For CLI pulls (recommended)
===========================

The CLI is meant to run on developer machines only, so install it as a development dependency. The
extension never has to be present on the server this way.

.. code-block:: bash

   composer require --dev yellow-twins/snapshot

For the backend module
======================

The backend module (:ref:`backend-module`) runs on the server, so there it must be a regular
dependency:

.. code-block:: bash

   composer require yellow-twins/snapshot

.. note::

   The backend module is **disabled by default**. Installing it changes nothing until you set
   ``SNAPSHOT_BACKEND_ENABLED=1`` in the environment. See :ref:`backend-module`.

After installation
==================

Copy the configuration template and adjust it to your environments:

.. code-block:: bash

   cp vendor/yellow-twins/snapshot/.snapshot.yaml.dist .snapshot.yaml

``.snapshot.yaml`` holds structure only; secrets belong in ``.env`` and are referenced via
``%env(...)%``. Add ``.snapshot.yaml`` to ``.gitignore`` (or commit a redacted form). See
:ref:`configuration`.

Verify the setup with the preflight check:

.. code-block:: bash

   vendor/bin/typo3 snapshot:doctor --from=stage
