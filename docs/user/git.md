# Git & GitHub

## How Git & GitHub Work in Dev Console

Dev Console uses one global GitHub configuration. The GitHub account or organization and Personal Access Token are configured once in Settings, and every Project repository uses that global GitHub account/authentication. Projects do not have separate GitHub credentials.

Every Dev Console Project has its own local Git repository on the Dev Console host under:

```text
/var/www/git/<project-id>
```

Each Project also has a corresponding private GitHub repository in the configured GitHub account. Codex works in the local Project repository on the Dev Console host. Git commit, fetch, pull, and push operations also happen on the Dev Console host.

Managed Servers do not require Git for Dev Console operation. Preview deployment prepares files from the Dev Console host repository and transfers them to the Managed Server using SSH/rsync. Production deployment promotes Preview to Production on the Managed Server using rsync.

```text
                    GitHub
              private repository
                     ^
                     |
                fetch / push
                     |
                     v
              DEV CONSOLE HOST
        /var/www/git/<project-id>
              Git repository
                     |
                   Codex
                     |
              prepare source
                     |
                SSH / rsync
                     v
              MANAGED SERVER
                     |
                  Preview
                     |
                   rsync
                     v
                Production
```

The Projects Git section shows repository status and repository operations for the selected Project. It is not GitHub account configuration.

## Lifecycle

1. Create Project: creates Project configuration only. It does not initialize Git.
2. Initialize Repository: creates the local repository on the Dev Console host and the corresponding private GitHub repository.
3. Work / Codex: changes are made in the local repository and committed/pushed from the Dev Console host.
4. Deploy Preview: Dev Console prepares the selected Git state and transfers files to Preview via SSH/rsync.
5. Deploy Production: Preview content is promoted to Production on the Managed Server.

## Initialize Repository

Initialize Repository:

1. verifies GitHub configuration
2. checks GitHub CLI
3. creates a private GitHub repository when available
4. creates the local Project repository on the Dev Console host
5. writes initial files
6. commits
7. pushes `main`
8. saves repository metadata

Repository visibility is private.

## Repository Collisions

If the preferred GitHub repository already exists, Dev Console does not delete it, attach it, overwrite it, or assume it belongs to the current Project.

Dev Console can suggest another repository name, such as `project-2`, while keeping the Project ID unchanged.

## Fetch and Pull

Fetch updates remote tracking state and Last Fetch metadata.

Pull performs a fast-forward pull when the working tree is clean and the repository is on the expected branch. Dev Console v1 stores a Project `branch` value, defaults it to `main`, and currently exposes no branch selector in the UI. Some repository workflows are therefore `main`-specific.

## Repository Deletion

GitHub repository deletion is available only during Delete Project and only when Dev Console can safely verify the exact configured repository identity.

The deletion checkbox is unchecked by default.
