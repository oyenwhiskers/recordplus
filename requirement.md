
PROJECT: RECORDPLUS
VERSION: V1 — INTELLIGENT FILING SYSTEM
DEVELOPMENT MASTER PLAN

==================================================
1. PROJECT OVERVIEW
==================================================

RecordPlus is an intelligent digital filing system designed
to eliminate repetitive manual document filing.

Our vision is simple:

"Just Upload. We Handle the Filing."

Users should not need to manually identify documents,
rename files, create complicated filing structures, or
decide where every document belongs.

RecordPlus should help users manage personal and business
documents with minimal effort.

Examples of supported documents:
- Shopping receipts
- Purchase invoices
- Sales invoices
- Insurance policies
- Bank statements
- Agreements and contracts
- Utility bills
- Warranty documents
- Personal documents
- General business documents

RecordPlus is not primarily an accounting system.

Our main focus is document filing, organisation,
management, and retrieval.

Financial information extracted from documents can
provide additional insights, but filing remains
the core product.

CORE PRODUCT VALUE:

1. Reduce time spent filing documents.
2. Reduce manual document categorisation.
3. Make documents easy to find.
4. Keep documents organised consistently.
5. Learn users' filing preferences over time.
6. Reduce dependency on manual administrative filing.

The experience must be simple enough for nontechnical
users, including small business owners and individuals.


==================================================
2. APPLICATION STRUCTURE
==================================================

RecordPlus will support two access methods:

1. RecordPlus Web
   - Accessible through a web browser.
   - Suitable for desktop and mobile browsers.

2. RecordPlus PWA
   - Installable on supported devices.
   - Provides an app-like experience.
   - Shares the same functionality as RecordPlus Web.

IMPORTANT:

RecordPlus Web and PWA must be the SAME application.

Do not develop two separate applications.

Both must share the same user accounts, documents,
profiles, filing logic, and information.

The backend uses Laravel with an API.

Google Gemini is our AI document understanding engine.

All uploaded documents and images are stored securely
on the server.

Users should be able to access their documents from
different devices through the same account.

The application is ONLINE-FIRST.

Full offline document management is not required
for V1.

The interface must work well on desktop and mobile.


==================================================
3. MAIN APPLICATION MODULES
==================================================

For V1, develop only THREE main modules.

1. Dashboard
2. Capture
3. My Files

Settings will be developed in a later phase.

Do not introduce additional main modules unnecessarily.

Essential profile and category management must still
be accessible within the existing modules.

Navigation:

DESKTOP:
- Dashboard
- Capture
- My Files

MOBILE / PWA:
- Home
- Capture
- Files

Keep the navigation clean and simple.

Capture should always be easy to access.


==================================================
4. PROFILE MANAGEMENT CONCEPT
==================================================

RecordPlus uses PROFILES to organise documents
belonging to different individuals or businesses.

Examples:

PROFILE: PERSONAL
- Expenses
- Insurance
- General Documents

PROFILE: YK NEXUS
- Expenses
- Income
- Debtors
- General Documents

Users may create multiple profiles.

Every profile has its own categories, documents,
filing preferences, and filing rules.

CRITICAL DESIGN RULE:

The user determines the active profile.

AI must NEVER attempt to guess, predict, or change
the profile that a document belongs to.

Example:

If the user is currently using YK NEXUS, all newly
uploaded documents belong to YK NEXUS.

If the user switches to Personal, subsequent uploads
belong to Personal.

The active profile must always be clearly visible.

The selected profile must be retained when an upload
begins, even if the user switches profiles before
AI processing finishes.

The system must also verify that the user has
permission to access the selected profile.

Profile changes after uploading must only occur
through an explicit authorised user action.

INITIAL ONBOARDING:

When someone registers:

1. Create or offer a default Personal profile.
2. Allow the user to create additional profiles.
3. Offer a few suggested categories.
4. Allow users to customise these categories.
5. Let users start uploading without complicated setup.

Profile creation should be accessible through the
profile selector on the Dashboard.

We do not need a separate Settings module yet.


==================================================
5. UNDERSTANDING DOCUMENT TYPES AND CATEGORIES
==================================================

