.. _start:

========
Snapshot
========

:Extension key: snapshot
:Package name:  yellow-twins/snapshot
:Version:       |release|
:Language:      en
:License:       This document is published under the
                `Creative Commons BY 4.0 <https://creativecommons.org/licenses/by/4.0/>`__
                license.

Developer provisioning for TYPO3: pull the database and fileadmin from any environment onto your
local machine, fast. **Snapshot is not a backup tool** — no scheduler, no off-site storage, no
restore-to-production. It exists to get real data onto a developer machine in minutes: onboarding a
new developer, or refreshing a stale local database.

Two ways in, one job:

- **CLI over SSH** — the primary path. Installed as a ``require-dev`` dependency and run from your
  own machine, so production is never touched. See :ref:`cli-usage`.
- **Backend module** — for the "I have backend admin but no SSH" case, hardened by defence in
  depth. See :ref:`backend-module`.

Pulled and exported data is **GDPR-anonymized by default** (see :ref:`scrubbing`).

.. toctree::
   :maxdepth: 1
   :hidden:

   Introduction/Index
   Installation/Index
   CliUsage/Index
   Configuration/Index
   BackendModule/Index
   Scrubbing/Index
   Ddev/Index
   Troubleshooting/Index
