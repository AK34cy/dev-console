# Projects

A Project connects a name, domains, configured Preview/Production paths, a Dev Console host Git repository path, and Managed Server assignment.

## Create Project

Create Project records project configuration only. It does not initialize Git, create a GitHub repository, clone code, create Apache configuration, or deploy files.

Dev Console generates:

- Project ID
- local repository path on the Dev Console host
- Production directory
- Preview directory
- Preview domain from the Production domain

These generated directories are defaults for new Projects. Adopted Projects may preserve existing Preview and Production paths outside `/var/www/projects/<project-id>`.

## Add Existing Project

Add Existing Project imports an already-hosted project into Dev Console without changing the existing website.

The normal flow is:

1. Scan a Managed Server.
2. Inspect a discovered site or project source.
3. Review the adoption plan.
4. Confirm Adopt Project.

Adoption copies the selected source into a local Dev Console host repository at `/var/www/git/<project-id>`, preserves existing Git/TASKS history when present, and registers the Project after the import succeeds. Existing Preview and Production paths are adopted in place. Dev Console does not modify Apache, deploy files, create GitHub repositories, or push to GitHub during adoption.

## Edit Project

Edit Project changes Project metadata such as display name, Production domain, and Managed Server assignment.

If infrastructure-affecting settings change, Dev Console marks Infrastructure as Update required.

## Infrastructure States

- Ready: infrastructure setup completed for the stored Project configuration.
- Update required: Project settings changed after setup and infrastructure should be updated.
- Setup failed: the last setup attempt failed.

## Set Up

Set up prepares Project infrastructure. For managed-server Projects created by Dev Console it creates remote Preview and Production directories, installs Apache virtual hosts, enables them, validates Apache configuration, reloads Apache, and saves setup metadata. Adopted-in-place Projects preserve their existing Preview, Production, and Apache state when that infrastructure is already recorded as configured.

## Retry Setup

Retry Setup appears after setup fails. It runs the same setup operation again after the reported issue has been corrected.

## Update Infrastructure

Update Infrastructure appears when infrastructure-affecting Project settings changed after setup. It runs the same setup operation against the current Project configuration.

## Remove from Console

Remove from Console removes only the Project registration from Dev Console.

It preserves:

- remote directories
- Apache configuration
- local Git repository
- GitHub repository

## Delete Project

Delete Project removes Dev Console-managed infrastructure and the Project registration.

It preserves the local Git repository. It preserves the GitHub repository unless the deletion dialog shows a safely verified repository and the GitHub deletion checkbox is explicitly selected.
