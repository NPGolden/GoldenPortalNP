# Golden A/S app for customers — Help (build 1.0.0, portal v1.79.0, GAS-CP-R68-001)

*Shown in the portal guide (Customer manual › "The Golden A/S app on your phone") and linked from More › Help in the app. EN; EL / IT follow with the pilot.*

## 1. What the app is for
Your Golden A/S customer portal on an iPhone or Android phone:
- **Accept service reports** (SF-GLD-014) with your signature — or send them back with a remark;
- **Approve documents and drawings** submitted for the Owner's approval (A / B / D with notes);
- **Decide on offers** — accept and sign, or reject with a note;
- **Follow and answer tickets** with photos and files; open new tickets;
- Read your vessels, project progress and the documents shared with you.

Everything you do in the app is the same record as in the web portal and is seen immediately by your Golden A/S project manager.

## 2. Getting started
1. Install **Golden A/S Portal** from the App Store / Google Play, or open goldensas.com/customer/mobile-customer in Safari / Chrome and choose *Add to Home Screen*.
2. Sign in with your portal e-mail and password. If two-factor authentication is on for your account (Account › Two-factor in the web portal), enter the authenticator code too.
3. Accept **biometric unlock** (Face ID / Touch ID / fingerprint) to open the app without typing your password; your password is never stored on the phone.

## 3. Home — Action Centre
Counters and the list of what waits for you: reports to accept, documents to approve, offers to decide, tickets waiting for your answer. Tap an item; filter by vessel; ↻ refreshes.

## 4. Accepting a service report
Open the report, read it to the last page (the *Sign report* button activates then), draw your signature, add a remark if you wish, tick the acceptance statement and confirm (authenticator code when 2FA is on). The report now carries your acceptance — your drawn signature printed in the acceptance box with name, date, time and network address; Golden A/S is notified. *Not accepted — send back* returns the report with your remark.

## 5. Approving documents and drawings
| Code | Meaning | Note |
|---|---|---|
| A | Approved | optional |
| B | Approved with comments — Golden A/S proceeds and incorporates them | required |
| D | Rejected — Golden A/S submits a new revision | required |
Your decision is final in the portal; the comment thread under the document stays open for remarks.

## 6. Offers
An offer sent for your decision shows total (ex-VAT), validity and the offer document. *Accept & sign* (signature + acceptance statement) or *Reject* with a note. Decisions on offers from the app require two-factor authentication on your account (web portal › Account › Two-factor); without it the app shows a notice instead of the buttons. Once the validity date has passed the offer cannot be accepted — reject with a note or open a ticket and Golden A/S issues a new revision.

## 7. Tickets
The conversation with Golden A/S per ticket (internal notes of Golden A/S are never shown). Reply with text, a photo or a file (≤ 20 MB; images are reduced on the phone). A ticket marked *Resolved* by Golden A/S can be closed by you — or re-opened if the problem returns. *New ticket* opens a request for one of your vessels with category and priority.

## 8. Account and security
More › Account: language, biometric unlock, your signed-in devices (*Revoke* a lost phone), sign out. Decisions ask for your authenticator code when 2FA is on. The session on a phone lasts 30 days of inactivity (7 days in a browser). Changing your portal password signs out all phones. The app reads what you already opened when offline; actions need a connection. No analytics, no tracking.

## 9. Problems?
*"Customer account required"* — the app is for customer accounts; Golden A/S staff use the Service Engineer or Golden PM app. *"Update required"* — update from the store. *"Device revoked"* — the phone was revoked or your password changed; sign in again. Anything else: support@goldenas.com.gr or a ticket.

---
*Staff notes: minimum version — Settings › Login history › Mobile apps › Customer app. Devices — Portal accounts › customer › ⎋ Revoke device. Signatures are sp_files rows (kind fsr_sign with the report, offer_sign with the offer; visibility external). Decisions carry decided_ip / decided_name as from the web; FSR events read "(mobile app)".*
