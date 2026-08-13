# Git & GitHub

Each Project has a local repository under `/var/www/git/<project-id>` and a GitHub repository.

## Initialize Repository

Initialize Repository:

1. verifies GitHub configuration
2. checks GitHub CLI
3. creates a private GitHub repository when available
4. creates the local Project repository
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

Pull performs a fast-forward pull when the working tree is clean and the branch matches the Project configuration.

## Repository Deletion

GitHub repository deletion is available only during Delete Project and only when Dev Console can safely verify the exact configured repository identity.

The deletion checkbox is unchecked by default.
