# Feature Specification: CMT Dashboard Audit & Hardening

**Feature Branch**: `feat/system-hardening`

**Created**: 2026-08-10

**Status**: Draft

**Input**: User description: "use app dev blueprint for D:/PROJECTS/cmt-dash/"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Secure the Application (Priority: P1)

As a system owner, I want the application to be secure from common web vulnerabilities, so that I can protect user data and ensure system integrity.

**Why this priority**: Security is the most critical non-functional requirement for any application handling business data. The current implementation has several potential vulnerabilities (in-memory auth, hardcoded secrets) that must be addressed first.

**Independent Test**: A full `strix` pentest (`strix -m deep -t .`) completes with zero Critical or High severity findings.

**Acceptance Scenarios**:

1. **Given** the application is running, **When** a `strix` deep scan is performed on the codebase, **Then** the final report shows no Critical or High severity vulnerabilities.
2. **Given** the `auth.py` file, **When** it is inspected, **Then** it contains no hardcoded passwords or salts.
3. **Given** the `requirements.txt` file, **When** `pip-audit` is run against it, **Then** no known critical vulnerabilities are found in the dependencies.

---

### User Story 2 - Improve Developer Onboarding (Priority: P1)

As a new developer joining the project, I want a clear, one-step process to set up and run the application locally, so that I can start contributing productively within minutes.

**Why this priority**: Reducing friction for new developers is crucial for long-term maintainability. A complex setup process is a major barrier.

**Independent Test**: A new developer, given only the Git repository URL, can successfully run the application and its background sync service using a maximum of two shell commands.

**Acceptance Scenarios**:

1. **Given** a fresh clone of the repository, **When** the developer runs `docker-compose up`, **Then** the web application and a one-off sync job start successfully.
2. **Given** the project root, **When** the developer reads the `README.md`, **Then** it contains clear, concise instructions for local setup, architecture overview, and key operational procedures.

---

### User Story 3 - Enhance Observability (Priority: P2)

As a system administrator, I want structured logs for all important events, so that I can effectively monitor application health and troubleshoot issues.

**Why this priority**: Without good logging, diagnosing problems in production is nearly impossible. This is a foundational element of production readiness.

**Independent Test**: Tailing the Docker container logs for the `dashboard` service shows structured (JSON) logs for every incoming HTTP request, authentication attempt, and background sync event.

**Acceptance Scenarios**:

1. **Given** a user logs in, **When** the container logs are observed, **Then** a structured log entry appears containing the event type (`user_login`), username, and outcome (success/fail).
2. **Given** the `/sync` endpoint is triggered, **When** the logs are observed, **Then** structured entries for the start and end of the sync process are visible, including duration and row counts.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The in-memory user and session store in `auth.py` MUST be replaced with a persistent data store (e.g., tables in the existing SQLite database).
- **FR-002**: All hardcoded credentials and salts in `auth.py` and elsewhere MUST be removed and loaded from environment variables or a dedicated secrets management system.
- **FR-003**: All application dependencies in `requirements.txt` MUST be scanned for known vulnerabilities, and any high-severity issues must be patched or mitigated.
- **FR-004**: Role-based access control checks (`require_role`) MUST be applied to all non-public API and web endpoints, ensuring the principle of least privilege.
- **FR-005**: The `tools/sync_db.py` script MUST be made idempotent and more resilient to failure (e.g., using atomic file renames, better error handling).
- **FR-006**: The application MUST produce structured (JSON) logs for access, authentication, and errors to facilitate automated monitoring.

### Key Entities
*(Existing entities, to be modeled correctly)*
- **User**: Represents an authenticated principal with an assigned role.
- **Session**: A token representing a user's authenticated state.
- **Shipment**: A unified record of an import (`NA.DBF`) or export (`CARGO.DBF`) activity.
- **Invoice**: A financial document related to shipments.
- **Vessel**: A ship involved in transport.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: `strix` scan report shows 0 Critical and 0 High findings.
- **SC-002**: Time-to-first-request for a new developer clone is under 15 minutes.
- **SC-003**: 100% of web and API endpoints have explicit, audited RBAC enforcement.
- **SC-004**: The `sync_db.py` script can be run N times in a row and the resulting `cmt.db` file is always valid and consistent.
