# Project Context

**Project:** cmt-dash
**Goal:** Laravel rebuild to full parity (cutover when ready per PRD). SC-001 pentest clean + SC-002 parity 100% per stop condition.
**Status:** active

## Description

Refer to PRD.md for details

## Stop Condition

The project should stop when: Parity checklist 100% + SC-001 pentest clean + cutover complete (PRD §9)
If the stop condition appears to be met, the leader should raise a motion for the team to vote on whether to stop.

**Heartbeat Member:** bejo (woken every 15 min)

## Team Members

| Profile Name | Role | Responsibilities |
|---|---|---|
| bejo (heartbeat) | leader — Team Leader | Project management, discussion chair, heartbeat, task dispatch |
| afnan | architect — Architect | System design, API contracts, technology selection, trade-off analysis |
| bunda | reviewer — Reviewer | Code review, security review, spec conformance, edge cases |
| hasna | devops — DevOps | CI/CD, containerization, deployment, monitoring, infrastructure |
| inem | writer — Writer | Documentation, README, API docs, content production |
| yervant | developer — Developer | Implementation, bug fixes, refactoring, dependency management |
| yevna | tester — Tester | Test strategy, automated tests, bug verification, regression coverage |

Assign tasks by profile name (e.g. `assignee='yevna'`). The system routes to the matched profile — roles are not assignees.

**Last heartbeat:** 2026-11-25T06:34:03.259342+00:00

## Workflow

1. Check your assigned tasks with `agora_project_status` or `hermes kanban list`.
2. Use `kanban show <task_id>` to read task details.
3. **Developer:** after completing a task, if your team has a `reviewer`,
   use `kanban_request_review(task_id, summary="...")` to submit for code
   review (summary describes what was implemented + how it was verified).
   The reviewer is auto-spawned. If the reviewer requests changes, fix the
   findings and re-submit. If no `reviewer` on team, use `kanban complete`.
4. **Reviewer:** approve with `kanban complete`; reject with
   `kanban_request_changes(task_id, reason="concrete findings")` — the
   task routes back to the implementer automatically.
5. **All other roles:** use `kanban complete <task_id>` when done.
6. If blocked, use `kanban block <task_id>` with a clear explanation.
7. For design decisions that need team input, use `agora_raise_motion`.
8. **Never** use Python, terminal, or direct DB calls to manage tasks/motions.
   Always use agora tools (`agora_raise_motion`, `agora_create_task`, etc.).
