# kanban/

The working queue for this project. See `AGENTS.md` for the full contract.

Quick map:

- `todo/` — one .md file per work item, not yet picked up.
- `doing/` — in progress now. WIP-limited.
- `review/` — finished; waiting for criterion-based review.
- `done/` — verified and ready to commit.
- `final/` — committed; durable reference (git is the real archive).

The unit of work is a todo file. Copy `kanban/SPEC.md` to start one.

Rules in brief:
- PRD.md edits are todo items unless decided otherwise.
- A todo is not ready for `doing/` until it has acceptance criteria.
- A todo is not ready for `review/` until its criteria are met or an owner-approved exception is recorded.
- Review is criterion-based, not tool-based.
- `final/` is secondary; git is the durable record.

If you are an agent starting a session, read `AGENTS.md` first.