Document type and filing category are DIFFERENT.

DOCUMENT TYPE:
Identifies what the uploaded document actually is.

Examples:
- Receipt
- Invoice
- Insurance Policy
- Bank Statement
- Contract
- Payslip
- General Document

CATEGORY:
Determines how the user wants the document organised.

Examples:
- Expenses
- Income
- Debtors
- Insurance
- Agreements
- General Documents

Example:

A user uploads a shopping receipt.

Document Type:
Receipt

Active Profile:
YK NEXUS

Filing Category:
Expenses

Another user might place the same type of receipt
under Personal > Shopping.

The document type remains Receipt.

The filing category depends on the user's established
organisation and preferences.

Do not assume a document's financial or accounting
meaning solely from its document type.

For example, an invoice does not automatically
mean money has been received or paid.


==================================================
6. RECORDPLUS INTELLIGENT FILING ENGINE
==================================================

The RecordPlus Engine is the core of this product.

It combines:

- Google Gemini document understanding
- Existing profile categories
- User-confirmed filing preferences
- Filing rules
- Review and correction workflows

Gemini handles document understanding.

RecordPlus handles filing decisions and organisation.

The AI must not directly control document ownership,
create unapproved categories, or override user rules.

EXPECTED FILING PROCESS:

STEP 1: USER CAPTURES OR UPLOADS A DOCUMENT

The user uploads a document while using an active
profile.

The system immediately associates the uploaded
document with that selected profile.

The original document must be stored safely.

STEP 2: DOCUMENT ANALYSIS

RecordPlus sends the document for Gemini analysis
when AI processing is available.

Gemini should attempt to identify:

- Document type
- Document title
- Document date
- Merchant or organisation
- Relevant reference numbers
- Relevant monetary amounts
- Currency, where applicable
- Other important document-specific information

Different document types may require different
information.

For example:

Receipt:
- Merchant
- Purchase date
- Total amount
- Currency

Insurance Policy:
- Insurance provider
- Policy number
- Coverage period
- Expiry date, where available

Invoice:
- Issuer
- Invoice number
- Invoice date
- Amount
- Due date, where available

Do not invent values that cannot be reliably read.

Missing information should remain unknown or blank.

STEP 3: CATEGORY MATCHING

RecordPlus checks the existing categories within
the active profile.

Example:

Active Profile: YK NEXUS

Existing Categories:
- Expenses
- Income
- Debtors
- General Documents

Uploaded Document:
Shopping Receipt

Suggested Category:
Expenses

The engine should prioritise matching existing
categories over proposing new ones.

STEP 4: CATEGORY SUGGESTION

If a suitable category does not exist, the engine
may recommend creating one.

Example:

Uploaded Document:
Insurance Policy

Existing Categories:
- Expenses
- Income
- Debtors
- General Documents

Suggested New Category:
Insurance

The user can:
- Approve the new category
- Select an existing category
- Choose another category

IMPORTANT:

AI must NOT automatically create new categories.

The user must approve category creation.

Avoid creating multiple categories with similar
meanings.

Examples of unwanted duplication:
- Petrol
- Fuel
- Vehicle Fuel
- Car Fuel

The engine should encourage consistent organisation.

STEP 5: FILING DECISION

The system determines whether the document can
be filed automatically.

For new users, assisted filing should be the default.

Documents can be automatically filed when an
appropriate trusted filing rule exists.

Documents requiring confirmation should go into
a Needs Review state.

STEP 6: DOCUMENT FILING

After the filing decision is complete:

- Assign the category.
- Save the extracted information.
- Assign a meaningful document title.
- Make the document searchable.
- Update its filing status.

The original uploaded document must be preserved.

STEP 7: DOCUMENT RETRIEVAL

The user can find the document from My Files.

Users should not need to remember the original
uploaded filename.


==================================================
7. FILING PREFERENCE LEARNING
==================================================

RecordPlus should become more useful as users continue
using it.

However, we do not need to train a custom AI model
for every user.

The system should learn through confirmed filing
decisions and reusable rules.

EXAMPLE:

A user uploads a petrol receipt under YK NEXUS.

RecordPlus identifies:
Document Type: Receipt
Suggested Category: Expenses

