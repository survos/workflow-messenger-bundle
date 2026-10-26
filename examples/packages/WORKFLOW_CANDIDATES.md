# Workflow candidates: Harvest and Mediary

Reviewed the local working trees on 2026-10-26, including their in-progress changes.
Both applications already register workflow-extras and workflow-messenger. Both
current application definitions use scalar state-machine markings. The new image
example demonstrates a separate multi-place workflow without changing those schemas.

## Implemented: one owner for Mediary's AI continuation

`mediary/src/Workflow/AssetFlow.php` gives `ai_ready` two guarded next candidates:
`ai_task` and `ai_done`. `AssetWorkflow::onCompleted()` also advances the AI queue
explicitly after each task, and `completeBatchedTask()` resumes it after an external
provider callback.

The new bundle handler additionally runs the place's next candidates after saving.
Without an override on `ai_task`, this schedules two completion messages for a
one-task queue. With multiple tasks, the automatic chain also sees the repeated
`ai_ready`/`ai_task` pair as a cycle, even though the task queue is making progress.

Fix: `next: []` on the `ai_task` transition. Entering `ai_ready` still starts work
through the place metadata. Subsequent tasks and batch callbacks remain owned by
the existing explicit queue driver. The general cycle protection stays intact.

The regression test uses the actual app attributes, real `onAiTask` and
`onCompleted` listeners, and the installed Messenger handler/next runner. Only AI
execution, persistence, and the message bus are isolated. Before the fix, a
one-task queue produces two `ai_done` requests; two- and three-task queues throw
the cycle exception. Coverage includes zero to three tasks and a provider batch
that must wait for its callback. This does not verify real database transactions
or external provider delivery.

## Best multi-place candidate: Mediary image derivatives and AI

Current automatic image path:

```text
archive → info → warm_thumb → queue_ai → ai_task* → ai_done
```

Recommended future dependency shape:

```text
                         ┌─ thumbnail warmup ───────────────┐
archive → metadata ready ┤                                  ├─ required results ready → complete
                         └─ requested AI tasks ─────────────┘
```

`AssetWorkflow::onWarmThumb()` requests an Imagor thumbnail from the retained
source. It records `thumb_warmed_at`; it does not create the URL used by AI.
`selectTaskImageUrl()`/`preferredThumbnailUrl()` independently choose an IIIF,
small-image, or proxy URL and can fall back to the full image. Thus warming and
thumbnail-based enrichment have no hard data dependency on each other. Warming
may still reduce the first AI request's latency; measure rather than assume the
net performance gain.

The current same-subject lock covers the whole transition. A multi-place marking
alone would remove the artificial dependency but would not overlap the expensive
operations. True overlap needs separate jobs and short result-completion transitions,
as described in [the example](IMAGE_WORKFLOW.md). Do not remove that lock: both jobs
otherwise risk overwriting the same Asset JSON fields and marking.

Keep these boundaries explicit during a migration:

- `ocr_mistral → layout` is a useful dependency: layout can reuse the OCR response
  rather than make another provider call (`mediary/src/Ai/AssetAiTask.php`).
- Triage is deliberately opt-in; comments in `AssetFlow` document its CPU cost.
  Do not introduce it into the default cascade just because it can run independently.
- PDF metadata/Tiny processing creates or updates page assets. Per-page work must
  wait for those records and source references (`AssetWorkflow::onInfo()`).
- Existing `/meta` reuse avoids duplicate source analysis. Do not split it into
  multiple requests for the same metadata just to create more workflow branches.
- Provider batching already exists through `AssetAiBatchSubmitter`; retain its
  pending/locked/callback contract rather than dispatching each batched task twice.
- Webhooks currently report progress as well as terminal outcomes whenever there
  is an archive URL. Preserve progress notifications; introduce an explicit final
  result boundary instead of silently withholding every callback until the join.

For migration, introduce a separate JSON processing marking or migrate the scalar
marking explicitly. Current guards, admin filters, callbacks, and Harvest consumers
expect scalar states. Keep the outward status contract until those consumers have
been migrated and tested. Include a processing-run identifier so a late result from
an earlier rerun cannot satisfy the current run's join.

## Harvest: keep the existing external-work join

`harvest/src/Workflow/IDatasetWorkflow.php` is a state machine:

```text
raw → normalize/register requests → normalized → dispatch → dispatched
                                                            │
                    media callbacks + translation callbacks ─┘
                                                            ↓
                                                         enrich → folio
```

`DatasetRequestRegistrar::register()` creates media and translation requests; their
individual workflows dispatch independently. Its registration barrier prevents
the join from opening before all requests have been registered. `DatasetEnrichGuard`
checks that barrier, selected unpublished translations, and the dataset's media
policy. `DatasetRequestGate` and `DatasetMediaGateListener` supply callback wakeups.
This already expresses a useful fan-out/join across services. No need to represent
every image as a token in the dataset marking.

Recommended next optimization: unify callback wakeup eligibility behind
`DatasetRequestGate`. The media listener still accepts both `normalized` and
`dispatched`, while the request gate deliberately accepts only `dispatched` to
avoid racing dispatch and scheduling duplicate enrichment/folio builds. The media
listener also checks only images before enqueueing; the request gate checks
translations too. Keep the guard authoritative and test late, duplicate, and
out-of-order callbacks before changing this path.

Preserve `MediaMode` policy: Analyze waits for terminal AI outcomes; Archive permits
the archived boundary; Off/Reference do not hold the folio for media. Reducing every
dataset to “wait for AI” would slow down archive-only use cases. Conversely, treating
all datasets as archive-only could publish a folio before its requested AI results.

Do not run enrich and folio independently: enrich writes the data the folio consumes.
Do not remove the registration barrier to speed up callback handling.

## Rollout order

1. Keep the tested single-owner AI continuation fix and the executable multi-place
   example as the reference behavior.
2. Consolidate Harvest callback wakeup checks with regression coverage for both
   media and translation completion orders.
3. Pilot a separate Mediary processing workflow for thumbnail warming and one
   independent AI task. Benchmark provider time, queue delay, duplicate dispatches,
   and end-to-end completion before broad migration.
4. Add durable run/job identity and publication recovery before using concurrent
   jobs as a production completion guarantee. Keep OCR/layout and PDF/page ordering.

The existing unrelated OCR, metadata, migration, and survey edits were preserved.
No production data, queues, schema, or service configuration were changed here.
