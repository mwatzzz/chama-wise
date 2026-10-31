# ChamaWise TODO

## Completed

- Member registration and login
- Admin authentication with hashed passwords
- Password reset flow
- Member profile dashboard
- Member profile-picture upload
- Admin dashboard layout
- Admin member list and search
- Admin member details, editing, and activation controls
- Contributions database, admin recording, and member history
- Member contribution payment methods, notes, and totals
- Automatic contribution penalty calculation and penalty views
- Member loan applications and loan status/details
- Admin loan review, approval, and rejection
- Member loan repayment history with ownership protection
- Admin repayment recording and centralized balance updates
- Member read-only meetings and minutes
- Admin meeting creation, editing, and minutes management
- Meeting scheduling and minutes
- Admin dashboard live treasury metrics and meeting summaries
- Admin real-data reports and CSV export
- Secure logout
- Member sidebar links to meetings and AI advisor
- Admin sidebar links connected to Loans, Reports, and Financial Settings; removed unavailable Documents navigation
- Member dashboard and AI advisor navigation links to Investments
- Configurable loan eligibility rules with recorded reasons and the existing two-admin approval workflow preserved
- Idempotent loan applications and repayments, reducing-balance interest, repayment corrections, and audit history
- Configurable monthly contribution penalties with deadline/grace handling and duplicate prevention
- Simulated member investment goals and admin-reviewed group proposals
- Investment goal progress, target dates, editing, completion, and inactive status
- Admin-managed opportunity details with risk, liquidity, source, and verification status
- Illustrative investment growth calculator, zero-return comparison, and member education
- Member-scoped investment context in the existing Ollama financial advisor
- Admin financial settings with audited changes and KES-based displays
- Member loan details show the interest method and estimated monthly installment

## Next

- Add TCPDF dependency and PDF report export
- Add editable member profile fields
- Add notification preference persistence
- Add admin account management and password change
- Add automated tests for authentication and authorization
- Add automated integration tests for financial rules and migration compatibility
- Add automated integration tests for investment ownership, goal lifecycle, and opportunity management

## Phase 2 Database Tables Added

- `payment_methods`
- `penalties`
- `loans`
- `loan_repayments`
- `meetings`
- `ai_advice_sessions`
- `ai_chat_messages`

## Phase 5 Complete

- Admin contributions support payment method selection
- Admin contributions support notes
- Existing contributions can be edited

## Phase 6 Complete

- Centralized late-contribution penalty service
- Automatic penalty sync after contribution create/edit
- Member penalty history and outstanding total
- Admin penalty status management

## Phase 7 Complete

- Member loan application page
- Member loan list and status page
- Member loan detail page with ownership protection

## Phase 8 Complete

- Admin loan application list
- Admin applicant details
- Approve/reject workflow for pending loans
- Approved loan balance initialization

## Phase 9 Complete

- Member repayment history
- Loan, amount, date, payment method, reference, and balance display
- Member ownership filtering on repayment queries

## Phase 10 Complete

- Admin repayment recording page
- Transaction-safe loan balance updates
- Overpayment prevention
- Automatic completed status when balance reaches zero

## Phase 11 Complete

- Member meetings page
- Meeting title, date, status, and minutes display
- Members cannot create or edit meeting records

## Phase 12 Complete

- Admin meeting creation
- Admin meeting editing
- Admin meeting minutes management
- Admin and member meeting navigation connected

## Phase 13 Complete

- Live total contributions
- Live loan and outstanding-balance metrics
- Live repayment and outstanding-penalty totals
- Live upcoming meeting count and list
- Six-month contribution trend from database records

## Phase 14 Partial

- Contribution, loan, repayment, penalty, and summary reports
- Print-friendly HTML report output
- CSV report downloads
- PDF export remains pending because Composer/TCPDF is not installed

## Phase 15 Complete

- Provider-independent `AIProvider` contract
- Server-side `AIService` façade
- OpenAI/ChatGPT provider using `OPENAI_API_KEY`
- Authenticated member financial context builder
- Member OpenAI financial advisor chat page
- Model configurable through `OPENAI_MODEL`
- API key is never exposed to frontend code

## Phase 16 Complete

- Member account context
- Contribution history and totals
- Loan history and outstanding balance
- Repayment history and totals
- Penalty history and outstanding total
- All financial queries scoped to the authenticated member ID

## Phase 17 Complete

- Member AI Financial Advisor page
- OpenAI request routed through PHP
- Conversation messages stored in AI session/message tables
- CSRF protection and service error state
- No OpenAI credentials or endpoint exposed to frontend code
