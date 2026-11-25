This file serves as a placeholder for a detailed User Flow diagram.

**Derives from**: `docs/information_architecture.md`

## User Flow: Successful Login

```mermaid
sequenceDiagram
    actor User
    participant Browser
    participant Server

    User->>Browser: Enters username and password
    Browser->>Server: POST /login with credentials
    Server->>Server: Validate credentials against `users` table
    Server-->>Browser: Set `cmt_session` cookie (HttpOnly)
    Browser-->>User: Redirect to GET / (Dashboard)
```

## User Flow: View Shipments (Staff)

```mermaid
sequenceDiagram
    actor Staff
    participant Browser
    participant Server

    Staff->>Browser: Clicks "Shipments" link
    Browser->>Server: GET /shipments
    Server->>Server: require_login() -> OK
    Server->>Server: queries.get_shipments() (no filter)
    Server-->>Browser: Render `shipments.html` with all data
    Browser-->>Staff: Display table of all shipments
```

## User Flow: View Shipments (Customer)

```mermaid
sequenceDiagram
    actor Customer
    participant Browser
    participant Server

    Customer->>Browser: Clicks "Shipments" link
    Browser->>Server: GET /shipments
    Server->>Server: require_login() -> OK
    Server->>Server: Get `customer_company` from user session
    Server->>Server: queries.get_shipments(company_filter="TRISCO")
    Server-->>Browser: Render `shipments.html` with filtered data
    Browser-->>Customer: Display table of Trisco's shipments only
```
