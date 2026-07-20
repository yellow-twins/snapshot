.. include:: /Includes.rst.txt

.. _scrubbing:

======================
Scrubbing (GDPR-safe)
======================

Snapshot anonymizes personal data **by default**, so a pulled or exported copy is legally safe to
hold on a developer machine. Scrubbing runs on a copy of the data, never on the production database.

- For the **CLI pull**, scrubbing runs on the freshly imported local database.
- For the **backend export**, scrubbing runs on a throwaway temporary database (:ref:`backend-module`).

Built-in defaults
=================

Out of the box, Snapshot:

- Anonymizes ``fe_users`` — username, name fields, e-mail, address and contact columns are replaced;
  the password is cleared.
- Anonymizes ``be_users`` — real name and e-mail are replaced, and every password hash is replaced
  with the hash of the known development password (``SnapshotDev.1234!``). No real names, e-mails or
  credential hashes leave the source, yet the copy stays loginable (username + dev password).
- Truncates ``sys_log``.

Columns that do not exist on a given installation are skipped automatically.

.. _scrubbing-rules:

Custom rules
============

Add or override rules per table under ``defaults.scrub_rules`` in ``.snapshot.yaml``. Each table is
either **truncated** or has **columns overwritten**.

.. code-block:: yaml

   defaults:
     scrub_rules:
       tx_myshop_domain_model_order:
         truncate: true
       fe_users:
         set:
           email: "user{uid}@example.invalid"
           username: "user{uid}"

Column values are templates. A literal string is used verbatim; the token ``{uid}`` is replaced with
the row's ``uid`` so anonymized values (such as e-mail addresses) stay unique per row.

Your rules are merged **over** the built-in defaults, so you only need to specify what differs.

Opting out
==========

For the CLI pull, scrubbing can be disabled per project with ``defaults.scrub: false`` or per run
with ``--no-scrub``:

.. code-block:: bash

   vendor/bin/typo3 snapshot:pull --from=live --no-scrub

.. warning::

   ``--no-scrub`` leaves real personal data in your local copy. Only use it for a deliberate,
   short-lived debugging need, and never keep such a copy around.

In the backend module, the export is always anonymized unless the environment explicitly unlocks the
raw export (:ref:`backend-raw`).
