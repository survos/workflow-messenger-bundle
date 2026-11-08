# Workflow Messenger Bundle

Queue **native Symfony 8.2 workflow transitions** through Messenger. Symfony owns
workflow definitions, guards, marking and events. This bundle supplies an explicit
dispatcher and a worker handler; your ordinary transition listeners do the work.

**Experimental 0.1 scaffold.** Requires PHP 8.5 (our kit-bundle baseline) and
`8.2.*@dev` for its Symfony integration dependencies. No stable release or
production migration is promised yet. The example lockfile records tested source
revisions; existing applications are not upgraded by this repository change.

## Why a separate bundle?

[Symfony #61935](https://github.com/symfony/symfony/pull/61935) introduced native
workflow attributes. [#66722](https://github.com/symfony/symfony/pull/66722) added
`Place(initial: true)`, and [#66687](https://github.com/symfony/symfony/pull/66687)
made `AsWorkflow`, `Place` and `Transition` extensible, and
[#66872](https://github.com/symfony/symfony/pull/66872) gave them a native
`description` argument, which [#66890](https://github.com/symfony/symfony/pull/66890)
made the third `Transition` argument. All are merged into 8.2. This package builds on that
work instead of maintaining another definition reader or registrar.

You can use native attributes with metadata, or the optional typed attribute:

```php
use Survos\WorkflowMessengerBundle\Attribute\Transition;
use Symfony\Component\Workflow\Attribute\AsWorkflow;
use Symfony\Component\Workflow\Attribute\Place;

#[AsWorkflow(
    name: 'packages',
    supports: Package::class,
    description: 'Load package metadata from Packagist',
)]
final class PackageWorkflow
{
    #[Place('Package discovered; metadata not yet loaded', initial: true)]
    public const DISCOVERED = 'discovered';

    #[Place('Package metadata loaded')]
    public const LOADED = 'loaded';

    #[Transition(
        self::DISCOVERED,
        self::LOADED,
        description: 'Fetch package metadata from Packagist',
        transport: 'package_metadata',
    )]
    public const LOAD = 'load';
}
```

Our `Transition` extends workflow-extras and defaults to `async: true`. Its signature is:

`from, to, description, label, next, guard, metadata, icon, iconClass, async, transport, batch`

Every extras argument keeps its name, type, position and default; only `async`,
`transport` and `batch` are appended. Enum endpoints, weighted arcs, label and string-or-list
next candidates are supported. Use named arguments after description when migrating
from the old native-order signature. The attribute is repeatable. Equivalent native metadata:

```php
#[\Symfony\Component\Workflow\Attribute\Transition(
    from: self::DISCOVERED,
    to: self::LOADED,
    description: 'Fetch package metadata from Packagist',
    metadata: [
        'async' => true,
        'transport' => 'package_metadata',
    ],
)]
```

Both endpoints reference declared place constants: changing a place's stored value
requires editing only its declaration. We explicitly declare and describe every
place and transition instead of relying on inferred places. That gives humans
and tools a complete, documented definition. Native backed enum cases work too;
enums are optional.

Async requires workflow-extras and enables its bundle. Extras can still be used
without async. Native metadata remains supported for interoperability. Use one transition attribute for
each transition definition, rather than stacking competing descriptions of it.
Typed options reject conflicting metadata; `async: false` cannot specify a
transport. For repeated definitions of one transition name, async intent and the
resolved transport must agree. Routing is validated when dispatching/handling.

## Install in an experimental 8.2 application

After the split repositories have been published and registered on Packagist:

```bash
composer config minimum-stability dev
composer config prefer-stable true
composer require 'survos/workflow-messenger-bundle:dev-main' --with-all-dependencies --no-scripts
```

An existing application's Symfony constraints (including Flex's `extra.symfony.require`)
must allow 8.2 first. Review its dependency changes and commit its lockfile. Do not
expect a library's `minimum-stability` setting to override the application's.
Pin a development revision containing #66687; an older cached 8.2 lockfile can
still have final attributes. Move to a containing 8.2 release when available.

Enable `Survos\WorkflowMessengerBundle\SurvosWorkflowMessengerBundle` if Flex has not done
so. Its required bundles provide Workflow Extras, Workflow, Messenger and Survos Kit. The tested
minimal kernel runs **without FrameworkBundle, Doctrine ORM or Twig installed**;
kit still depends on HttpKernel.

With DoctrineBundle installed, the default `subject_store` is
[DoctrineSubjectStore](src/Doctrine/DoctrineSubjectStore.php): the reference is the
entity class and its single identifier, only classes a workflow `supports` are
loaded, a worker refreshes the entity before applying, and saving flushes the
entity's manager. Queuing inside an open transaction is rejected (there is no outbox).

Without Doctrine, implement [SubjectStoreInterface](src/Contract/SubjectStoreInterface.php)
and configure it as `subject_store`. A reference contains a stable `type` and string
`id`; `save()` receives the original apply context. A plain object with a `marking`
property works.

Transitions of the same subject never run concurrently: the handler loads, applies,
saves and continues each request under a per-subject lock
(`survos_workflow:<type>:<id>`), so `can()` still holds at apply time and one worker
cannot overwrite another's flush. A busy subject is retried a second later; other
subjects run in parallel. A sync continuation of the same subject re-enters the lock
it already holds. The lock is always on: the default `lock_factory: lock.factory` is
Symfony Lock's default resource (semaphore or flock, enough for workers on one host).
With workers on several hosts or containers, name a shared store, e.g. PostgreSQL
advisory locks:

```yaml
framework:
    lock:
        default: '%env(LOCK_DSN)%'
        database: { service_id: doctrine.dbal.default_connection, advisory: true }
survos_workflow_messenger:
    lock_factory: lock.database.factory
```

For a CLI run that should handle everything in-process, wrap it in
`TransitionExecutorInterface::synchronously(fn () => ...)`: every transition it
starts, including kickoffs, is dispatched with `cascade: sync`. `state:iterate --sync`
does this.

```yaml
# config/packages/survos_workflow_messenger.yaml
survos_workflow_messenger:
    # subject_store: App\Workflow\PackageStore # only without Doctrine
    default_transport: package_metadata # optional when every transition specifies one

# config/packages/messenger.yaml -- component-owned configuration in Symfony 8.2
messenger:
    transports:
        package_metadata: '%env(METADATA_TRANSPORT_DSN)%'
        package_readme: '%env(README_TRANSPORT_DSN)%'
```

Use genuine consumable transports such as configured Doctrine, Redis or AMQP
transports. Their transport packages, queue options and failure/retry policies
belong to the application. Sync and failure transports are excluded from the
bundle's selectable routes. Automatic queue provisioning is not implemented.
Configure the adapter before running application cache/warmup scripts.

## Dispatch, then execute in a worker

Persist **and commit** the subject before enqueueing:

```php
$dispatcher->dispatch(
    'packages',
    new \Survos\WorkflowMessengerBundle\Message\SubjectReference('package', $packageId),
    PackageWorkflow::LOAD,
    context: ['requested_by' => 'import'],
);
```

Inject [TransitionDispatcher](src/Service/TransitionDispatcher.php) normally.
The request contains workflow name, subject reference, transition name, plain-data
context, request ID and schema version. It is routed with `TransportNamesStamp`;
no message-class routing rule is required. The selected Messenger bus must retain
its normal send/handle middleware. Native workflow services are found by their
`workflow` tags, so injection-only workflows need not belong to the Registry.

```bash
php bin/console messenger:consume package_metadata package_readme
```

The worker loads the subject, calls native `apply()` with the supplied context,
and saves after successful execution. Standard `AsTransitionListener` listeners
run in that worker. Calling native `apply()` yourself remains synchronous even
if async metadata is present; this bundle never intercepts it.

Handler results report `applied` or `blocked` (including blocker messages). They are available through Messenger's `HandledStamp`, including to
worker event subscribers. Requests blocked by a changed marking first resume automatic
`next` work from the current places: a previous attempt may have persisted its
transition before its continuation failed. The original transition is not reapplied.
If continuation fails again, the request is retried; otherwise it is acknowledged
as `blocked`. Guard-blocked requests do not resume other work. `cascade: none`
still disables continuation. Missing subjects and configuration errors are unrecoverable failures; listener, network and
persistence failures propagate to Messenger's configured failure handling.
The scaffold does not yet supply a metrics/logging subscriber.

## Delivery and persistence boundaries

- Delivery is at least once. A request ID supports tracing, not deduplication or
  exactly-once execution. External side effects must be idempotent.
- Guards run in the worker against current state. A stale request can be blocked;
  a self-transition may still be enabled on redelivery.
- The Doctrine adapter flushes under the handler's per-subject lock. On failed
  single or batched work, stores implementing `BatchSubjectStoreInterface` discard
  unsaved changes before another message can flush them. This also covers a
  `completed` listener that throws after changing the in-memory marking.
  Custom stores own equivalent failure cleanup. No transactional outbox is provided;
  a process crash between saving and dispatching still requires reconciliation.
- A database flush is not necessarily a commit. Queuing inside an open producer
  transaction can let a worker run before it sees the subject.
- Context permits only arrays, scalars and null, not entities, closures or events.

## Run the packages example

The [small executable example](examples/packages/README.md) adapts our packages
application: fetch Packagist metadata, perform cheap validation synchronously,
then fetch the README of an eligible Symfony 8 bundle on a second queue.

From a **mono checkout**, with PHP 8.5:

```bash
cd bu/workflow-messenger-bundle/examples/packages
composer install --no-plugins --no-scripts
php demo.php --offline
# Or a real package, using Packagist and GitHub:
php demo.php symfony/monolog-bundle
composer test
```

The example uses local path repositories for both bundles and kit, a locked 8.2
dependency set, a JSON subject store, serialized in-memory transports and real
Messenger Workers. It is a bounded, single-process demonstration, not a durable
broker deployment. No network is used by its tests or offline mode.

## What is here / what comes next

Implemented: typed async transition metadata, explicit dispatch, named native
workflow lookup, transport selection, subject-store contract, handler outcomes,
real-worker example and tests.

Next: application-specific Doctrine integration in packages, durable transport
and retry/failure integration tests, optional transport provisioning, then
searchbench and harvest validation. No production claim is made for these yet.

Workflow descriptions, `next` selection and future iteration/UI belong to
[workflow-extras-bundle](https://github.com/survos/mono/tree/main/bu/workflow-extras-bundle).
The legacy [state-bundle](https://github.com/survos/state-bundle) remains supported;
do not enable two registrars for the same workflow or discard old queued message
classes during migration.

## Shared continuation execution

Extras owns NextTransitionRunner and the next listener. This bundle replaces
TransitionExecutorInterface with MessengerTransitionExecutor. Every selected
transition becomes a TransitionRequest. Unmarked transitions run through the sync
transport when configured, otherwise directly through Messenger handlers; async
metadata selects a queued transport. `cascade: sync` forces synchronous Messenger
execution, while `cascade: none` stops subsequent selection.

The handler defers next during native apply, calls SubjectStoreInterface::save(),
then selects the next transition from saved state. The store must provide a stable
reference(object), reload the subject, and make save() a safe persistence boundary.
The native completed listener never dispatches before that boundary. Applications
calling native apply directly can use an explicit post-flush adapter. A failed save
must not schedule follow-on work. No nested workflow event flush is performed.

## Batch transitions

Use `batch: 500` on an async transition to collect requests for the same workflow,
subject type and transition in a worker. Set the transport prefetch to at least
that size; otherwise a broker may deliver only one unacknowledged request at a time.
A partial group flushes after five idle seconds or when Messenger forces a flush
(for example when stopping the worker). Direct synchronous execution remains per subject.

```php
#[Transition(self::NEW, self::DISPATCHED, transport: 'media.dispatch', batch: 500)]
public const DISPATCH = 'dispatch';

#[AsBatchTransitionListener('media', self::DISPATCH)]
public function submitBatch(BatchTransitionEvent $event): void
{
    // One bulk API request using $event->subjects.
}
```

Import `AsBatchTransitionListener` from `Survos\WorkflowMessengerBundle\Attribute`
and `BatchTransitionEvent` from `Survos\WorkflowMessengerBundle\Event`.
The bulk listener runs under the same subject locks as ordinary transitions,
after missing subjects and blocked transitions have been filtered out. Duplicate
requests in a group share one application and each receive their own result.

The ordinary transition listener still runs for each subject. It can skip its
per-item API call when the context contains `BatchTransitionEvent::CONTEXT_BATCHED`.
The bulk listener can call `skip($subject)` to run that subject's ordinary listener
without the flag, or `release($subject)` to requeue it for individual execution.
Release preserves context and uses the transition's configured transport.

The default Doctrine store validates references and flushes once per manager for
the group. Custom stores can implement `BatchSubjectStoreInterface` (`saveBatch()` and
`discardBatch()`);
otherwise each subject is saved separately. All saves finish before automatic
`next` continuations and successful acknowledgments. Listener or persistence
failures discard unsaved group changes and nack pending requests; missing subjects are unrecoverable. Bulk operations
must be idempotent because retries can repeat external side effects.
