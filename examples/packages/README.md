# A package README workflow

Two slow steps, two queues, ordinary Symfony listeners:

```text
discovered --LOAD [package_metadata]--> loaded
                                           | validate (guarded, synchronous)
                                           v
                                         valid --FETCH_README [package_readme]--> readme_checked

loaded --reject (guarded, synchronous)--> rejected
```

This is a small adaptation of our packages application's workflow. Start with one
known package so the example is about async transitions, not package discovery.
Searching Packagist for `type:symfony-bundle`, optionally filtering a vendor, is a
useful next command. Walking composer dependencies or collecting AGENTS.md adds
repository-discovery questions that are unnecessary for this first demonstration.

## Run from mono

Requires PHP 8.5 and Composer. The path repositories deliberately use the sibling
bundle sources in mono; when running a split-repository checkout, replace the
extras/kit path repositories with their published packages or equivalent sibling
checkouts first.

```bash
cd bu/workflow-messenger-bundle/examples/packages
composer install --no-plugins --no-scripts
php demo.php --offline
php demo.php symfony/monolog-bundle
composer test
```

The first command after installation is fully offline and deterministic. The
second makes public Packagist and GitHub requests. Each package's JSON state is
saved under `var/subjects/`; a second run refuses to overwrite it. Delete that
package's demo JSON file to repeat from the beginning.

Expected offline output:

```text
Queued load on package_metadata; marking is still discovered
Validation: valid
Queued fetch_readme on package_readme
demo/example-bundle: readme_checked; README found (58 bytes)
```

## What this proves

- All three extras attributes are discovered through Symfony's native registration.
- Async's typed Transition routes native metadata to separate configured transports.
- Enqueueing leaves the stored marking unchanged. A real Messenger Worker consumes
  a serialized message, loads a fresh subject, executes a normal transition listener
  and persists the new marking.
- A plain PHP subject with a marking property is enough. JSON persistence keeps the
  demo small; no Doctrine entity, FrameworkBundle, ORM or Twig is installed.
- Guard listeners gate cheap validation and recheck README eligibility in the worker.
- Explicit orchestration schedules the next async step **after** saving validation.
- Missing README returns `missing`; a failed network request does not advance saved
  state or become a missing README. Stale requests are acknowledged as blocked.

The executable intentionally uses **in-memory transports in one process**. The
worker really runs, and serialization is enabled, but these queues disappear when
the process exits. `RunQueue` stops after one message (or ten seconds) and has no
retry subscriber. The example tests failures being rejected, not durable retries.
For background processes, configure a durable Messenger transport and run normal
`messenger:consume` workers with application retry/failure configuration.

## Package selection policy

The metadata listener expands Packagist P2 metadata, selects its first stable
release, and requires `type: symfony-bundle`, a non-abandoned release, compatible
PHP and a declared Symfony core constraint accepting 8.2.0. It checks a documented
short list of core components in the source. This is a tutorial eligibility check,
**not a Composer dependency solver** or a guarantee that every dependency installs.
Packages without one of those core requirements are skipped.

The README listener supports public GitHub source repositories and uses the exact
source reference from that release. Other hosts finish with `unsupported_source`.
A GitHub 404 finishes with `missing`; that can also mean an inaccessible repository
or unavailable reference, not necessarily an absent README. Rate limits, auth
errors other than 404 and server errors propagate. No GitHub token is required
for the small public example, but unauthenticated API limits apply.

The real packages application should reuse its own richer eligibility policy and
persistence, rather than replace them with this tutorial's heuristic.

## Persistence and migration

The JSON adapter atomically replaces files but does not lock a subject for an entire
transition. It is suitable for this single-worker demonstration, not concurrent
production processing. A Doctrine adapter belongs to the application until its
transaction, manager-selection and concurrency contracts have been exercised.

Next validation targets: packages (one workflow), searchbench (broader behavior),
then harvest (private, heavier usage). No existing application is changed here.

`composer.lock` records the actual 8.2 development source references tested. The
bundle manifests constrain the 8.2 development line; they do not embed a temporary
PR patch. The tests and offline example make no live HTTP requests.

## Multi-place image example

Run `php image.php` for the offline download/thumbnail/description/join example.
See [the workflow walkthrough](IMAGE_WORKFLOW.md) and
[Harvest/Mediary candidate review](WORKFLOW_CANDIDATES.md).
