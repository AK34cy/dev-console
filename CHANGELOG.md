# Changelog

## Milestone 1 — Project infrastructure foundation

Date:
2026-07-26

- Authentication-protected local console
- Dashboard and Settings
- Apache installation and service management
- Apache site discovery
- Multi-project registry
- Production and Preview environment model
- Automatic environment directory generation
- Managed Apache VirtualHost generation
- Apache configuration validation
- Safe project setup and rollback
- Project status and drift detection
- Safe managed deletion
- Local configuration stored outside Git

## Next

- Git repository integration
- Git repository connection
- Fetch and fast-forward-only Pull
- Local Git status reporting
- Shared robust external command execution
- Reliable exit-code and timeout handling
- Global GitHub configuration
- Automatic GitHub repository creation
- Secure token-based Git operations
- Removed manual existing-repository connection flow
- Reliable GitHub CLI repository cloning
- Retryable repository initialization
- Compact GitHub and Apache Settings layout
- GitHub CLI 2.4-compatible repository bootstrap
- Local-first repository initialization and push
- Reliable PAT authentication for Git push, fetch, and pull
- Recovery of incomplete repository initialization
- GitHub API and Git transport separation
- Verified local and remote Git state
- Correct incomplete repository recovery
- Reliable Push, Fetch, and Pull workflow
- Removed false CONNECTED status
- Preview and Production deployment binding
- DNS and SSL support
- UI refinement

## Unreleased

- Added local Apache routing verification for managed Production and Preview hosts.
- Fixed routing verification upgrades for projects created before placeholder markers existed.
