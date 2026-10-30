<?php
/** User manual — owner / customer edition. Content only; rendered by views/manual.php.   GAS-CP-R20-002
 *  Each section: id, title, body (array of paragraphs — plain text, or ['ul' => [...]] / ['ol' => [...]] / ['fig' => file, 'cap' => caption] / ['tip' => text]). */
return [
  'title' => 'Customer Portal — User Manual (owners and customers)',
  'lede'  => 'How to follow your vessels and projects, approve documents, raise tickets, accept service reports and keep track of what changed — from any browser, on a computer, tablet or phone.',
  'sections' => [
    ['id' => 'signin', 'title' => 'Signing in', 'body' => [
      'Your Golden A/S project manager creates your account and sends you the address of the portal, your e-mail address as user name and a first password. Open the address in any modern browser (Edge, Chrome, Safari, Firefox) — nothing is installed on your device.',
      ['fig' => 'c-login.jpg', 'cap' => 'The sign-in page — e-mail and password, then the six-digit code from your authenticator app'],
      ['ol' => ['Enter your e-mail address and password and press Sign in.', 'The first time, the portal asks you to set up two-factor authentication: scan the QR code with an authenticator app (Microsoft Authenticator, Google Authenticator, Authy …) and type the six-digit code it shows.', 'On every later sign-in, type the current six-digit code from the app after your password.']],
      'Your session ends after 30 minutes without activity (your administrator may set another value), after 10 hours at most, and when you close the browser — reopening the window or restoring the tabs brings you back to the sign-in page, never straight into the portal. A session is also tied to the browser it was opened in. Under My account › My sessions you can sign out of every browser at once; do that and change your password if you suspect that someone else used your account. Too many wrong passwords lock the account for a short time — wait and try again, or contact your project manager.',
      ['tip' => 'Lost your phone or the authenticator app? Ask your Golden A/S project manager to reset two-factor authentication; you will set it up again at the next sign-in.'],
      'If the portal ever shows "The portal is locked", its service contract is being renewed — nothing of yours is lost; contact your project manager and try again later.',
    ]],
    ['id' => 'layout', 'title' => 'Finding your way around', 'body' => [
      'The portal has three parts: the top bar, the menu on the left and the page itself. Under the top bar a clock strip shows the time in UTC, Greece (home, framed in gold), the UAE, the United Kingdom, Italy, the Netherlands, Germany and Dallas, 24-hour, with the date and ISO week. The Greek clock carries a small weather badge; click any city for sunrise / sunset, daylight length, the conditions now, today\'s minimum / maximum, rain chance, wind and the hourly forecast.',
      ['fig' => 'c-weather.jpg', 'cap' => 'The clock strip with the weather badge and the pop-over for Athens'],
      ' The top bar shows the bell with your unseen changes, the theme switch (light / dark), the gear for your account settings, the language as a two-letter code (EN, GR, IT, UA, BG — English, Ελληνικά, Italiano, Українська, Български) and, at the far right, the sign-out icon (⇥). The menu is grouped like your work: Fleet (vessels and projects), Work (tickets, work orders, service reports), Commercial (offers, owner approvals) and Library (documentation, IT & cybersecurity, support). On a phone the menu folds behind the ≡ button.',
      ['fig' => 'c-dashboard.jpg', 'cap' => 'The dashboard — fleet progress, what needs your attention, this week across the fleet and your vessels'],
      ['ul' => ['Gold badges on a menu entry count items waiting for you — tickets waiting for your reply, documents to approve, offers to decide.', 'A gold dot on a menu group means something changed there that you have not seen yet (see Notifications).', 'Long lists — projects, vessels, service and inspection reports, offers, approvals, tickets — show 25 rows per page with a pager under the table (25 / 50 / 100 per page); filters and totals always cover the whole list.', 'Breadcrumbs above every page title lead back to the vessel or project.', 'Every list has filters at the top; the Apply button runs them, Clear resets.']],
      ['ul' => ['Any table sorts on click: click a column header to sort by that column, click again for the opposite direction. Numbers and dates are recognised.']],
    ]],
    ['id' => 'listfilters', 'title' => 'Lists remember your filters', 'body' => [
      ['ul' => ['Searchable drop-downs: a drop-down with more than eight entries (vessel, company, customer, contact, project, assignee…) opens with a search line — type any part of the name, IMO or code to narrow the list, use ↓ ↑ and Enter to choose, Esc to close. Short lists stay plain drop-downs.']],
      'The lists of tickets, vessels, projects, offers, equipment, approvals and the Open issues tab keep the last filter you applied: opening the list again from the menu or from a Back link shows the same selection, announced by an amber banner above the filter bar (“Showing your previous selection — items outside these filters are not shown”) with the Clear button in it. Apply stores a new selection, Clear returns to the defaults. The memory belongs to your sign-in on this device.',
    ]],
    ['id' => 'dashboard', 'title' => 'The dashboard', 'body' => [
      'The dashboard opens after sign-in and summarises everything in four blocks: the headline figures (fleet progress, open tickets, offers awaiting your decision, approvals waiting for you), progress by sub-project with the seven process stages, what needs your attention (sorted by how long it has waited), and this week across the fleet — every change of the last seven days with its date.',
      ['ul' => ['Click a figure to open the corresponding list already filtered.', 'Each sub-project bar shows the seven stages ENG · PROC · PROD · FAT · INST · SAT/HAT · COMM; green segments are complete, the gold one is in progress.', 'The Fleet block at the bottom shows every vessel with its progress ring and open-ticket count.']],
    ]],
    ['id' => 'vessels', 'title' => 'Vessels', 'body' => [
      'Fleet › Vessels lists your vessels with their projects and open tickets. Open a vessel for its technical history: the projects and sub-projects on board, the systems installed, tickets, work orders and service reports in date order, and the weekly reports.',
      ['ul' => ['The Projects filter narrows the list to vessels with active projects, with any project, or without projects; the Projects column shows the total and the active count.']],
      ['fig' => 'c-vessels.jpg', 'cap' => 'The vessel list'],
      ['fig' => 'c-vessel.jpg', 'cap' => 'The vessel page — history, projects, open items and the report buttons'],
      ['ul' => ['Technical history (PDF / Word) is a complete dossier of the vessel: every project, document, ticket and service report — and, since v1.27.1, the equipment on board with its service history.', 'The Equipment tab of the vessel lists the units Golden A/S looks after on board by serial number (see Equipment on board).', 'Weekly vessel report opens the week view with everything that happened in a chosen week; PDF and Word versions can be downloaded.', 'The project selector at the top narrows the page to one project when a vessel has several.']],
      ['fig' => 'c-weekly.jpg', 'cap' => 'The weekly vessel report — one week, all projects on board, with the issued revisions underneath'],
    ]],
    ['id' => 'equipment', 'title' => 'Equipment on board — service history by serial number', 'body' => [
      'Fleet › Equipment lists the units Golden A/S looks after on your vessels — shore converters and other systems — by serial number. The Equipment tab of a vessel shows what is on board; a serial number opens the unit data (maker, model, warranty, first attendance, contacts on board) and its service history: every issued service report that concerned the unit, with the work done, the open issues and the parts replaced, plus the maker records and the records written by our engineers.',
      ['fig' => 'g-equipment-vessel.jpg', 'cap' => 'The Equipment tab of a vessel'],
      ['ul' => ['Use the search box for a serial number, model or contact; the status filter separates units in service, retired and under warranty.', 'History PDF / History Word on a serial number downloads the service-history document of the unit; the vessel technical-history report also lists the equipment on board with its service history.', 'The history also carries the issued inspection reports that concerned the unit, and the unit page shows the contacts Golden A/S linked to it.', 'The history is read-only on your side — ask through a ticket if a record needs a correction.']],
      ['fig' => 'g-equipment-pdf.jpg', 'cap' => 'The service-history document of a serial number'],
    ]],
    ['id' => 'projects', 'title' => 'Projects and sub-projects', 'body' => [
      'Fleet › Projects shows the project tree: each main project (e.g. GY-85-02) with its sub-projects (Y.01, Y.02 …), their progress, deadlines, offers and open items. A sub-project is one system or one package of work — the main switchboard, the lighting control, the bridge NAVCOM.',
      ['fig' => 'c-projects.jpg', 'cap' => 'The project tree — main projects, sub-projects, progress and offers'],
      'Every sub-project goes through the same seven stages. Golden A/S updates the percentage of each stage; the overall figure is the average of the stages that apply — a stage that does not exist for a sub-project (no FAT for a pure installation job, say) is shown hatched as N/A and does not hold the percentage back.',
      'The project code in the breadcrumb of a sub-project, report, offer or approval page links back to that project on the project list, opened and scrolled into view.',
      ['fig' => 'stages.svg', 'cap' => 'The seven process stages of a sub-project'],
      ['fig' => 'c-subproject.jpg', 'cap' => 'A sub-project — the Process tab with the stages, the offers on top and the tabs for drawings, approvals, class, tests, notes, comments, files and reports'],
      ['ul' => ['Process — the stage percentages and the progress history (snapshots).', 'Drawings — the drawings and information Golden A/S has requested from the yard or the owner, with responsible party, dates and status; you can see which are still outstanding. Every request still pending is also listed in the weekly report (Approvals section — oldest expected first, red when overdue) and in the monthly report until it is received; a new request raises a notification in your bell.', 'Owner approval — the documents submitted for your approval (see the next section).', 'Class — submittals to the classification society and their status.', 'FAT / HAT / SAT — factory, harbour and sea acceptance tests with their reports.', 'Weekly notes — the progress notes written by Golden A/S, with photos.', 'Service reports — the field service reports of this sub-project.', 'Comments — a conversation thread between you and Golden A/S on this sub-project. Every text box in the portal (comments, ticket descriptions, notes) has a small toolbar: B, I, U, bulleted and numbered lists (Ctrl+B / I / U; Enter continues a list, an empty item ends it). The text is kept readable everywhere — it shows formatted on screen and clean in PDF and Word reports.', 'Files — every file shared with you for this sub-project.', 'Reports — the monthly sub-project report (PDF / Word).']],
      ['fig' => 'c-drawings.jpg', 'cap' => 'The Drawings tab — what was requested, from whom, and whether it has arrived'],
      ['ul' => ['Each project in the tree also lists its open tickets (number, subject, status, who is handling it, age) with + New ticket already set on the project.']],
    ]],
    ['id' => 'issues', 'title' => 'Open issues', 'body' => [
      'The Open issues tab of a sub-project lists what is still pending after the visits of our engineers — the open issues of the service reports and the points added by hand — with its permanent number (OI-…), who has to act (Golden A/S, your crew or a third party), the due date and the status. You can add an issue yourselves, close one when your side has done its part (a short note helps) and re-open it if needed. The weekly, monthly and vessel reports print the same list.',
      ['fig' => 'c-issues.jpg', 'cap' => 'The Open issues tab on the owner side'],
    ]],
    ['id' => 'colours', 'title' => 'Colour code in the reports', 'body' => [
      'The weekly vessel report, the technical history, the monthly sub-project report, the inspection reports and the equipment service history use one colour code, explained in a legend under the title of each document: a narrow coloured bar at the left of a row and the figure that caused it (a due date, a number of days, a risk) in the same colour. Red — overdue, failed, high risk, rejected or delayed; amber — due within 7 days, pending, medium risk or draft; green — done, on time, accepted or closed; navy — in progress; grey — not applicable. Rows that need no attention carry no bar.',
      ['fig' => 'g-colour-code.jpg', 'cap' => 'A weekly vessel report with the legend and the row markers'],
    ]],
    ['id' => 'approvals', 'title' => 'Owner approvals', 'body' => [
      'Commercial › Owner approvals lists every drawing and document Golden A/S has submitted for your approval, with the revision, the date submitted and the due date. The gold badge in the menu counts the ones still waiting for you.',
      ['fig' => 'c-approvals.jpg', 'cap' => 'The approvals list — waiting, approved, approved with comments, rejected'],
      ['fig' => 'flow-approval.svg', 'cap' => 'The approval flow'],
      ['ol' => ['Open the document; the file is shown or downloaded from the Document card.', 'Write your remarks in the Comments on the document box if you have any.', 'Press Approve, Approve with comments or Reject. Comments are mandatory for the last two.', 'The decision is recorded with your name, date, time and IP address and is visible to Golden A/S at once; the document can no longer be changed.', 'After a rejection Golden A/S submits a new revision; the old one stays in the list for reference.']],
      ['fig' => 'c-approval.jpg', 'cap' => 'The approval page — document, your decision, and the comment thread with Golden A/S'],
      ['tip' => 'Use the comment thread for questions before you decide; Golden A/S is notified of every comment and answers in the same thread.'],
    ]],
    ['id' => 'tickets', 'title' => 'Tickets — faults, service and questions', 'body' => [
      'Work › Tickets is the place to report a fault, ask for service or spare parts, or ask a question about a system on board. Each ticket has a number (TK-YYYY-NNNN), a vessel, optionally a project and system, a category and a priority.',
      ['fig' => 'c-tickets.jpg', 'cap' => 'The ticket list with filters, aging and the attention strip'],
      ['ol' => ['Press + New ticket, choose the vessel (and the project or system when you know it), a category — fault, service, warranty, spare parts, question, customer complaint (when you want to raise a complaint about our service or the quality of a work; it is tracked separately by our management), other — and a priority, describe the problem and attach photos or files.', 'Golden A/S assigns an engineer; the status moves to In progress.', 'When Golden A/S needs something from you the status becomes Waiting for owner and the ticket appears in your attention list — answer in the Comments.', 'When the work is done the ticket is Resolved with a written resolution; if the problem persists, reply and it is re-opened; otherwise it is closed.']],
      ['fig' => 'flow-ticket.svg', 'cap' => 'The ticket lifecycle'],
      ['fig' => 'c-ticket-new.jpg', 'cap' => 'The new-ticket form'],
      ['fig' => 'c-ticket.jpg', 'cap' => 'A ticket — details, comments with attachments, linked work orders and service reports, and the full history'],
    ]],
    ['id' => 'tickets_history', 'title' => 'Older tickets (FD-nnnn)', 'body' => [
      'Tickets numbered FD-nnnn are the history of your vessels from our previous helpdesk, brought into the portal so that everything is in one place: the request as raised, the replies exchanged and the final status, with their original dates. Their first line says where they came from. They are read-only history — open a new ticket for anything current.',
    ]],
    ['id' => 'joborders', 'title' => 'Work orders', 'body' => [
      'A work order is a defined piece of work on a project — a request from you to Golden A/S, or from Golden A/S to you for approval. Work › Work orders lists them per project with their status.',
      ['fig' => 'c-joborders.jpg', 'cap' => 'Work orders — requested, pending approval, approved, in progress, done'],
      ['fig' => 'flow-jo.svg', 'cap' => 'The work-order flow — the requesting side submits, the other side approves'],
      ['ul' => ['To request work, open the project (or the ticket it relates to) and press + Work order; describe the work, the location and the date you need it.', 'A work order Golden A/S requests from you appears with Approve / Reject buttons; a rejection needs a note.', 'When the work is done, the service report is linked to the work order.']],
    ]],
    ['id' => 'fsr', 'title' => 'Service reports — accepting the engineer\'s work', 'body' => [
      'After an intervention on board, the Golden A/S engineer submits a field service report (form SF-GLD-014): date, location, the tasks carried out, the hours, spare parts used, open issues and photos. Work › Service Reports lists them; the ones waiting for your acceptance carry a badge.',
      ['ul' => ['A service call without a project (a visit for a fault, a check) is reported under the vessel directly — you find it under the vessel\'s Services tab and in the report list. A report marked External is a document Golden A/S received from elsewhere (yard, maker) and filed for you; it may later be followed by a portal report "from EXT-…" with the detailed hours.']],
      ['fig' => 'c-reports.jpg', 'cap' => 'The service-report list'],
      ['fig' => 'flow-fsr.svg', 'cap' => 'Submit · accept or dispute · re-open'],
      ['ol' => ['Open the report and read the tasks and hours.', 'Press Accept to sign it electronically — your name, date, time and IP address are recorded on the report; the PDF and Word versions are generated and archived.', 'If something is wrong, press Dispute and write what: Golden A/S corrects the report and submits a new version.']],
      ['fig' => 'c-fsr.jpg', 'cap' => 'A service report — report data, hours summary, tasks and the acceptance block'],
      ['ul' => ['When a report concerns registered equipment (shore converters, propulsion controls …), the work done, the open issues and the parts replaced also appear in the service history of each unit — Fleet › Equipment › serial number.']],
    ]],
    ['id' => 'isr', 'title' => 'Inspection reports', 'body' => [
      'After a condition survey on board, Golden A/S issues an inspection report (form SF-GLD-020): the subject and objective of the inspection, the main findings and recommendations, the risks identified and, for each inspected system, the condition as found, the checklist answered, the class regulations that apply, a risk level with its analysis, the proposed corrective actions and the photos or documents taken. Reports appear on Work › Service Reports and on the sub-project\'s Service reports tab once they have been checked and issued by Golden A/S; download the PDF or the Word version from the report page.',
      ['fig' => 'c-isr.jpg', 'cap' => 'An inspection report — report data, introduction and the inspected systems with their risk levels'],
      ['ul' => ['An issued inspection report that concerned registered equipment also appears in the service history of each unit, with the findings, risk level and corrective actions.', 'The highest risk level of a report is shown on the list, so the surveys that need action first stand out.', 'A corrected report is issued again as the next revision (R1, R2 …); the earlier documents stay in the archive.', 'Ask questions about a finding through a ticket under the same project — it stays linked to the vessel\'s history.']],
    ]],
    ['id' => 'offers', 'title' => 'Offers', 'body' => [
      'Commercial › Offers lists Golden A/S\'s offers for your projects with number, revision, subject, amount, status and how long they have been waiting. The gold badge counts the offers awaiting your decision.',
      ['fig' => 'c-offers.jpg', 'cap' => 'The offer list'],
      ['fig' => 'flow-offer.svg', 'cap' => 'Offer statuses'],
      ['ol' => ['Open the offer; download the offer document from the Document card.', 'Press Approve or Reject and add a note if you wish. The decision is recorded with your name, date, time and IP address.', 'Approved offers move to In progress when the work starts; the status history is shown on the offer page.']],
      ['fig' => 'c-offer.jpg', 'cap' => 'An offer — document, decision and history'],
    ]],
    ['id' => 'forecast', 'title' => 'Weekly works forecast — where we will work next week', 'body' => [
      'For every project Golden A/S issues a weekly works forecast: the deck plans of your vessel with the areas we intend to work in next week (Plan A, solid) and the alternative areas if those are not available (Plan B, dashed), the sub-projects and works per area, the days, and the notes. You find it on the project (Weekly works forecast) and in the bell when it is issued.',
      ['fig' => 'g-wf-forecast.jpg', 'cap' => 'A weekly works forecast — deck map with Plan A and Plan B'],
      ['ol' => ['Reply on the forecast page to ask for a change of the schedule, or to tell us about a delay foreseen, an obstacle in a space, or painting planned in an area — choose the kind, the area and the dates. Golden A/S is notified at once.', 'Your remarks are carried into the final weekly schedule (the next revision of the forecast), together with a line on how the schedule was adapted; the issued revisions are listed on the page with their PDF and Word.']],
    ]],
    ['id' => 'remote', 'title' => 'Remote support contracts', 'body' => [
      'Library › IT & Cybersecurity lists the remote support contracts of your vessels — period, hours included and used, agreed response time, coverage hours — next to the cyber documents. A ticket you raise on a vessel with a live contract is covered by it; the ticket page shows the contract and the remote sessions Golden A/S logged against it.',
      ['fig' => 'g-rs-list.jpg', 'cap' => 'IT & Cybersecurity — your remote support contracts'],
    ]],
    ['id' => 'documents', 'title' => 'Documentation', 'body' => [
      'Library › Documentation holds the manuals, drawings, certificates, test reports and other documents Golden A/S has published for your vessels, with their category and status. Filter by vessel or category and download.',
      ['fig' => 'c-documents.jpg', 'cap' => 'The documentation library'],
      'Library › IT & Cybersecurity lists the cyber-security information for the systems on board (networks, remote access, update policy), when it applies to your vessels.',
    ]],
    ['id' => 'notifications', 'title' => 'Notifications — what changed since you last looked', 'body' => [
      'The bell in the top bar counts everything that changed and that you have not seen yet: a comment on a ticket, a service report submitted, an offer sent, a new weekly note, a document submitted for approval. Click it for the newest entries, or open All notifications for the full list grouped like the menu (Fleet · Work · Commercial · Library).',
      ['fig' => 'c-notifications.jpg', 'cap' => 'The Notifications page — tabs per group, unseen first, Open and Mark as seen'],
      'To call a specific Golden A/S person in a ticket comment, an approval comment, a sub-project comment or a forecast reply, type @ and pick the name from the list that opens: the person is notified at once and the handle is highlighted in your text.',
      ['ul' => ['A weekly works forecast issued for one of your projects, and every revision after your replies, arrives here too — Open shows the deck maps and the reply form.', 'Open takes you to the record and marks the entry as seen.', 'Mark as seen, Mark group as seen and Mark all as seen clear entries without opening them.', 'You never receive an entry for something you did yourself.']],
    ]],
    ['id' => 'account', 'title' => 'Your account, language, theme and support', 'body' => [
      'The gear in the top bar opens your account: change your password, choose the language and the look (Editorial — light, or Glass — dark), upload a photo, and manage two-factor authentication. The ☾ / ☀ button in the top bar switches the theme at any time; the choice is stored on your account.',
      ['fig' => 'c-account.jpg', 'cap' => 'Account settings'],
      'Library › Support sends a message to Golden A/S for anything that is not a technical ticket — access, a missing vessel, a question about the portal. Your previous requests are listed on the same page.',
      ['fig' => 'c-support.jpg', 'cap' => 'The Support page'],
      'The search box at the top of the Support page searches this guide: type a few words (a page name, a document number prefix, "approval", "Plan B") and the matching sections are listed with the words highlighted; open one and the guide shows that section with the others folded. The filter box above the contents of the guide does the same as you type.',
    ]],
    ['id' => 'kb', 'title' => 'Knowledge base — ask and answer', 'body' => [
      'Library › Knowledge base (also on the Support page) is a shared board of questions and answers from everybody who uses the portal — owners, captains, engineers and Golden A/S. Search it before opening a support request; most practical questions have been asked before.',
      ['ol' => ['Search by words in the question or the answers; filter by category (Using the portal, Systems on board, Documents & approvals, Service & tickets, Other) and by status (open, solved, unanswered).', 'Ask a question: a clear title, the details of what you tried, the category, and up to five attachments (photos, PDF, drawings — 20 MB each) when they help. Never include passwords or confidential commercial figures — the board is visible to every user of the portal.', 'Answer a question when you know: the answer appears with your name; answers from Golden A/S people carry a Golden A/S tag. Questions and answers can carry up to five attachments — photos of the panel, a PDF, a drawing; images are shown as thumbnails.', 'If an answer solved your question, press Mark as the answer — the question is shown as solved. Press 👍 on any answer that helped you.']],
      ['tip' => 'Administrators may delete questions or answers that are wrong, out of date or contain data that should not be shared.'],
    ]],
    ['id' => 'faq', 'title' => 'Questions people ask', 'body' => [
      ['ul' => ['I cannot see a vessel or project. — Vessels and projects are linked to your company by Golden A/S; ask your project manager to link the missing one.', 'Why is there a weather badge next to the clock? — The clock strip shows the time in Greece and at the yards; click a city for sunrise / sunset and today\'s forecast.', 'I approved a document by mistake. — The decision is final in the portal; comment on the document and Golden A/S will submit a new revision if needed.', 'Where is the file I downloaded last week? — Files of Golden A/S-issued documents carry their document number in the file name (e.g. 20261009IB0949_R1.pdf); search your downloads for the number.', 'Can several people in my company use the portal? — Yes; each person has an own account and sees the same vessels. Ask Golden A/S for additional accounts.', 'Is the portal available in my language? — English, Greek, Italian, Ukrainian and Bulgarian; change it in the top bar. Reports are issued in English.']],
    ]],
  ],
];
