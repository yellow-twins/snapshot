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

Developer provisioning for TYPO3: pull database and fileadmin from any environment to your
local machine. **Snapshot is not a backup tool** — no scheduler, no off-site storage, no
restore-to-production. It exists to get real data onto a developer machine fast.

.. note::

   🚧 This extension is in early development. Documentation will grow alongside the
   milestones described in ``ROADMAP.md``.

Overview
========

- **CLI over SSH** — installed as a dev dependency; production is never touched.
- **Backend module** — for the "backend admin, no SSH" case, behind a 9-layer security model.
- **GDPR-safe by default** — pulled data is anonymized before it lands locally.
- **DDEV-native** — ships ``ddev snapshot-pull`` commands and a DDEV add-on.
