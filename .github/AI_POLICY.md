<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: MIT
-->

# AI Contribution Policy

This document provides guidance for AI tools and developers using AI assistance when contributing to Nextcloud. It applies to all repositories under the [Nextcloud GitHub organization](https://github.com/nextcloud/), including the server, clients, apps, and the community app ecosystem.

This policy complements the existing [Contribution Guidelines](CONTRIBUTING.md). The requirements around testing, the Developer Certificate of Origin, license headers, and security reporting described there continue to apply in full - this document addresses how they extend to AI-assisted contributions.

---

## Requirements

### Disclosure

Every pull request containing AI-assisted code, documentation, or tests must declare this in the PR description. PRs found to have undisclosed AI use might be closed.

For full traceability at the commit level, each commit containing AI-assisted content must include an `Assisted-by:` git trailer:

```
Assisted-by: AGENT_NAME:MODEL_VERSION
```

The agent name and model version identify the AI tool. Basic development tools such as git, compilers, editors, and static analyzers are not listed - these are standard parts of any development workflow regardless of AI involvement.

The PR description disclosure explains how AI was used; the commit trailer ensures that provenance is permanently recorded in version history and available to future contributors, auditors, and tooling.

Examples:

```
Assisted-by: Devstral:devstral-small-2-24b-instruct-2512
Assisted-by: ClaudeCode:claude-sonnet-5-5
Assisted-by: Qwen:qwen3-coder-next
Assisted-by: Codex:gpt-6.1-sol
```

### Author Accountability

The contributor is the legal and moral author of every line they submit, and is responsible for it however it was produced. This applies to code, comments, documentation, and tests alike. "The AI wrote it" is not an answer to a reviewer's question: answers may be drafted with AI, but they must actually address the question.

### Communication Written for Humans

Issues, PR descriptions, review comments, and replies may be drafted with AI assistance. Every such text is read by people - maintainers, reviewers, and future contributors - so it must be written for them:

- **Owned by the contributor.** Text posted on the contributor's behalf is theirs, no matter who drafted it.
- **Answers the reviewer's first questions.** A PR description states which problem it solves, what changed at a high level, and how to verify the change. A reviewer who has not read the code yet can tell all three from the description alone.
- **Plain, concise, and skimmable.** Do not restate the diff file by file, pad the text with filler or summaries of summaries, or paste raw tool output.
- **Disclosed.** Tick the AI checkbox of the repository's PR template, and end AI-drafted comments and issues with a footer such as `Assisted by AGENT_NAME:MODEL_VERSION`.
- **Template filled in.** When opening a PR or issue, keep the repository's template intact and complete every section.
- **Bug reports reproducible.** An AI-drafted bug report follows the repository's issue template and states whether the bug was validated and reproduced on a live Nextcloud instance.
- **Agent-only details collapsed.** Information meant only for other agents (prompts, plans, tool logs) goes into a collapsed `<details>` block, so human readers can skip it.

Translation assistance and grammar/spelling help do not need to be disclosed.

This applies throughout the review process: answers to reviewer questions and implementations of requested changes may be drafted with AI as well. What counts is the result. A pull request that is correct and well made is welcome, regardless of how much of it was produced with AI, as long as it meets the requirements of this policy. If a maintainer gets the impression that the contributor does not understand their own submission and is only relaying an AI whose output is of poor quality, they may first ask for a rewrite - by requesting changes or converting the pull request to a draft - and name what is missing, or close the pull request right away: such a contribution has no value to the project. If a rewrite was requested, the pull request may be closed when the contributor does not respond within a month, or when the rewrite still shows neither understanding nor quality. Extractive contributions can still be closed right away, see Maintainer Discretion.

### Security and Dependency Scrutiny

AI tools hallucinate package names, produce subtly broken access controls, and may reproduce vulnerable patterns from their training data. Contributors must manually verify all dependencies, access control logic, authentication patterns, and security implications in AI-generated code before submitting - the risk of undetected errors is higher than with hand-written code and warrants extra care.

For general security requirements applicable to all contributions, see the [Contribution Guidelines](CONTRIBUTING.md). Security vulnerabilities must be reported via [HackerOne](https://hackerone.com/nextcloud) following Nextcloud's [security policy](https://nextcloud.com/security/), not via public issues. AI-generated security reports must be independently verified before submission; unverified reports might be closed without response.

### No Autonomous Agent Submissions

AI agents must not act on their own: every issue, pull request, and comment is posted on behalf of a contributor who directed the agent and is accountable for it, and security reports are always submitted by a human. This includes agentic workflows where an AI browses the codebase, plans changes across multiple files, and generates commits - the human contributor remains responsible for everything that is submitted.

AI agents must not add `Signed-off-by` tags: only humans can legally certify the [Developer Certificate of Origin](https://github.com/nextcloud/server/blob/master/contribute/developer-certificate-of-origin).

### Licensing and Copyright Compliance

Contributors must ensure AI-generated code contains no material from sources incompatible with the license of the repository or app they are contributing to. Each Nextcloud repository and app carries its own license - contributors are responsible for knowing which applies. For guidance on license headers, see [HowToApplyALicense.md](https://github.com/nextcloud/server/blob/master/contribute/HowToApplyALicense.md).

The applicable test has three parts: the AI tool's terms must permit open-source use of its output; no third-party copyrighted material may be reproduced; and any included material must use a compatible open-source license. If generated code appears identical or suspiciously similar to code from an incompatible source, it must be removed or replaced with an original implementation. Ignorance of AI-generated provenance is not a defense.

### Code Quality and Cleanup

AI output must be cleaned before submission. Dead code, redundant logic, excessive comments, inconsistent style, unused variables, malformed or garbled characters, structural drift, and unrelated file changes must all be removed. Submitting large AI code blobs without meaningful oversight - sometimes called "vibe coding" or "prompt dumping" - is prohibited.

Code comments must document the code itself - behavior, signatures, and constraints the code cannot express - never the process that produced it. Progress notes, decision records, and change descriptions ("changed X to Y", "as requested", "this fixes ...") belong in the commit message or PR discussion; in code they go stale and become misleading. Comments must stay brief and match the comment density of the surrounding code, and self-explanatory code needs no comment at all.

Commit messages follow the repository's conventions, such as conventional commits and the trailers this policy requires. Beyond that, the message body explains why the change was made - the problem it solves and the decisions the diff does not show - not how it was done; the diff already shows that.

Duplicated logic is a further common sign of unreviewed AI output: existing helpers and utilities must be reused instead of re-implemented, and when a flawed pattern is corrected, every occurrence of it must be fixed - not only the one a reviewer happened to point out.

Signs of a disallowed submission include: large unreviewed AI blobs; obvious mechanical mistakes a human would fix in minutes; code that has clearly never been executed; and pull requests that shift debugging and cleanup work onto maintainers rather than the contributor. As required by the [Contribution Guidelines](CONTRIBUTING.md), all changed and added code must be unit tested - AI-generated code is not exempt from this requirement.

Every pull request that changes runtime behavior must be validated by testing it on a live Nextcloud instance before submission, in every context in which the change is reachable - not only the default authenticated view, but also public share links, embedded contexts such as the Smart Picker and reference widgets, and other entry points the app supports. AI-generated changes frequently work in the primary view while silently failing in secondary ones. This testing may be AI-assisted, but must not be limited to it: the contributor tests the change on the live instance themselves as well.

---

## Guidelines

### Focused and Scoped Pull Requests

A pull request should address exactly one thing. AI-generated code frequently drifts in scope due to imprecise prompting, touching unrelated files or introducing incidental refactors. If a PR description does not match its diff, that is a signal the contributor did not review their own changes. Large changes must be broken into multiple focused commits or separate PRs.

### Maintainer Discretion

Maintainers have unreviewable authority to close AI-assisted contributions for quality, complexity, scope, or community-fit reasons. A contribution that costs reviewers more time than it returns value to the project is extractive and will be closed, regardless of how many rounds of review it has already received. The golden rule applies: a contribution should be worth more to the project than the time it takes to review.

### Beginner Issues

AI tools must not be used to fully automate the resolution of issues labeled [`good first issue`](https://github.com/issues?q=org%3Anextcloud+label%3A%22good+first+issue%22) or similar beginner-friendly labels. These issues exist to create a learning path for new contributors who will become future maintainers. Take a beginner issue only if you genuinely intend to learn and continue contributing to Nextcloud.

---

## Scope and Updates

This policy applies to all contributions to repositories and apps under the Nextcloud GitHub organization, by all contributors. It will be reviewed and updated as AI tooling, open-source best practices, and applicable law evolve. Pull requests opened before a change to this policy are asked to adapt to it rather than closed. Suggested changes are welcome via pull requests.