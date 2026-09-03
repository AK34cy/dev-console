# IOVON Dev Console --- Product & User Guide

> **Status:** Working draft\
> **Purpose:** Explain what IOVON Dev Console is, why it exists, who it
> is for, how it is used, and how its security and architecture are
> designed.\
> Detailed technical documentation is maintained separately.

## 1. What is IOVON Dev Console?

**IOVON Dev Console turns AI coding into a controlled website
development and deployment workflow.**

You describe what you want changed. An AI coding agent changes the
source code. Dev Console keeps those changes in Git, synchronizes them
with GitHub, deploys them to a separate Preview environment, and lets
you explicitly promote the tested version to Production.

The idea is deliberately simple:

**Ask → Change → Review → Preview → Approve → Production**

Dev Console sits between an AI coding agent and the websites it works
on. Its purpose is not to make AI more autonomous. Its purpose is to
make AI-assisted development **manageable, visible, repeatable, and
safer**.

Today the supported coding engine is Codex CLI. The architecture can
later support additional engines, but the current product is
intentionally focused on one working end-to-end workflow.

------------------------------------------------------------------------

## 2. Why does it exist?

AI coding tools are already capable of making useful changes to real
websites. The difficult part is everything around the code change.

A typical request may sound trivial:

> Change these prices, replace the logo, fix this form, and add a new
> page.

But using an AI coding tool directly still leaves the user responsible
for many operational questions:

-   Which copy of the source code should the AI modify?
-   What exactly did it change?
-   Is the change recorded in version control?
-   Was it synchronized with the remote repository?
-   How can the result be tested without touching the live site?
-   How does the tested version reach Production?
-   What happens if the AI makes a bad change?
-   How are several websites and servers managed consistently?
-   Who is allowed to perform privileged server operations?

Developers can solve all of these problems manually with Git, SSH, shell
scripts, deployment tools, and their own procedures.

Dev Console packages that workflow into one place.

The goal is not to replace professional development practices. It is to
make a useful subset of those practices available as a coherent workflow
for AI-assisted website work.

------------------------------------------------------------------------

## 3. Who is it for?

### Website owners without a permanent developer

A person or small company may operate one or several websites but not
have a developer continuously available.

Many changes are small enough to describe in normal language, yet risky
enough that editing Production directly is undesirable.

Dev Console provides a controlled path from the request to a tested
change.

### Freelance developers and consultants

A developer or consultant may maintain several unrelated customer
websites on different servers.

Dev Console provides a common place for projects, AI tasks, Git
repositories, Preview deployments, and Production promotion instead of
repeating the same SSH/Git/deployment routine for every site.

### Small web teams and agencies

A small team can use an AI coding agent as another implementation
resource while retaining a recognizable development and release process.

AI changes code; it does not become the release authority.

### Technical owners of existing websites

Dev Console is not limited to new projects.

An existing website can be inspected and adopted into the workflow,
after which its source can be managed through Git, Preview, and
controlled Production deployment.

------------------------------------------------------------------------

## 4. Typical use cases

### Use case 1 --- Make a small change to a live website

A website owner wants to change prices, text, images, a form, or a page.

1.  Create a task in Dev Console.
2.  Describe the required change.
3.  Run the AI coding agent.
4.  Review the resulting task and Git state.
5.  Deploy the result to Preview.
6.  Open the Preview site in a browser and test it.
7.  Explicitly promote the tested Preview version to Production.

The AI does not need to edit the live website directly.

### Use case 2 --- Maintain several websites

A freelancer or administrator maintains multiple projects.

Each project has its own:

-   source repository;
-   GitHub repository;
-   Managed Server;
-   Preview environment;
-   Production environment;
-   task history;
-   deployment state.

Dev Console provides one workflow across them.

### Use case 3 --- Use AI inside a normal development process

A developer wants AI to implement tasks but does not want to surrender
source control or deployment control.

The task becomes a traceable unit of work. Changes are committed to Git
and synchronized with GitHub. Preview and Production remain separate
stages.

The developer can use AI for implementation while retaining the normal
control points around it.

### Use case 4 --- Adopt an existing website

A website already exists on a server and may have years of history
outside Dev Console.

