# Production

Production is the live environment for the Project.

## Relationship to Preview

Production deployment promotes the current remote Preview contents to the remote Production path.

It does not automatically run after Preview deployment.

For Composer projects, dependencies are prepared during Preview deployment. Production promotes the already-prepared Preview tree, including `vendor/`, and does not run `composer install` again.

## Deploy Production

Deploy Production:

1. requires a successful Preview deployment
2. requires Production preflight for the current Preview commit
3. blocks deployment if the preflight finds unreviewed Production files that would be deleted
4. checks that remote Preview is readable and non-empty
5. prepares the remote Production path
6. removes explicitly approved Production deletion candidates with managed privileges when required
7. runs remote rsync from Preview to Production with `--delete`, excluding `.git/` and `TASKS/`
8. verifies Production is readable and non-empty
9. saves Production deployment metadata

## Production Preflight

Preflight is read-only. It compares the remote Preview tree that would be promoted with the current remote Production tree and reports adds, updates, deletes, and preserved paths.

If Production contains a file that Preview does not contain, Dev Console treats that as a review item because normal `rsync --delete` would remove it. Add a preserve rule only when the file is intentionally Production-local. Preserve rules are relative to the Production root and prevent that path from being deleted or overwritten during Production promotion.

If the remaining deletion candidates are intentionally obsolete, use Approve deletions. Approval applies only to the current preflight result. Refreshing preflight or changing the deletion set requires approval again.

When approved deletion candidates are owned by root or another user, Dev Console removes only those exact approved paths using the Managed Server privilege model (`sudo -n` for non-root deployment users, direct execution for root). The actual Preview-to-Production rsync still runs as the configured deployment user so newly synchronized files keep the normal deployment ownership model.

If rsync fails after synchronization starts, Production may have been partially updated. Review the operation log, rerun Production preflight, and retry only after the reported filesystem or permission issue is resolved.

Dev Console still excludes repository internals and task artifacts from Production promotion:

- `.git/`
- `TASKS/`

## Version States

Production may match Preview or be behind Preview depending on the latest deployment. The Dashboard displays deployment metadata so the current state can be reviewed before promotion.

## Practical Scenario

Preview approved -> Refresh Preflight -> Deploy Production.