The user confirms Expenses.

RecordPlus remembers this confirmed decision.

Later, the user uploads another similar receipt.

RecordPlus can use the previous decision when
suggesting the filing category.

After repeated consistent confirmations, RecordPlus
can suggest creating an automatic filing rule.

Example rule:

Active Profile:
YK NEXUS

Document Type:
Receipt

Matching Merchant:
Confirmed petrol merchant

Filing Category:
Expenses

Once approved, matching documents can be
automatically filed.

IMPORTANT RULES:

1. Learning must remain specific to each profile.
2. Explicit user decisions take priority.
3. Existing confirmed rules take priority over
   AI guesses.
4. One correction should not automatically create
   a permanent rule.
5. Users must be able to correct filing decisions.
6. Users must be able to disable or change learned
   rules.
7. Conflicting or uncertain results require review.
8. The system must not change previously filed
   documents silently.

The goal is to reduce user interaction over time
without making filing unpredictable.


==================================================
8. MODULE ONE — DASHBOARD
==================================================

PURPOSE:

Give users a simple overview of their documents
and filing activity.

This will be the default landing page.

MAIN COMPONENTS:

A. PROFILE SELECTOR

Display the current active profile prominently.

Allow users to:
- Switch profiles
- Create a profile
- Rename a profile where appropriate

Changing profile updates the information displayed
across Dashboard, Capture, and My Files.

B. DOCUMENT OVERVIEW

Display:

- Total Documents
- Successfully Filed
- Needs Review
- Currently Processing

C. RECENT DOCUMENTS

Show recently uploaded documents.

Each item should show:
- Document title
- Document type
- Category
- Date
- Filing status

Users should be able to open the document directly.

D. FILING ACTIVITY

Show a simple summary of the engine's activity.

Examples:
- 15 documents uploaded this week.
- 12 documents successfully filed.
- 3 documents require review.

E. FILING INSIGHTS

Where reliable data is available, show useful
document insights.

Examples:
- Documents uploaded this month
- Documents by category
- Automatic filing rate
- Estimated filing time saved

Do not overcrowd the dashboard with charts.

Do not display speculative financial figures
as confirmed accounting results.

F. QUICK ACTION

Provide a prominent Capture action.

The user should be able to start uploading
immediately from the dashboard.

DASHBOARD GOAL:

Users should quickly understand:

1. Which profile they are using.
2. How many documents are stored.
3. Whether their documents are organised.
4. Which documents need attention.
5. How to upload the next document.


==================================================
9. MODULE TWO — CAPTURE
==================================================

PURPOSE:

Make adding documents extremely easy.

This is the most important module in RecordPlus.

CAPTURE OPTIONS:

- Take a picture using the device camera.
- Upload an image from the device.
- Upload a PDF or supported document.
- Select multiple files for bulk upload.

USER EXPERIENCE:

1. User opens Capture.
2. Current profile is clearly displayed.
3. User takes a picture or selects a file.
4. RecordPlus uploads and saves the document.
5. Gemini analyses the document automatically
   when AI processing is available.
6. RecordPlus determines the filing recommendation.
7. System either files the document using trusted
   rules or requests user confirmation.
8. The document becomes accessible in My Files.

Do not require the user to manually enter:
- Filename
- Document type
- Category
- Merchant
- Document date

These should be automatically suggested when
AI can reliably identify them.

Users must still be able to correct information.

PROCESSING FEEDBACK:

Display clear, simple statuses such as:

- Uploading
- Analysing
- Filed Successfully
- Needs Review
- Processing Failed

If Gemini processing fails:
- Preserve the uploaded document.
- Allow the user to retry analysis.
- Allow manual filing.
- Never silently discard the document.

BULK UPLOAD:

When uploading multiple documents:
- Process each document independently.
- Display each document's progress.
- Do not allow one failed document to block others.
- Keep the original active profile for every file.

CAPTURE GOAL:

The ideal interaction is:

OPEN CAPTURE
    ↓
TAKE PICTURE
    ↓
RECORDPLUS ANALYSES
    ↓
DOCUMENT FILED

The fewer steps required, the better.


==================================================
10. MODULE THREE — MY FILES
==================================================