Dev Console can inspect an existing candidate, adopt it as a managed
project, establish the development repository and Preview/Production
model, and then use the same workflow for future changes.

This allows Dev Console to be introduced without rebuilding the website
from scratch.

------------------------------------------------------------------------

## 5. How normal work looks

The normal user workflow is intentionally linear:

``` text
Task
  ↓
AI coding agent
  ↓
Local Git repository
  ↓
GitHub
  ↓
Preview
  ↓
Human review
  ↓
Production
```

### Step 1 --- Describe the task

The user writes what should change.

The task is stored with the project rather than existing only as an
ephemeral AI chat.

### Step 2 --- Let the AI work on the project source

Codex runs against the selected project workspace.

The AI works on source code. It is not given authority to publish the
result directly to Production.

### Step 3 --- Record the result

The resulting change is validated, committed to Git, and synchronized
with GitHub as part of the managed workflow.

Git remains the source history.

### Step 4 --- Deploy to Preview

Dev Console deploys the selected project version to the configured
Preview directory on the Managed Server.

Preview is a real web environment where the result can be opened and
tested.

### Step 5 --- Decide

The user reviews the actual website.

If the result is wrong, the workflow returns to development.

If the result is acceptable, the user explicitly chooses to deploy it to
Production.

### Step 6 --- Promote to Production

Before Production deployment, Dev Console performs a preflight
comparison.

Potential deletions are surfaced rather than silently accepted. Where
deletion approval is required, it is tied to the exact preflight result.

Production deployment then promotes the tested Preview contents to
Production.

------------------------------------------------------------------------

## 6. Why use Dev Console instead of just using an AI coding tool?

An AI coding tool solves the **code generation and modification**
problem.

Dev Console addresses the **operational workflow around that code**.

### Controlled Production

The AI does not decide that a change is ready for Production.

A human does.

### Real Preview

Changes can be tested as a running website before Production is touched.

### Git history

Source changes are recorded in Git and can be synchronized with GitHub.

### Task history

The requested work and its execution state are associated with the
project.

### Multiple projects and servers

The same workflow can be used for multiple websites rather than
rebuilding an ad-hoc process for every project.

### Existing-site adoption

The system can be introduced around websites that already exist.

### Explicit operational state

Dev Console shows whether source, GitHub, Preview, and Production are
synchronized rather than leaving the user to infer this from shell
commands.

### Separation of responsibilities

AI coding, source control, deployment, server administration, and
Production approval are related but distinct operations.

Dev Console keeps those boundaries visible.

------------------------------------------------------------------------

## 7. What can the AI do?

The current coding engine is Codex CLI.

Codex is used to work on the selected project's development repository.

It can, within the task and sandbox constraints:

-   inspect project source;
-   create and modify project files;
-   run appropriate development checks;
-   implement the requested task.

Dev Console then handles the surrounding lifecycle.

### What the AI does not decide

Codex does **not** have the product authority to:

-   approve its own work for Production;
-   bypass Preview;
-   approve Production deletion candidates;
-   silently deploy a task to Production;
-   redefine the Managed Server configuration.

The central principle is:

> **AI may implement a change. A human controls release of that
> change.**

------------------------------------------------------------------------

## 8. Security model

Dev Console manages code and deployment, so security is a core
architectural concern rather than an optional feature.

The system follows several basic principles.

### Dev Console does not run as root

The persistent Dev Console service runs under a normal Linux service
user.

Root access is not required for normal application execution.

### Privileged operations are exceptional

Some setup and administration operations genuinely require elevated
privileges: installing host prerequisites, managing a system service,
configuring AppArmor, or installing/configuring web-server components.

These operations are explicit and separate from normal AI coding.

The long-term design principle is that privileged access should remain
limited to a defined set of administrative operations rather than
becoming arbitrary root shell access.

### Codex runs in its sandbox

Dev Console uses the normal Codex sandbox path rather than disabling the
sandbox for convenience.

On Ubuntu systems where AppArmor restricts unprivileged user namespaces,
Dev Console installs a narrowly scoped AppArmor profile for Codex's
bundled Bubblewrap executable. It does not globally disable the Ubuntu
restriction.

### Production is separated from development

Codex works against the development repository.

