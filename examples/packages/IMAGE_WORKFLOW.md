# An image workflow with independent prerequisites

This example runs `download → (thumbnail, description) → completion webhook` using
the existing `async: true` and place-level `next: [...]` attributes. Symfony owns
the split and AND-join; no application-side completion counter is needed.

From this directory, using the same installed dependencies as the package example:

```bash
php image.php
vendor/bin/phpunit tests/ImageWorkflowTest.php
```

The executable is entirely offline. Download, thumbnail, AI, and webhook responses
are fixtures supplied through `MockHttpClient`; the workflow, attribute discovery,
serialized Messenger queues, workers, listeners, and JSON persistence are real.
It uses a fresh temporary directory on every run and removes it on exit.

Expected output:

```text
image_download → {"thumbnail_pending":1,"description_pending":1}
image_thumbnail → {"description_pending":1,"thumbnail_done":1}
image_description → {"thumbnail_done":1,"description_done":1}
Completion webhook: {"id":"demo-run","thumbnail":"https:\/\/example.invalid\/thumb.jpg","description":"A museum photograph"}
image_webhook → {"completed":1}
```

## Definition and marking

See [ImageWorkflow](src/ImageProcessing/ImageWorkflow.php) for the complete native
attribute definition and transition listeners, and [Image](src/ImageProcessing/Image.php)
for the subject. The essential declaration is:

```php
#[AsWorkflow(name: self::NAME, type: WorkflowType::Workflow, supports: Image::class)]
```

`AsWorkflow` defaults to `WorkflowType::StateMachine`; explicitly choosing
`WorkflowType::Workflow` is required. Its marking is an array of place names to
token counts, such as `['thumbnail_done' => 1, 'description_pending' => 1]`.
For a Doctrine subject, persist that map as JSON rather than a scalar string.

The download transition produces both pending places. Each branch consumes only
its own pending place and produces its own done place. The completion transition
requires both done places, consumes them, and produces `completed`.

```php
#[Place(next: [self::THUMBNAIL])]
public const THUMBNAIL_PENDING = 'thumbnail_pending';

#[Place(next: [self::DESCRIBE])]
public const DESCRIPTION_PENDING = 'description_pending';

#[Place(next: [self::COMPLETE])]
public const THUMBNAIL_DONE = 'thumbnail_done';

#[Place(next: [self::COMPLETE])]
public const DESCRIPTION_DONE = 'description_done';

#[Transition(self::NEW, [self::THUMBNAIL_PENDING, self::DESCRIPTION_PENDING], async: true)]
public const DOWNLOAD = 'download';

#[Transition(self::THUMBNAIL_PENDING, self::THUMBNAIL_DONE, async: true)]
public const THUMBNAIL = 'thumbnail';

#[Transition(self::DESCRIPTION_PENDING, self::DESCRIPTION_DONE, async: true)]
public const DESCRIBE = 'describe';

#[Transition([self::THUMBNAIL_DONE, self::DESCRIPTION_DONE], self::COMPLETED, next: [], async: true)]
public const COMPLETE = 'complete';
```

## What `next` does today

The runner gathers candidates from every active place, deduplicates their names,
and starts the first enabled candidate. After the thumbnail finishes, `complete`
is still blocked but `describe` is enabled, so processing continues. Reversing the
branch completion order is equally valid.

Do not give a branch transition only `next: [self::COMPLETE]`: transition-level
metadata overrides all active-place candidates. That would stop the automatic
cascade after the first branch because the join is not yet enabled. The final
transition's `next: []` intentionally stops continuation.

This is independent **dependency ordering**, not concurrent execution. Automatic
continuation enqueues one branch at a time; if that branch fails, the other is not
automatically scheduled until it succeeds. Explicit producers can queue both
branches (the tests exercise both delivery orders), but the Messenger handler still
locks the same subject across the entire transition listener. Slow work on that
subject therefore remains serialized.

For actual concurrent work, dispatch separate jobs after saving the fork. Jobs
operate on immutable input/run identifiers and store their results independently;
short completion transitions merge the results under the subject lock. Only those
completion transitions mutate the workflow marking. Keep one owner for scheduling
each branch and for triggering the join. This requires additional job orchestration;
changing `next` to mean “all enabled” globally would break guarded alternative paths.

## Verification and delivery guarantees

[ImageWorkflowTest](tests/ImageWorkflowTest.php) exercises native attribute discovery,
automatic continuation, synchronous cascade, both explicit branch delivery orders,
premature joins, duplicate deliveries after persistence, a failing branch, and a
failing webhook followed by retry. Each worker loads state from disk again, so
the second branch must preserve the first branch's result and marking.

The webhook contains both results and carries a stable key for the processing run.
Its receiver must honor that key. A crash after an external side effect but before
saving the marking can repeat the side effect; these tests do not claim exactly-once
delivery. A new processing run needs a new run identifier.

The demo queues are in-memory and disappear on exit. The bounded demo worker has
no retry subscriber: tests explicitly requeue failed requests. Production needs
durable transports, retry/failure configuration, a lock store shared by all workers,
and an outbox or reconciliation for the save-to-dispatch crash gap. Those mechanisms
are not supplied by this example.