PURPOSE:

Allow users to easily locate, browse, and manage
their uploaded documents.

Keep the experience familiar, similar to a simple
file explorer.

MAIN COMPONENTS:

A. CURRENT PROFILE

My Files only displays documents belonging to
the active profile.

Documents from other profiles must never appear
unless the user explicitly switches profiles.

B. CATEGORY EXPLORER

Show categories as familiar folders.

Example:

YK NEXUS
|
|-- Expenses
|-- Income
|-- Debtors
|-- General Documents

Users can open categories to browse documents.

The categories do not need to represent physical
folders on the storage server.

C. DOCUMENT SEARCH

Allow searching using:

- Document title
- Document type
- Merchant or organisation
- Date
- Reference number
- Extracted document text, where available

The objective is to make documents easy to find
without remembering exact filenames.

D. DOCUMENT LIST

Each document should display:

- Meaningful title
- Type
- Category
- Date
- Status

E. DOCUMENT PREVIEW

Allow users to:
- View document
- Inspect extracted information
- See assigned category
- Correct extracted information
- Change filing category
- Rename document
- Download original document
- Delete document with confirmation

F. NEEDS REVIEW

Provide a filter or section for documents requiring
user attention.

Examples:
- Category uncertain
- New category suggested
- Unable to identify document type
- Missing important information
- Conflicting filing rules

Users can resolve issues without leaving My Files.

G. BASIC CATEGORY MANAGEMENT

Allow users to:
- Create a category
- Rename a category
- Manage categories within the active profile

Avoid complicated folder structures for V1.

MY FILES GOAL:

Users should be able to find the document they need
within seconds.

The document organisation should feel familiar,
but users should not need to perform repetitive
manual filing.


==================================================
11. FILING STATUS MANAGEMENT
==================================================

Every uploaded document should have a clear state.

USER-FACING STATES:

1. Processing
   Document is being uploaded or analysed.

2. Filed
   Document has an accepted filing destination.

3. Needs Review
   Document requires user confirmation or correction.

4. Uncategorised
   Document is safely stored but has no final category.

5. Processing Failed
   Automatic analysis failed, but the original
   document is still accessible.

These states must be easy to understand.

The system should not display technical errors
to ordinary users.

Provide clear recovery actions.

For example:

"We couldn't analyse this document.
You can retry or file it manually."


==================================================
12. USER EXPERIENCE PRINCIPLES
==================================================

RecordPlus must be extremely simple.

DESIGN PRINCIPLES:

1. Minimal navigation.
2. Clear active profile.
3. Capture always easy to access.
4. Reduce unnecessary forms.
5. Avoid repeated questions.
6. Use familiar terms such as Files and Categories.
7. Keep AI processing mostly behind the scenes.
8. Provide understandable progress feedback.
9. Allow users to correct AI mistakes.
10. Prioritise mobile usability.
11. Use consistent layouts across Web and PWA.
12. Keep advanced features away from primary workflows.

AVOID:

- Complicated setup wizards.
- Excessive dashboard charts.
- Too many menu items.
- Making users repeatedly select categories.
- Technical AI configuration screens.
- Automatic profile switching.
- Unapproved category creation.
- Unexplained changes to document organisation.

RecordPlus should feel like a simple filing assistant,
not an enterprise document management platform.


==================================================
13. SECURITY AND DOCUMENT RELIABILITY
==================================================

RecordPlus will manage important personal and
business documents.

The system must protect user information.

EXPECTED BEHAVIOUR:

- Every document belongs to an authorised profile.
- Profiles remain isolated.
- Users cannot access other users' private documents.
- Original files are stored securely.
- Documents must not be publicly accessible.
- Deletion requires appropriate confirmation.
- Failed processing must not cause file loss.
- Users must be able to retrieve their original files.
- Document storage must have a backup and recovery plan.
- Gemini must never execute instructions found inside
  uploaded document contents.
- AI results must be treated as extracted suggestions,
  not verified facts.

These are foundational requirements, not features
to postpone until Settings is developed.


==================================================
14. AI AVAILABILITY AND PREMIUM READINESS
==================================================

The system should support both manual and
AI-assisted filing.