Preview and Production are deployment targets, not the AI's working
directory.

### Production deployment is explicit

Moving a version to Production requires a user action.

Production preflight additionally identifies filesystem changes,
including deletions, before promotion.

### Credentials are not source code

Console tokens, GitHub credentials, SSH private keys, and runtime state
are not intended to be committed to project repositories.

Detailed credential locations and permission requirements belong in the
technical security documentation.

------------------------------------------------------------------------

## 9. Trust boundaries and privileges

At a high level:

  -----------------------------------------------------------------------
  Component               Normal privilege        Primary responsibility
  ----------------------- ----------------------- -----------------------
  Dev Console service     Normal Linux user       UI, state, workflow
                                                  orchestration

  Codex                   Service user + Codex    Modify selected
                          sandbox                 development workspace

  Installer               Root, explicitly        Install and configure
                          invoked                 Console host
                                                  prerequisites

  Managed Server SSH user Configured remote user; Project/server
                          selected sudo           deployment operations
                          operations where        
                          required                

  Git/GitHub              Repository access       Source history and
                                                  remote synchronization

  Preview                 Web-server runtime      Run candidate version
                                                  for testing

  Production              Web-server runtime      Run explicitly promoted
                                                  version
  -----------------------------------------------------------------------

This is intentionally not a model in which an autonomous AI process
receives unrestricted control of the whole server.

------------------------------------------------------------------------

## 10. Why does it run as a system service?

Dev Console is designed as a persistent management application.

It may coordinate long-running tasks, maintain runtime state, serve its
web interface, and remain available after the administrator logs out.

On the currently supported Ubuntu deployment model, `systemd` provides a
standard mechanism for:

-   starting the service;
-   restarting it;
-   starting it after reboot;
-   running it under a specific non-root user;
-   inspecting service status and logs.

Using `systemd` does **not** mean that Dev Console itself runs as root.

------------------------------------------------------------------------

## 11. Why is sudo needed?

This distinction is important:

> **Dev Console needing access to specific administrative operations is
> not the same as Dev Console running as root.**

Examples of operations that may require elevation include:

-   initial installation of required operating-system packages;
-   creation/update of the Dev Console system service;
-   installation of Apache on a Managed Server;
-   creation of Apache virtual-host configuration;
-   reloading Apache after validated configuration changes;
-   installation of the narrow Codex AppArmor profile.

Normal project editing does not require root.

The security objective is to keep privileged operations explicit and
bounded.

------------------------------------------------------------------------

## 12. Why SSH?

Managed Servers may be separate machines.

SSH provides an established, auditable mechanism for Dev Console to
perform deployment and server-management operations without installing a
proprietary privileged agent on every target server.

The model is straightforward:

``` text
Dev Console Host
       │
       │ SSH
       ▼
Managed Server
       │
       ├── Preview
       └── Production
```

The Managed Server determines which account Dev Console uses and which
administrative capabilities that account has.

SSH also means Dev Console can manage a server without requiring the Dev
Console web application itself to run on that server.

------------------------------------------------------------------------

## 13. Why a separate Dev Console host?

A dedicated management host provides a clean separation between:

-   AI development tooling;
-   source repositories;
-   Dev Console runtime state;
-   customer/application web servers.

It is possible for the Dev Console host also to be registered as a
Managed Server, which is useful for small installations and testing.

However, these are conceptually different roles.

For larger or more sensitive installations, separating the management
plane from application servers creates a clearer trust boundary.

------------------------------------------------------------------------

## 14. Why Git and GitHub?

Git is the development history.

GitHub is the remote repository and synchronization point in the current
workflow.

This gives Dev Console a source history independent of Preview and
Production filesystem contents.

The deployment model is therefore not:

``` text
AI → edit live server
```

It is:

``` text
AI → Git → GitHub → Preview → Production
```

GitHub is not the deployment authority. It is part of the source-control
workflow.

------------------------------------------------------------------------

## 15. Why Preview and Production?

Because generated code should not become live code merely because
generation succeeded.

Preview provides a real environment in which the user can answer the
most important question:

> Does the website actually work and look the way I intended?

Only after that review is the version promoted to Production.

This is one of the central controls in Dev Console.

