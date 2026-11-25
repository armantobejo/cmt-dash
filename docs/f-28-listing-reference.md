# F-28 Listing Reference

**Route paths:** `/agents`, `/shippers`, `/consignees`, `/accounts`, `/rate-by-destination`

**Page purpose:** Four listing-reference surfaces (Agent, Shipper, Consignee/Notify, Account) showing master data rows with search, sort and pagination; plus a Rate by Destination page showing per-agent × per-destination container rates.

**Visible columns:**

| Page | Columns |
|------|---------|
| **Agent** (`/agents`) | Agent Code, Sub Code, Name, Invoice Agent, Rate, Address, Phone, Fax, PIC, Account Code, Credit Account |
| **Shipper** (`/shippers`) | Shipper Code, Name, BU, Customer Type, Payment Term, Address, Phone, Fax, NPWP, NPWP Address, NPWP Date, SIUP No, SIUP Date, Account Code |
| **Consignee / Notify** (`/consignees`) | Consignee Code, Name, Address, Phone, Fax |
| **Account** (`/accounts`) | Account Code, Account Name, Type, Group, Normal Balance, Entry Allowed, Active From, Inactive From |
| **Rate by Destination** (`/rate-by-destination`) | Agent Code/City, Destination/City, Quota, Date, LCL, MIN, D20, D40, D40HQ, D45 |

**Available filters (per page):**

- **All pages:** search box (uppercase LIKE on code/name fields), company filter (customer company from auth)
- **Agents:** search on CODE / NAME / AGENT
- **Shippers:** search on KD_SHIPPER / NAME
- **Consignees:** search on KD_CONSIG / NAME
- **Accounts:** search on KD_ACCOUNT / NM_ACCOUNT; optional `type_filter` query param
- **Rate by Destination:** search on agent code / desc_city / kd_city / quota / agent name

**Sort options (per page):**

- **Agents:** code, name, agent, rate
- **Shippers:** code, name, BU, type
- **Consignees:** code, name
- **Accounts:** code, name, type, group
- **Rate by Destination:** code (agent), city, quota, date

**Data source (DB view / API endpoint):**

- **Agents:** `AGENT` table — `SELECT CODE, NAME, AGENT, AGENTINV, RATE, KD_ACCOUNT, ACCT_CR, TELP, FAX, PIC FROM AGENT`
- **Shippers:** `SHIPPER` table — `SELECT KD_SHIPPER, NAME, KD_BU, KD_JNSCUST, TERM, TELP, FAX, NPWP, NPWP_ADDRESS, NPWP_DATE, NO_SIUP, TGL_SIUP, KD_ACCOUNT FROM SHIPPER`
- **Consignees:** `CONSIGNEE` table — `SELECT KD_CONSIG, NAME, ADDRESS, TELP, FAX FROM CONSIGNEE`
- **Accounts:** `ACCOUNT` table — `SELECT KD_ACCOUNT, NM_ACCOUNT, TYPE, GROUP, SLD_NORMAL, ENTRY, ACTIVE, NOACTIVE FROM ACCOUNT`
- **Rate by Destination:** `QUOTATION_R` (one row per agent × city, latest quotation per agent × destination via ROW_NUMBER), joined to `QUOTATION` (header) and `AGENT` (label resolution). Source: `QUOTATION_R.CODE_AGENT`, `QUOTATION_R.KD_CITY`, `QUOTATION_R.DESC_CITY`, `QUOTATION_R.LCL`, `QUOTATION_R.MIN`, `QUOTATION_R.D20`, `QUOTATION_R.D40`, `QUOTATION_R.D40HQ`, `QUOTATION_R.D45`.

**Acceptance checklist (per the task):**

- ✅ Route and page purpose are clear for all 5 F-28 surfaces
- ✅ Filters listed per page (search + company filter / type_filter)
- ✅ Sort options listed per page
- ✅ Data origin (DB table / view) noted for each page