FREE / MANUAL EXPERIENCE:

Users can:
- Upload documents.
- Manually assign categories.
- Manage documents.
- Search and retrieve files.

AI-ASSISTED EXPERIENCE:

When AI access is enabled:
- Automatically analyse uploaded documents.
- Identify document types.
- Extract metadata.
- Recommend filing categories.
- Suggest meaningful filenames.
- Apply approved automatic filing rules.

Prepare the application for future AI usage limits
and paid subscriptions.

However, subscription management and billing
screens are NOT part of the current development scope.

Do not hardcode the assumption that every user
has unlimited AI usage.

Actual pricing and plan limits will be finalised later.


==================================================
15. DEVELOPMENT PHASES
==================================================

PHASE 1 — APPLICATION FOUNDATION

Goal:
Establish the basic RecordPlus user experience.

Deliver:
- User accounts and sign-in
- Initial profile onboarding
- Profile creation and switching
- Suggested initial categories
- Shared Web/PWA application
- Basic navigation
- Secure document storage

Completion criteria:
Users can sign in, select their profile, and
navigate between the three modules.


PHASE 2 — CAPTURE AND DOCUMENT UNDERSTANDING

Goal:
Make uploading and analysing documents functional.

Deliver:
- Camera capture
- File upload
- Multiple document upload
- Gemini document analysis
- Document type identification
- Metadata extraction
- Suggested filenames
- Processing status and failure handling

Completion criteria:
A user can upload a receipt and RecordPlus
can analyse its contents while keeping the
original document safely stored.


PHASE 3 — INTELLIGENT CATEGORY FILING

Goal:
Allow RecordPlus to organise documents intelligently.

Deliver:
- Existing category matching
- Suggested category creation
- User category confirmation
- Document filing
- Needs Review handling
- Correcting filing decisions
- Profile-specific filing preferences
- Basic approved automatic filing rules

Completion criteria:
A user can upload a document and RecordPlus
can recommend the right category.

Trusted matching rules may file documents automatically.

Unknown documents remain accessible for review.


PHASE 4 — MY FILES

Goal:
Make all filed documents easy to find and manage.

Deliver:
- Category explorer
- Document listing
- Search and filtering
- Document previews
- Metadata display
- Editing document details
- Changing categories
- Downloads
- Deletion
- Needs Review workflow

Completion criteria:
Users can locate, view, correct, and download
their documents without difficulty.


PHASE 5 — DASHBOARD

Goal:
Provide a meaningful summary of filing activity.

Deliver:
- Profile selector
- Document statistics
- Recently uploaded documents
- Processing summaries
- Needs Review overview
- Simple filing insights
- Quick access to Capture

Completion criteria:
Users can understand their filing status
at a glance.


PHASE 6 — PWA AND PRODUCT REFINEMENT

Goal:
Make RecordPlus feel like a polished application.

Deliver:
- Installable PWA
- Responsive mobile and desktop layouts
- Clear offline messaging
- Improved upload experience
- Better processing feedback
- Error and empty states
- Performance optimisation
- End-to-end user testing

Completion criteria:
Users can use RecordPlus through a browser
or install it on supported devices and
access the same documents and profiles.


==================================================
16. REAL-WORLD ACCEPTANCE SCENARIOS
==================================================

SCENARIO 1 — PERSONAL SHOPPING RECEIPT

1. User switches to Personal.
2. User photographs a shopping receipt.
3. Gemini detects it as a receipt.
4. Gemini extracts merchant, amount, and date.
5. RecordPlus suggests an existing category.
6. User confirms if required.
7. Document is filed under Personal.
8. User retrieves it later using search.

EXPECTED:
No business profile should be involved.


SCENARIO 2 — BUSINESS INVOICE

1. User selects YK NEXUS.
2. User uploads a supplier invoice.
3. Gemini identifies it as an invoice.
4. Gemini extracts relevant invoice details.
5. RecordPlus suggests Expenses or another
   suitable existing category.
6. User confirms if required.
7. Document is filed under YK NEXUS.

EXPECTED:
The system must not assume the invoice was paid.


SCENARIO 3 — NEW CATEGORY SUGGESTION

