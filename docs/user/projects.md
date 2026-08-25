# Projects

A Project connects a name, domains, generated directories, a Dev Console host Git repository path, and Managed Server assignment.

## Create Project

Create Project records project configuration only. It does not initialize Git, create a GitHub repository, clone code, create Apache configuration, or deploy files.

Dev Console generates:

- Project ID
- local repository path on the Dev Console host
- Production directory
- Preview directory
- Preview domain from the Production domain

## Edit Project

Edit Project changes Project metadata such as display name, Production domain, and Managed Server assignment.

If infrastructure-affecting settings change, Dev Console marks Infrastructure as Update required.

## Infrastructure States

- Ready: infrastructure setup completed for the stored Project configuration.
- Update required: Project settings changed after setup and infrastructure should be updated.
- Setup failed: the last setup attempt failed.

## Set Up

Set up prepares Project infrastructure. For managed-server Projects it creates remote Preview and Production directories, installs Apache virtual hosts, enables them, validates Apache configuration, reloads Apache, and saves setup metadata.

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
