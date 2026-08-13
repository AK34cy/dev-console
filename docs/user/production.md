# Production

Production is the live environment for the Project.

## Relationship to Preview

Production deployment promotes the current remote Preview contents to the remote Production path.

It does not automatically run after Preview deployment.

## Deploy Production

Deploy Production:

1. requires a successful Preview deployment
2. checks that remote Preview is readable and non-empty
3. prepares the remote Production path
4. runs remote rsync from Preview to Production with `--delete`
5. verifies Production is readable and non-empty
6. saves Production deployment metadata

## Version States

Production may match Preview or be behind Preview depending on the latest deployment. The Dashboard displays deployment metadata so the current state can be reviewed before promotion.

## Practical Scenario

Preview approved -> Deploy Production.