1. User selects YK NEXUS.
2. User uploads an insurance policy.
3. Gemini identifies the document type.
4. Existing categories do not adequately match.
5. RecordPlus suggests Insurance.
6. User approves the new category.
7. Document is filed under Insurance.

EXPECTED:
No category is created without approval.


SCENARIO 4 — LEARNED FILING PREFERENCE

1. User files multiple similar receipts.
2. RecordPlus records confirmed filing decisions.
3. System identifies a consistent pattern.
4. RecordPlus suggests a reusable filing rule.
5. User approves the rule.
6. Future matching documents are automatically filed.

EXPECTED:
The rule applies only to the relevant profile.


SCENARIO 5 — PROFILE SWITCH DURING PROCESSING

1. User selects YK NEXUS.
2. User uploads several documents.
3. Gemini processing begins.
4. User switches to Personal.
5. Processing finishes.

EXPECTED:
All earlier uploads remain under YK NEXUS.

The active profile change must not affect
documents already submitted.


SCENARIO 6 — AI PROCESSING FAILURE

1. User uploads a document.
2. Gemini cannot analyse the document.
3. RecordPlus displays a processing failure.
4. User opens the document.
5. User files it manually or retries.

EXPECTED:
The original file must never be lost.


SCENARIO 7 — DOCUMENT RETRIEVAL

1. User opens My Files.
2. User searches for a specific invoice.
3. RecordPlus displays matching documents.
4. User opens the correct document.
5. User downloads the original file.

EXPECTED:
The user should not need to remember
the document's original filename or category.


==================================================
17. WHAT NOT TO DEVELOP YET
==================================================

The following are outside the current V1 scope:

- Dedicated Settings module
- Subscription and payment screens
- Complex financial accounting
- Tax calculations
- Payroll management
- Advanced financial forecasting
- Multi-user approval workflows
- Team collaboration
- External accounting integrations
- Email inbox integrations
- Automated document reminders
- Advanced workflow automation
- Custom AI model training
- Complex nested folder structures
- Full offline document synchronisation

These features may be considered in later releases.

Do not introduce them into V1 unless they are
essential for an agreed core workflow.


==================================================
18. HOW WE WILL MEASURE SUCCESS
==================================================

RecordPlus V1 should demonstrate measurable
improvements to document filing.

Important metrics:

1. Time required to upload and file a document.
2. Percentage of documents classified correctly.
3. Percentage of documents filed automatically.
4. Number of documents requiring manual correction.
5. Time required to retrieve an existing document.
6. Percentage of users who return to upload more files.
7. Reliability of document storage and retrieval.

Our objective is not to maximise the number
of features.

Our objective is to minimise the work required
to organise and retrieve documents.

The main validation question is:

"Can users file and retrieve their documents
with significantly less manual effort than before?"


==================================================
19. FINAL PRODUCT DIRECTION
==================================================

RecordPlus must deliver three core capabilities:

CAPTURE
Users can easily upload any supported document.

UNDERSTAND AND FILE
RecordPlus uses Gemini and established filing rules
to understand and organise documents within
the user's selected profile.

FIND
Users can quickly retrieve documents whenever needed.

The entire application should revolve around
these capabilities.

Do not design RecordPlus as a complicated ERP,
accounting system, or traditional enterprise DMS.

It is an intelligent filing assistant.

Our desired user experience:

"Choose your profile once.
Capture your documents.
RecordPlus handles the filing.
Find anything when you need it."

DEVELOPMENT INSTRUCTION:

Build the project incrementally according to
the phases above.

Keep the implementation clean, maintainable,
secure, and scalable.

You may independently determine the appropriate
technical architecture, components, database
structure, and implementation details.

However, do not change the agreed product behaviour
or introduce major functionality outside the scope.

Prioritise completing a functional end-to-end filing
journey before adding enhancements.

The most important milestone is:

A user opens RecordPlus, selects YK NEXUS,
takes a picture of a receipt, and RecordPlus
understands, organises, stores, and makes it
retrievable with minimal manual interaction.

That is the RecordPlus V1 product we are building.

==================================================
END OF DEVELOPMENT MASTER PLAN
==================================================
