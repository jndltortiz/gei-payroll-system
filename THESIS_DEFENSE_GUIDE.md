# GEI HR & Payroll System — Complete Thesis Defense Guide

---

## 1. Overall System Purpose

The **GEI HR & Payroll System** is a web-based Human Resource and Payroll Management System developed for **Great Eastern Institute (GEI), La Paz, Tarlac**. It automates the full employee compensation cycle — from daily attendance tracking to government contributions, loan deductions, leave management, and end-of-year accrued pay settlement.

**Core problems it solves:**
- Eliminates manual payroll computation prone to human error
- Enforces government-mandated contribution rates (SSS 2025, PhilHealth 2025, Pag-IBIG 2026)
- Provides a structured two-level approval workflow (Admin → Principal)
- Centralizes all HR records: attendance, leaves, loans, service credits
- Generates accurate payslips compliant with BIR TRAIN Law

**Technology Stack:**
- Backend: PHP 8 (PDO/MySQL)
- Database: MySQL via XAMPP
- Frontend: HTML/CSS/JavaScript (no framework)
- Charts: Chart.js
- Icons: Font Awesome

---

## 2. Role Access

There are **4 roles** in the system:

| Role | Who | Access Level |
|---|---|---|
| **Admin** | HR Officer / Payroll Officer / Bookkeeper | Full system access — generates payroll, manages employees, attendance, leaves, loans |
| **Principal** | School Principal | Approval authority — approves payroll, leaves, loans, service credits |
| **Special Assistant** | Assistant to Principal | Same approval rights as Principal |
| **Employee** | All staff members | Self-service only — views own payslip, attendance, leave, loans |

**Important:** Admin and Principal accounts can be linked to an employee record (`employee_id`). When linked, they gain a **"My Account"** section in their sidebar giving them access to their own self-service pages (payslips, leave, attendance, loans) on top of their admin/approval functions.

---

## 3. Sidebar Modules Per Role

### Admin Sidebar

**OVERVIEW**
- **Dashboard** — Live operational snapshot: employee count, today's attendance, pending leaves, latest released payroll

**WORKFORCE**
- **Attendance** — Daily and cutoff-period attendance tracking for all employees
- **Employees** — Master list of all employee records, add/edit/deactivate

**PAYROLL**
- **Payroll** — Generate, review, adjust, submit, and release payroll periods
- **Payroll Archive** — View all released/historical payroll periods
- **Loans** — Full loan lifecycle management (create, submit, track, adjust)
- **Service Credits** — Record and submit extra-service hours for EOSY pay

**LEAVE**
- **Leave Records** — Review, forward, and record-to-attendance all leave requests

**ANALYTICS**
- **Analytics Dashboard** — Workforce, payroll trend, attendance rate, leave and loan summaries

**SYSTEM**
- **Notifications** — System-wide notification center
- **Audit Logs** — Full trail of every system action
- **Settings** — Payroll Settings (allowances, deductions, loan types, government tables, payroll config)