------------------------------------------------------------------------

## 16. Why not Docker?

Docker is a possible packaging model, but it does not automatically
solve the security or administration problems Dev Console has to
address.

Dev Console needs to interact with:

-   local Git repositories;
-   Codex;
-   SSH credentials;
-   host diagnostics;
-   operating-system packages in some administrative workflows;
-   Managed Servers;
-   web-server configuration.

A container requiring broad host mounts, privileged execution, or access
to the Docker socket can create a privilege model that is no
simpler---and potentially more dangerous---than a carefully constrained
native service.

The current Ubuntu implementation therefore uses a normal non-root
service managed by `systemd`.

This is an implementation decision, not a claim that container
deployment is inherently wrong. A future containerized distribution can
be considered if it preserves the same trust boundaries without
requiring excessive host privileges.

------------------------------------------------------------------------

## 17. How are dependencies and updates handled?

Dev Console should not silently change infrastructure dependencies
behind the administrator's back.

The intended principle is:

> **Detect automatically; change explicitly.**

Dev Console can diagnose installed tools and versions and can provide
explicit installation/update actions where appropriate.

Infrastructure-changing actions should be visible administrative
operations.

Fast-moving components such as AI coding engines deserve particular
care. Future versions may provide explicit update policies such as
stable/current/pinned versions rather than assuming that the newest
available release should always be installed automatically.

The Console itself should likewise have a deliberate update procedure
rather than self-modifying unpredictably.

------------------------------------------------------------------------

## 18. What happens if AI produces bad code?

Nothing should reach Production merely because Codex completed
successfully.

A bad result can be caught at several points:

1.  development validation;
2.  Git review/history;
3.  Preview deployment;
4.  browser testing;
5.  Production preflight;
6.  explicit human promotion.

AI completion means:

> "The implementation attempt finished."

It does not mean:

> "This change is approved for Production."

------------------------------------------------------------------------

## 19. What IOVON Dev Console is not

### It is not an autonomous production administrator

AI does not independently decide what should be released.

### It is not cPanel

Dev Console is not intended to become a general hosting control panel
for mail, DNS, databases, users, billing, and every server-management
function.

### It is not an IDE replacement

Developers may continue to use their normal editors and development
tools.

Dev Console manages the AI task and deployment workflow around the
project.

### It is not a GitHub replacement

GitHub remains the remote source repository in the current architecture.

### It is not a general infrastructure orchestrator

Dev Console is intentionally focused on website/application development
and deployment rather than attempting to replace Ansible, Kubernetes,
Terraform, or general-purpose operations platforms.

### It is not a promise that AI-generated code is correct

The product provides control points around AI-generated changes. It does
not remove the need to review and test them.

------------------------------------------------------------------------

## 20. Getting started --- conceptually

A new installation follows this path:

``` text
Install Dev Console on Ubuntu
        ↓
Open the Console
        ↓
Check host prerequisites
        ↓
Install/authenticate Codex
        ↓
Configure GitHub
        ↓
Add a Managed Server
        ↓
Create a new project
        or
Adopt an existing website
        ↓
Create the first task
        ↓
Run Codex
        ↓
Deploy Preview
        ↓
Review
        ↓
Deploy Production
```

The detailed commands and operational requirements belong in the Getting
Started technical guide.

------------------------------------------------------------------------

## 21. Current scope

The current version is deliberately narrow.

It focuses on:

-   Ubuntu as the Dev Console host;
-   Codex CLI as the AI coding engine;
-   Git and GitHub for source management;
-   SSH-managed web servers;
-   Apache-based project provisioning;
-   Preview and Production environments;
-   new projects and adoption of existing websites.

Possible future directions include:

-   additional AI coding engines;
-   multi-user access;
-   stronger role/permission models;
-   configurable dependency update policies;
-   additional web-server/deployment models;
-   backup and restore tooling;
-   alternative packaging, including containerized deployment where
    appropriate.

These are directions, not requirements for the current product to be
useful.

------------------------------------------------------------------------

## 22. The core idea

The shortest description of IOVON Dev Console is:

> **AI writes the change. Git records it. Preview proves it. You decide
> whether it goes live.**

That is the boundary the product is designed to preserve.
