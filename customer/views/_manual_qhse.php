<?php
/** User manual — QHSE edition: the registers of ISO 9001, ISO 14001, ISO 45001 and ISO 22301, and the calibration of measuring equipment.   GAS-CP-R97-086
 *  Every change to the menu group QHSE is described here, not in the Staff or Project Manager handbooks. */
return [
  'title' => 'Customer Portal — QHSE Handbook',
  'lede'  => 'How the quality department keeps the four management systems the company is certified for — ISO 9001, ISO 14001, ISO 45001 and ISO 22301 — in the portal: the common registers, what each standard adds, the calibration of measuring equipment and the validity of its certificates, training kept in the personnel records, and who has access.',
  'sections' => [
    ['id' => 'where', 'title' => 'Where things are and who sees them', 'body' => [
      'The menu group QHSE holds two pages. Documents (library) is for every member of staff: the approved documents of the systems, to read and download. Management systems (ISO) is for the people of QHSE; at its top are five tabs: Integrated system, ISO 9001, ISO 14001, ISO 45001 and ISO 22301. Under the chosen tab stand its overview and its registers.',
      ['ul' => [
        'Who works in it: management and administrators always; everybody whose staff role or discipline is ticked under Access by position; and the single people ticked in the QHSE team.',
        'Access by position is a card on the first tab that only the CEO and the General Manager see and save. Until it is saved the portal ticks the roles and disciplines named QHSE, HSE or Quality, and the card says so.',
        'The QHSE team is chosen by management and administrators on the same tab (in v1.163.0 the Save buttons of the team and of Access by position did nothing; corrected in v1.163.1). Only management and administrators delete records.',
      ]],
      'Filters — type to pick from the list (v1.195.3): a drop-down became a type-to-search list only when it had more than eight entries, so a short filter such as Vessel on the purchase orders opened as a plain list. Now, in every filter bar of the portal, a drop-down with four or more entries opens with a search box: click it, type a few letters, press Enter or click the entry. The Vessel and Supplier filters of Purchase orders and of the Pipeline board are always searchable, whatever their length. Fixed choices of two or three entries (for example Purpose) stay plain lists.',
      'IT & Cybersecurity for the QHSE role (v1.167.0): the same account — staff role named QHSE, HSE or Quality — has the menu entries ISO 27001 and Marine cyber safety (E26 / E27) and works in their registers like a member of the ISMS team.',
      'Which standard is selected, and the evaluation of a new supplier (v1.189.0): the upper row of the QHSE page — Integrated system, ISO 9001, ISO 14001, ISO 45001, ISO 22301 — now marks the standard you are in, like the row of registers below it (before, only "Integrated system" was ever marked). New supplier: when a supplier is added to the supplier list — by Procurement in the portal, or by an import or the ERP — the portal puts it in ISO 9001 › Approved suppliers as "To evaluate" and plans its initial evaluation (8.4.1) under Supplier evaluations, due in 14 days; the QHSE team is notified (the administrators if no team is set). Open the evaluation, give the six scores and the result and set it to Done: the supplier becomes approved, conditional or blocked as before. Suppliers that were in the list before this version are not touched — evaluate those from the register as you do now. Measuring equipment: a record of an instrument now shows who holds it when HR has given it to an employee.',
      'Quality — no financial data, and the Milestones and Safety log (v1.185.2): the financial data of the company was closed only for a staff role named QHSE, HSE or Quality, while the QHSE menu was also given to positions named QA or QC — so a "QA/QC" position could work in QHSE and still see amounts. Now one rule decides both: an account is Quality when its staff role or its discipline is named QHSE, HSE, EHS, Quality, QA or QC (also in Greek), or when the person is a member of the QHSE team. For such an account amounts are hidden everywhere and the financial pages are closed: pipeline, offers, system budgets, sales, collections, cash flow, cost control, payables, invoices and proformas, and the prices of the inventory. An administrator or a member of Management who is named in the QHSE team keeps the figures. Quality now has the page "Milestones & safety log" in the QHSE menu: it lists the running projects with their milestones, the ones not yet achieved and the last safety entry; open a project to add, change or remove milestones and safety entries (lost-time incidents, near misses, observations) — the same page project managers use, which feeds the weekly and monthly reports. To check a person: Administration › Users shows the staff role and discipline; QHSE › Overview shows the team.',
      'No financial data for the QHSE role: an account whose staff role is named QHSE, HSE or Quality has no access to the financial data of the company. Amounts are hidden wherever the portal shows them, and the pages Sales pipeline, Offers, System Budgets, Collections, Accounting, Proforma invoices, Payables, Cash flow, Cost control and the financial reports are closed — whatever is ticked on the account, and for an administrator account too.',
      ['tip' => 'Nothing that is filed in this group is sent to the assistant of the portal.'],
    ]],
    ['id' => 'record', 'title' => 'A record, in every register', 'body' => [
      'Every record is numbered automatically — it opens with its number filled in (QD-001, QN-004, EQ-012 …) and you may type another one as long as it is not used in the register. Besides the number it has a title, a status, an owner, a due date or a next review where the register uses them, the fields of its register, files and a history.',
      'Save the record first; then attach the evidence (PDF, Office, images, e-mail, zip, up to 25 MB each) with a note of what it is. The history keeps who created the record, which fields changed, every status step and every file attached or removed.',
      'Lists are filtered by word, status and owner, by Open or Overdue, and on the first tab by the standard. Open means that the record still needs work; overdue that its due date has passed or its review date has come.',
      'Reminders: the owner is notified 14 days before a due or review date (30 days before a calibration certificate expires); a record without an owner goes to the QHSE team.',
      'Import from Excel: on the list of a register press Import template, fill one record per row on the sheet Records (the second sheet lists the allowed values and the rules) and upload it with Import Excel. The title is required; an empty code gets the automatic number; a code or a title that is already in the register is skipped — nothing is overwritten; files are attached afterwards. Registers that hang under another record (calibrations, evaluations) and the approved suppliers are not imported this way.',
      'Excel: the button with the name of the tab gives the registers of that tab; All standards (Excel) gives one workbook with a summary and a sheet for every register — the file for the auditor.',
    ]],
    ['id' => 'ims', 'title' => 'Integrated system — the registers common to the four standards', 'body' => [
      'What all four standards ask for is kept once. Each record carries the field Standard — All four, or the one it serves — and the list can be filtered by it.',
      ['ul' => [
        'Documents (7.5) — manual, policies, procedures, work instructions, forms, records and plans, with version, approval and next review. Attach the approved file.',
        'Risks & opportunities (6.1) — per process: risk or opportunity, likelihood and impact from 1 to 5, the treatment and its plan, and what remains. The score is likelihood × impact: 15 and more high, 8 to 14 medium, below 8 low.',
        'Objectives (6.2) — measure, target, date and result.',
        'Audits & reviews (9.2 · 9.3) — internal and external audits, management reviews, supplier audits, inspections and emergency drills, with scope, auditor, result and number of findings.',
        'Nonconformities (10.2) — source, description, root cause, correction, corrective action, check of effectiveness and closing date; can be linked to a vessel, a project and a sub-project.',
        'Legal & other requirements (6.1.3 · 9.1.2) — the source, the obligation and how it is met, with its next review.',
        'Training & competence (7.2 · 7.3) — see the section on training.',
        'Interested parties (4.1 · 4.2) — customers, authorities, classification societies, suppliers, employees, owners, community: what they need and how it is met.',
      ]],
      'The overview of this tab also shows the four standards with their records, open and overdue.',
    ]],
    ['id' => 'q9001', 'title' => 'ISO 9001 — complaints and measuring equipment', 'body' => [
      'Customer complaints & feedback (9.1.2 · 8.2.1): complaint, feedback or praise; the customer, the date received, the vessel and project it concerns, the description, root cause, correction and action. Status Open → Action under way → To verify → Closed.',
      'Measuring equipment (7.1.5) and Calibrations (7.1.5.2) are described in the next section; Approved suppliers (8.4) in the one after it.',
    ]],
    ['id' => 'calibration', 'title' => 'Calibration of measuring equipment and validity of certificates', 'body' => [
      'Every instrument that needs calibration is a record of Measuring equipment: kind of instrument, maker and model, serial number, range and accuracy, where it is kept, its holder (the owner of the record), whether calibration is required and the calibration interval in months.',
      ['ol' => [
        'Add the instrument (+ New instrument on the ISO 9001 overview, or New record in Measuring equipment).',
        'On the instrument press + New calibration. The calibration is numbered under the instrument (EQ-001-C01). Give the planned date and the laboratory; status Planned.',
        'When the instrument leaves, set the calibration to At the laboratory.',
        'When it returns: calibration date, certificate number, result (Within tolerance · Adjusted, within tolerance · Limited use · Out of tolerance), the date the certificate is valid until, the reference standard and the uncertainty. Attach the certificate and set the calibration to Done.',
      ]],
      'Done needs the date, the certificate number and the result. A result Out of tolerance makes the calibration Failed.',
      'What the instrument shows afterwards: its last calibration, the certificate number, the laboratory and the result, and the date its certificate is valid until — the date written on the certificate, or the calibration date plus the interval of the instrument when no date was given. These are set by the calibrations, not typed on the instrument. A failed calibration takes the instrument Out of service; the next good calibration puts it back In use.',
      'The state of the calibration is shown on the list and on the ISO 9001 overview: valid · due soon (within 30 days) · expired · never calibrated · out of service · not required. The overview counts the instruments with a valid calibration and lists those to arrange. The holder is reminded 30 days before the certificate expires.',
      ['tip' => 'An instrument that is expired, never calibrated or out of service must not be used for a measurement that goes into a test record or a report.'],
    ]],
    ['id' => 'suppliers', 'title' => 'Approved suppliers — evaluation, and what procurement sees', 'body' => [
      'Approved suppliers (8.4) holds the suppliers of Procurement › Suppliers that the quality department has evaluated. Choose the supplier from the list — it is in the register once and its name becomes the title — and give the category (materials and parts, equipment, subcontractor, services, calibration laboratory, transport), what it supplies and whether it is critical. A new one starts as To evaluate.',
      ['ol' => [
        'On the supplier record press + New evaluation: initial (before first use), periodic, or after an incident.',
        'Score the six criteria from 1 (very weak) to 5 (very good): quality of the product or service; delivery on time and complete; documentation and certificates; response and after-sales support; keeps what was agreed; health, safety and environmental conduct.',
        'Write any conditions and attach the questionnaire, the certificates or the audit report.',
        'Set the evaluation to Done — all six scores are needed.',
      ]],
      'The rating is the sum in percent and the result is proposed from it — 70 % and more Approved, 50 to 69 % Approved with conditions, below 50 % Not approved — another result can be chosen. The latest finished evaluation gives the supplier its rating and approval and dates the next evaluation: 12 months for a critical supplier, otherwise 24; the owner is reminded 14 days before.',
      'What procurement sees: on Procurement › Suppliers a column Approval and, on the supplier, the card Supplier approval (ISO 9001) with the rating and the next evaluation; in a new purchase order the supplier list is grouped — Approved suppliers first, then Not evaluated yet, then Not approved. Approved supplier list (Excel) is on the register and in Settings › Procurement.',
      ['ul' => [
        'A supplier that is Not approved can never receive a purchase order — neither a new one nor a draft that is sent, including one made by awarding a request for quotation.',
        'Settings › Procurement › Approved suppliers › Purchase orders only to approved suppliers: with the tick, a purchase order goes only to a supplier that is Approved or Approved with conditions; without it, a supplier that has not been evaluated yet can still be used.',
        'Requests for quotation are not limited — a quotation may be asked from a supplier before it is evaluated.',
      ]],
      ['tip' => 'Switch the rule on when the suppliers in use have been evaluated — before that, procurement could not order from them.'],
    ]],
    ['id' => 'library', 'title' => 'Documents (library) — for all staff', 'body' => [
      'QHSE › Documents (library) shows every document of the register Documents whose status is Approved, grouped by kind — manual, policies, procedures, work instructions, forms, records, plans — with its standard, version, approval date and the files attached to it. It can be searched by word and filtered by standard and kind. Everybody of the staff can read and download; nobody can change anything there.',
      'A document appears as soon as its status is set to Approved and disappears when it becomes Obsolete or goes back to Draft or In review. The files of a document that is not approved cannot be opened from the library.',
      ['tip' => 'A printed or saved copy is not controlled — the valid version is the one in the library.'],
    ]],
    ['id' => 'q14001', 'title' => 'ISO 14001 — environment', 'body' => [
      'Environmental aspects (6.1.2): the activity, its aspect and its impact, the condition (normal, abnormal, emergency), the significance as likelihood × severity from 1 to 5, the controls, and the residual score, with a next review.',
      'Waste records (8.1 · 9.1): kind of waste, EWC code, quantity and unit, the date it was handed over, the carrier or receiver and the consignment or recycling document — attach it. Open while stored, Closed when handed over; can be linked to a vessel and a project.',
    ]],
    ['id' => 'q45001', 'title' => 'ISO 45001 — health and safety', 'body' => [
      'Hazards & risk assessment (6.1.2): the activity, the hazard, who is exposed, the risk as likelihood × severity, the controls and the protective equipment, and the residual risk; can be linked to a vessel, a project and a sub-project for a site-specific assessment.',
      'Incidents & near misses (10.2): near miss, unsafe condition, first aid, medical treatment, lost-time injury, damage to property or environmental; date, location, persons involved, what happened, the immediate action, root cause, corrective action, lost days and who was notified. Status Reported → Being assessed → Action under way → Closed.',
      ['tip' => 'Write only what the investigation needs about a person. Medical details belong with HR, not in this record.'],
    ]],
    ['id' => 'q22301', 'title' => 'ISO 22301 — business continuity', 'body' => [
      'Business impact analysis (8.2.2): per activity, the impact of an interruption, the longest tolerable interruption (MTPD), the recovery time objective (RTO) and recovery point objective (RPO), what the activity depends on, and its criticality.',
      'Continuity plans (8.3 · 8.4): the scenario, the strategy and plan, version, approval and the date it was last tested, with a next review.',
      'Exercises & tests (8.5): scenario, date, scope, result, lessons learned and number of findings.',
    ]],
    ['id' => 'training', 'title' => 'Training kept in the personnel records', 'body' => [
      ['ol' => [
        'Create the record in Training & competence: title, planned date, audience, trainer and the standard.',
        'On the record, in the card HR › Training and personnel records, press Create in HR › Training — or link a training that HR has already published.',
        'After the session tick who attended and press Save attendance; tick Set the training to Done to close the record here and the training in HR together.',
      ]],
      'The attendance is written to HR › Training and shows on the Training tab of each personnel record; HR adds certificates and scores there.',
    ]],
    ['id' => 'routine', 'title' => 'The routine through the year', 'body' => [
      ['ul' => [
        'Every week — Needs attention on each tab, and Calibration to arrange on the ISO 9001 tab.',
        'When something happens — a complaint, an incident or a finding is recorded the same day and followed to Closed with its cause and action.',
        'Every month — calibrations due in the next 30 days are planned with the laboratory.',
        'Every year — the audit programme and the management review, the review of risks, aspects and hazards, the legal requirements, the objectives, the continuity exercise and the drills.',
        'Before a certification audit — All standards (Excel), and check that every approved document and every calibration has its file attached.',
      ]],
    ]],
  ],
];