**MY ACCOUNT** *(only if admin's account is linked to an employee record)*
- My Attendance, My Leave, My Payslips, My Loans, My Service Credits, My Profile, My Calendar

---

### Principal Sidebar

**OVERVIEW**
- **Dashboard** — Summary of items pending approval + payroll status

**WORKFORCE**
- **Employees** — View-only employee list
- **Attendance** — View attendance records

**PAYROLL**
- **Payroll Approvals** — Review and approve/return submitted payroll periods
- **Payroll Archive** — View released payroll history
- **Loan Approval** — Approve or deny pending loan applications
- **Service Credit Approval** — Approve or reject service credit records

**LEAVE**
- **Leave Requests** — Approve or reject leave requests forwarded by admin

**ANALYTICS**
- **Analytics** — Payroll trend, attendance rate, leave breakdown

**SYSTEM**
- **Notifications**

**MY ACCOUNT** *(if linked to employee record)* — same as Admin's My Account section

---

### Employee Sidebar

- **Dashboard** — Personal overview: upcoming schedule, leave balance, loan balance
- **My Attendance** — Own daily attendance records
- **My Leave** — Submit and track personal leave requests
- **My Payslips** — View and download payslips per payroll period
- **My Loans** — View active loans, balance, and payment history
- **My Service Credits** — View approved and released service credits
- **My Profile** — Edit personal information
- **My Calendar** — School calendar with holidays and events

---

## 4. Module-by-Module Detail

### Dashboard (Admin)

**Purpose:** Real-time snapshot of daily operations.

**Cards displayed:**
- Total active employees
- Today's attendance summary (Present / Late / Half-Day / Absent)
- Attendance rate for today
- Pending leave requests count
- New employees this month
- Latest 5 released payroll records

**Key logic:** Attendance rate = Attended (Present+Late+Half-Day) ÷ (Working Days × Active Employees) × 100. No attendance is inferred — only explicit records are counted.

---

### Employees (Admin)

**Purpose:** Central employee directory and CRUD.

**Main table columns:** Employee No, Name, Department, Position, Shift, Status

**Filters:** Search by name or employee number, filter by department

**Pagination:** 12 records per page

**Actions:**
- **+ Add Employee** → modal form (name, employee no., dept, position, shift, hire date, employment type, salary)
- **Edit** → same form pre-filled
- **Deactivate** → soft-delete (sets `employee_status = 'INACTIVE'`); employee is hidden but data preserved

**What happens after save:** Employee appears in payroll generation scope, attendance list, leave module, and all self-service modules.

---

### Attendance (Admin)

**Purpose:** Track daily presence of all employees.

**Two tabs:**

**TODAY tab:**
- Cards: Present / Late / Half-Day / Absent counts
- Table: Name, Dept, Position, Time In, Time Out, Status
- Filters: date picker, search, status filter
- Leave sync indicator — shows if employee is on approved leave on selected date

**CUTOFF tab:**
- Shows 6-month history grouped by cutoff period (1–15, 16–31)
- Per period: working days, present days, absence rate
- Department filter

**Attendance statuses:** PRESENT, LATE, HALF_DAY, ABSENT, LEAVE

**Actions:**
- **Add Record** — manual entry for a specific employee and date
- **Edit Record** — correct existing record
- **Upload DTR** — bulk import from file (biometric export)
- **Download DTR** — export attendance report

**Important GEI policy:** In REGULAR payroll, attendance records do NOT cause deductions. Half-day and absence deductions only happen at end-of-school-year (ACCRUED_PAY) payroll.

---

### Leave Records (Admin)

**Purpose:** Two-level leave approval workflow.

**Six tabs with badge counts:**

| Tab | Description |
|---|---|
| Pending Review | New requests submitted, admin hasn't acted yet |
| Awaiting Principal | Admin forwarded to Principal for decision |
| To Be Recorded | Principal decided; admin must record in Attendance |
| Approved | Fully approved and attendance recorded |
| Rejected | Fully rejected |
| History | All old records |

**Filters:** Department, leave type, month, school year, search by name

**Stats shown:** Pending count, awaiting count, approved this month, employees on leave this month/next month

**Workflow:**
1. Request appears in Pending Review
2. Admin reviews → clicks Forward to Principal → moves to Awaiting
3. Principal approves/rejects → moves to To Be Recorded
4. Admin clicks Record in Attendance → syncs leave dates to attendance records as "LEAVE" status → moves to Approved

**Per-date approval:** Both admin and principal can approve/reject individual dates within a multi-day leave request. Parent status recalculates to APPROVED, REJECTED, or PARTIALLY_APPROVED based on the dates.

**Leave Credits:** Each employee has an annual allocation per leave type per school year stored in `employee_leave_credits`. When leave is approved, `used_days` increments.

---

### Payroll (Admin)

**Purpose:** Generate, review, adjust, and process payroll for all employees.

**Period selection dropdown:** Shows all periods grouped by status (OPEN, PROCESSING, APPROVED, RELEASED).

**Main table columns:** Employee No, Name, Basic Pay, Allowances (Rice Subsidy, Laundry, Additional Assignment, Service Credits), Deductions (SSS, PhilHealth, Pag-IBIG, PERAA, Withholding Tax, Loans), Gross Pay, Total Deductions, Net Pay, Status

**Generate Payroll Modal:**
- Select payroll period (OPEN periods only)
- Select scope: All / By Department / By Position / Specific employees
- Check Re-generate existing records to delete and rebuild

**After generation:** Records appear in table with status DRAFT. Admin can review, add adjustments, delete records.

**Manual Adjustments:**
- Click on a record → Add allowance or deduction manually
- Edit or delete existing line items
- All changes auto-recalculate gross and net

**Submit for Review:** Moves period from OPEN → PROCESSING. Sends to Principal's approval queue.

**Release (after Principal approves):** Moves APPROVED → RELEASED. Finalizes loan deductions, releases service credits.

---

### Payroll Archive (Admin + Principal)

**Purpose:** Searchable history of all released payroll periods.

**Columns:** Period Name, Dates, Pay Date, Employee Count, Gross, Deductions, Net, Released By, Released At

**Filters:** Year selector, search by period name, sort by date/net/employees/name

**Totals row** at bottom: sum of gross/net for filtered results

---

### Payroll Settings (Admin)

**Purpose:** Configure everything that controls how payroll is computed.

**5 tabs:**

1. **Allowances** — Add/edit allowance types, set default amounts, assign scope (ALL / DEPT / POSITION / EMPLOYEE), flag service-credit target allowance
2. **Deductions** — Add/edit deduction types, set Fixed or Percentage, flag is_government / is_loan / is_absence_deduction
3. **Loan Types** — Manage loan categories, view active loan count and outstanding totals per type
4. **Government Tables** — View SSS, PhilHealth, Pag-IBIG contribution tables loaded from DB (current 2025/2026 rates)
5. **Settings** — Payroll frequency (semi-monthly/monthly), working days per week (5/6/7), leave allocation days, government calc mode (STANDARD table lookup vs MANUAL fixed rates)

---

### Loans (Admin)

**Purpose:** Full lifecycle management of employee loans.

**Three tabs:**

**Documents tab (pending loans):**
- Columns: Employee, Loan Type, Provider, Amount, Monthly Deduction, Status
- Actions: Review → modal with full details → Approve/Deny → Return for correction

**Active Loans tab:**
- Columns: Employee, Loan Type, Balance, Monthly Deduction, Start/End Date
- Stats: Total active count, total outstanding balance, total monthly deduction, loans ending in 3 months
- Actions: Pause deductions, Resume, Record manual payment, View payment schedule

**History tab:**
- Completed, cancelled, denied, and archived loans

**After loan is ACTIVE:** Payroll generation automatically includes the loan deduction. On each payroll release, the balance decreases. When balance reaches ₱0, the loan auto-completes.

---

### Service Credits (Admin)

**Purpose:** Track compensable extra work (e.g., Saturday duty, seminars) and include in EOSY pay.

**Seven tabs:** All / Draft / Pending / Approved / Applied / Rejected / Archived

**Stats:** Pending pay (₱), Approved pay (₱), Applied/Released pay (₱)

**Statuses flow:** DRAFT → PENDING → APPROVED/REJECTED → APPLIED (when payroll is generated) → RELEASED (when payroll is released)

**Per-date records:** Each service credit has individual work dates. Principal can approve or reject individual dates. Parent recalculates to APPROVED, PARTIALLY_APPROVED, or REJECTED.

**Payroll link:** APPROVED service credits are included in ACCRUED_PAY payroll as an allowance. Once that payroll period is released, the service credit is marked RELEASED — meaning the employee has been paid.

---

### Analytics (Admin)

**Purpose:** System-wide insights dashboard.

**Sections:**
- **Workforce** — Active employee count, full-time vs part-time split, top departments by headcount
- **Payroll Trend** (last 6 periods) — Gross, deductions, net per period; average net pay; period status counts
- **Attendance** (this month) — Rate %, weekly trend, department breakdown
- **Leave** (this month) — Active, pending, approved, rejected counts
- **Loans** — Active, completed, pending counts

---

### Notifications (All Roles)

**Purpose:** Unified in-app notification center.

**Filters:** All / Unread / Read

**Types with icons:** Calendar (amber), Payroll (indigo), Leave (teal), Loan (green), Service Credit (purple), System (blue)

**On click:** Marks as read and navigates to the relevant page.

---

### Principal — Payroll Approvals

**Purpose:** Review payroll submitted by admin and approve or return for revision.

**Pending cards show:**
- Period name, date range, employee count
- Total gross pay, total net pay
- Submission timestamp
- Last return remarks (if resubmitted)
- Alert badges if any employee has negative net pay or zero basic pay

**Actions:**
- **Approve** → period moves PROCESSING → APPROVED; admin can now Release
- **Return with Remarks** → period moves back to OPEN; admin sees the remarks and fixes issues, then resubmits

---

### Principal — Leave Approvals

**Purpose:** Approve or reject leave requests forwarded by admin.

**Three tabs:** Pending / Decided / History

**Per-request display:** Employee name, leave type, dates, total days, child dates with individual status

**Actions:** Approve All / Reject All / Per-date approve or reject

---

### Principal — Loan Approval

**Purpose:** Approve or deny loan applications submitted by admin.

**Three tabs:** Pending / Active (read-only overview) / History

**On approval:** Loan status moves PENDING → ACTIVE, payroll generation starts including the deduction.

---

### Principal — Service Credit Approval

**Purpose:** Approve or reject service credit records.

**Per-record display:** Employee, work dates, total days, equivalent pay, per-date status

**Actions:** Approve All / Reject All / Per-date approve or reject

---

### Principal Analytics

**Metrics:**
- Payroll trend (last 6 approved/released periods) — KPIs: avg net, total released, return count
- Active employee count
- Attendance this month — rate, weekly trend
- Leave analytics — monthly usage, leave type breakdown

---

## 5. Computation Logic

### Regular Payroll Formula

**Step 1 — Determine Payable Days**

| Employment Type | How days are counted |
|---|---|
| FULL_TIME | Calendar working days in the period (Mon–Fri, or Mon–Sat depending on working_days_per_week setting) |
| PART_TIME | Actual attendance records only (PRESENT=1, LATE=1, HALF_DAY=0.5) |

```
basic_pay = daily_rate × payable_days
```

**Step 2 — Add Allowances**
- System looks for active allowance assignments matching the employee (ALL scope, or by dept/position/employee)
- Each matching allowance is inserted as a line item
- Service-credit target allowance is skipped in REGULAR payroll

```
total_allowances = SUM of all allowance line items
gross_pay = basic_pay + total_allowances
```

**Step 3 — Government Contributions (semi-monthly = divide by 2)**

SSS (2025, SSS Circular 2024-006):
```
MSC = ROUND(monthly_salary / 500) × 500
      capped min ₱5,000 / max ₱35,000
monthly_EE = MSC × 5%
monthly_ER = MSC × 10%
period_SSS = monthly_EE / 2
```
For MSC above ₱20,000, MPF is included in the total EE/ER shares.

PhilHealth (2025, Circular 2025-0002):
```
monthly_EE = MIN(₱2,500, MAX(₱250, monthly_salary × 2.5%))
period_PhilHealth = monthly_EE / 2
Income ceiling: ₱100,000 (max EE = ₱2,500/month)
```

Pag-IBIG (2026):
```
If monthly_salary >= ₱10,000:  period_Pagibig = ₱100 (fixed = ₱200/month cap)
Else:                           period_Pagibig = MIN(₱100, monthly_salary × 2% / 2)
```

**Step 4 — Withholding Tax (BIR TRAIN Law)**
```
annual_gross_basic      = basic_pay × 24 periods
annual_gov_deductions   = (period_SSS + period_PhilHealth + period_Pagibig) × 24
annual_taxable_income   = annual_gross_basic - annual_gov_deductions

Annual tax brackets:
  ₱0 – ₱250,000:           0%
  ₱250,001 – ₱400,000:     15% of excess over ₱250,000
  ₱400,001 – ₱800,000:     ₱22,500 + 20% of excess over ₱400,000
  ₱800,001 – ₱2,000,000:   ₱102,500 + 25% of excess over ₱800,000
  ₱2,000,001 – ₱8,000,000: ₱402,500 + 30% of excess over ₱2,000,000
  Above ₱8,000,000:         ₱2,202,500 + 35% of excess over ₱8,000,000

period_tax = annual_tax / 24
```

**Step 5 — Loan Deductions**
```
For each ACTIVE loan assigned to this period:
  period_loan = monthly_deduction / 2
  (skip if skip_next_deduction = 1)
  (use next_deduction_override if set)
```

**Step 6 — Net Pay**
```
total_deductions = SSS + PhilHealth + Pag-IBIG + WithholdingTax + Loans + OtherDeductions
net_pay = basic_pay + total_allowances - total_deductions
```

---

### Accrued Pay (EOSY Settlement)

Special period type run once at end of every school year (April–May). Does NOT pay regular salary. Settles accumulated items:

**1. Service Credits Allowance**
```
Collect all APPROVED/PARTIALLY_APPROVED service credits with no payroll_id
allowance = SUM(equivalent_pay of those credits)
Credits are then marked APPLIED and linked to this payroll period
```

**2. Half-Day Settlement**
```
total_half_days   = COUNT(attendance WHERE status='HALF_DAY' in school year June 1 – May 31)
already_settled   = SUM(absence_days in previous ACCRUED_PAY half-day deductions)
new_half_days     = MAX(0, total_half_days - already_settled)
half_day_deduction = new_half_days × (daily_rate / 2)
```

**3. Excess Leave Settlement**
```
leave_threshold  = MAX(employee's total allocated leave days, global default in settings)
total_used       = SUM(used_days for non-statutory leave types)
excess           = MAX(0, total_used - leave_threshold)
already_settled  = SUM(absence_days in previous ACCRUED_PAY excess-leave deductions)
new_excess       = MAX(0, excess - already_settled)
excess_deduction = new_excess × daily_rate
```

**ACCRUED_PAY net:**
```
basic_pay  = 0
gross_pay  = service_credit_allowance
net_pay    = service_credit_allowance - half_day_deduction - excess_leave_deduction
```

---

### Employer Contributions

Stored in payroll_records for reference but NOT deducted from employee pay:
```
employer_SSS        = MSC × 10% / 2 per period
employer_PhilHealth = monthly_salary × 2.5% / 2 per period (capped ₱2,500/mo)
employer_Pagibig    = ₱100 per period (₱200/month cap)
```
These represent GEI's direct institutional cost per employee.

---

### PERAA Contribution

- Applied to FULL_TIME employees only
- Computed as a percentage of monthly salary, split per period:
```
period_PERAA = (monthly_salary × rate%) / 2
```
Gives equal amounts each period regardless of working days.

---

## 6. Best Thesis Demo Flow

### Recommended Order

**Login as Admin** (`admin` / `Admin@GEI2025`)

1. **Dashboard** — Show live overview: employee count, attendance today, pending leaves, latest payroll
2. **Employees** — Show employee list, open one profile, explain departments/positions/employment types
3. **Attendance** — Show today's tab, explain statuses; show cutoff tab for historical view
4. **Leave Records** — Show a pending leave request, explain two-level workflow (Admin → Principal), show tabs
5. **Loans** — Show an active loan, open payment schedule modal, explain auto-deduction during payroll
6. **Service Credits** — Show an approved credit, explain how it links to EOSY payroll
7. **Payroll Settings** — Show Government Tables tab (SSS/PhilHealth/Pag-IBIG), explain 2025/2026 rates
8. **Payroll** — Select the OPEN period, generate payroll, show the records table, explain each column
9. **Submit for Review** — Show period moving to PROCESSING status
10. **Analytics** — Show payroll trend chart, attendance rate, workforce breakdown

**Login as Principal** (`r.miguel` / `Admin@GEI2025`)

11. **Payroll Approvals** — Show pending payroll card with totals and alert indicators
12. **Approve payroll** — Period moves to APPROVED
13. **Leave Approvals** — Show a forwarded leave, approve per-date
14. **Loan Approval** — Show a pending loan, approve it
15. **Service Credit Approval** — Show a pending service credit, approve it
16. **Principal Analytics** — Show the overview dashboard

**Back to Admin** (`admin` / `Admin@GEI2025`)

17. **Release Payroll** — Release the approved period → RELEASED
18. **Payroll Archive** — Show the newly released period in the archive

**Login as Employee** (`a.flores` / `Employee@GEI2025`)

19. **My Payslips** — Open latest payslip, walk through each deduction and allowance
20. **My Attendance** — Show own records
21. **My Leave** — Show leave balance and submitted requests
22. **My Loans** — Show loan balance and payment history

---

### Demo Account Reference

| Username | Role | Password | Notes |
|---|---|---|---|
| admin | Admin | Admin@GEI2025 | Full system access |
| treasurer | Admin | Admin@GEI2025 | Alternative admin account |
| r.miguel | Principal | Admin@GEI2025 | Ruby Ann Miguel — Principal |
| bookkeeper | Accounting | Employee@GEI2025 | Accounting role |
| a.flores | Employee | Employee@GEI2025 | Ana Kristina Flores — has loan + leave credits |
| b.cruz | Employee | Employee@GEI2025 | Benjamin Cruz |
| (all others) | Employee | Employee@GEI2025 | |

**Ready-to-use OPEN period:** PR-2026-05-002 (May 16–31, 2026) — ready for generation

---

## 7. Possible Panel Questions and Answers

**Q: What government regulations does your system follow?**

A: The system follows three current mandatory contribution circulars. SSS: Circular No. 2024-006 effective January 2025 — employee 5%, employer 10% of Monthly Salary Credit, MSC range ₱5,000–₱35,000 with MPF component above ₱20,000. PhilHealth: Advisory No. 2025-0002 — 5% total (2.5% each), income ceiling ₱100,000, maximum ₱2,500 per side. Pag-IBIG: 2026 schedule — 2% rate, mandatory savings ceiling ₱10,000, maximum contribution ₱200 per side. For withholding tax, BIR TRAIN Law brackets effective 2023 are applied.

---

**Q: Why are absence and half-day deductions not applied in regular payroll?**

A: This is a GEI-specific institutional policy. GEI follows a school-year-based settlement approach. Absence and half-day deductions accumulate throughout the school year (June–May) and are settled all at once in the ACCRUED_PAY period at the end of the school year. This avoids cash flow disruption during the school year and aligns with the teachers' compensation structure.

---

**Q: How does the system prevent double deductions on loans?**

A: Each loan deduction in `payroll_deductions` is linked to the specific `loan_id`. On payroll release, the system reads all deductions with a loan_id for that period and subtracts them from `employee_loans.balance_amount`. If the balance reaches zero or below, the loan status is automatically set to COMPLETED. The system also has `skip_next_deduction` and `next_deduction_override` flags that allow admin to skip or adjust a single period's deduction without changing the loan's monthly terms.

---

**Q: What happens if a payroll period is returned by the Principal?**

A: The Principal enters remarks explaining the issue, then clicks Return. The period status reverts from PROCESSING back to OPEN. All payroll records revert to DRAFT. The admin sees the return remarks on the payroll page, makes corrections — such as adjusting a deduction or allowance line item — then resubmits. The process goes back to the Principal for review. The full history of all approvals, returns, and releases is recorded in the `payroll_workflow_log` table.

---

**Q: How does the service credit system work?**

A: Service credits represent compensable extra work (such as Saturday duties or training sessions) performed by employees. Admin records them with specific work dates and equivalent pay. The Principal reviews and approves per work date. Approved service credits stay pending until the end-of-school-year ACCRUED_PAY payroll is generated. At that point, the system collects all approved and unapplied service credits for each employee, totals the equivalent pay, and inserts it as an allowance. When the payroll period is released, the service credits are marked RELEASED — meaning the employee has been paid.

---

**Q: How is the withholding tax computed?**

A: The system uses the BIR TRAIN Law six-bracket schedule. It first annualizes the employee's taxable income by multiplying the period's basic pay by 24 (for semi-monthly) and subtracting annualized government contributions (SSS + PhilHealth + Pag-IBIG). This annualized taxable income is applied to the tax brackets to get an annual tax amount. That annual tax is then divided by 24 to get the per-period withholding tax deduction. This ensures the correct progressive tax rate is applied without under- or over-withholding.

---

**Q: What is the difference between REGULAR and ACCRUED_PAY payroll?**

A: REGULAR is the standard semi-monthly payroll. It pays basic salary plus regular allowances and deducts government contributions, loans, and withholding tax. ACCRUED_PAY is a special end-of-school-year settlement period. It has zero basic pay. Its purpose is to pay out approved service credits (as allowances), and settle accumulated half-day deductions and excess leave deductions for the whole school year. It runs once per school year and resolves everything that REGULAR payroll intentionally deferred.

---

**Q: What is the two-level approval workflow?**

A: The system enforces a four-stage payroll workflow. First, the Admin generates payroll — records are created in DRAFT status. Second, the Admin submits the period — it moves to PROCESSING and appears in the Principal's approval queue. Third, the Principal either approves (moving it to APPROVED) or returns it with remarks (moving it back to OPEN for correction). Fourth, once approved, only the Admin can release it — moving it to RELEASED, which finalizes loan deductions, releases service credits, and makes payslips visible to employees. This separation of duties ensures that no single user can both create and finalize payroll.

---

**Q: How does part-time payroll differ from full-time?**

A: Full-time employees are paid based on the number of calendar working days in the pay period, regardless of actual attendance. This is consistent with a regular monthly salary structure. Part-time employees — like the school physician or dentist — are paid only for days they actually reported to work, based on confirmed attendance records. The system identifies part-time employees by the `employment_type` field and counts PRESENT, LATE, and HALF_DAY (as 0.5) records to compute their payable days.

---

**Q: How does the system handle the employer share of contributions?**

A: The employer shares for SSS, PhilHealth, and Pag-IBIG are computed and stored in separate columns of the payroll record (`employer_sss_share`, `employer_philhealth_share`, `employer_pagibig_share`) for reporting and reference purposes. However, they are not deducted from the employee's pay — they represent GEI's direct institutional cost. Under the 2025/2026 rates, GEI pays SSS 10% of MSC, PhilHealth 2.5% up to ₱2,500, and Pag-IBIG ₱200 per employee per month.

---

**Q: Can the system be used for multiple school years?**

A: Yes. The system has a `school_years` table where each school year (e.g., 2025–2026, 2026–2027) is tracked. Leave credits are allocated per employee per school year. Attendance records reference the active school year for ACCRUED_PAY calculations. When a new school year starts, a new school year record is created, set as active, and new leave credits are allocated. Historical data from prior school years remains intact and accessible through the archive and analytics modules.

---

**Q: What audit trail does the system maintain?**

A: The system maintains two audit mechanisms. The `audit_logs` table records every significant action with the user ID, action type (CREATE/UPDATE/DELETE/GENERATE/APPROVE/RETURN/RELEASE), the affected table, the record ID, and a human-readable description with timestamp. The `payroll_workflow_log` specifically tracks every stage of the payroll approval process — who submitted, who approved, who returned, who released, with totals (gross, net, employee count) snapshotted at each event. This provides full accountability for all payroll actions.

---

**Q: What happens to loan deductions when payroll is released?**

A: On payroll release, the system loops through all payroll deductions that have a `loan_id` for that period. For each one, it subtracts the deducted amount from `employee_loans.balance_amount` and records the transaction in `loan_payment_log` with `payment_channel = 'PAYROLL'`. If the resulting balance is zero or below, the loan status is automatically updated to COMPLETED. This ensures the loan ledger is always consistent with what was actually paid through payroll.

---

**Q: How does the system handle per-date leave approval?**

A: Leave requests are stored in two tables. The parent `leave_requests` table holds the overall request, and `leave_request_dates` holds one row per individual leave date. Both the admin and principal can approve or reject individual dates. Each time a date is actioned, the system recalculates the parent status: if all dates are approved it becomes APPROVED, if all are rejected it becomes REJECTED, and if mixed it becomes PARTIALLY_APPROVED. This allows, for example, approving 3 out of 5 requested days.

---

**Q: What security measures are in place?**

A: Each page enforces role-based guards at the top — `requireAdminPage()`, `requirePrincipal()`, `requireEmployee()` — so a user who navigates directly to a URL they are not authorized for is redirected. All database queries use PDO prepared statements to prevent SQL injection. Passwords are hashed. First-time logins are forced to change their default password via `blockIfMustChangePassword()`. Every sensitive action is logged to `audit_logs` with the user ID and timestamp.
