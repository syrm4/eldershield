<?php
// database/seed.php — Run ONCE in browser to create demo users + incidents
// DELETE THIS FILE after running in production!
require_once __DIR__ . '/../config/db.php';
if (defined('APP_TIMEZONE')) date_default_timezone_set(APP_TIMEZONE);

$db = getDB();

// ── Already-seeded guard ──────────────────────────────────────
// Incidents have no natural unique key, so running this twice
// would silently duplicate all demo data with no easy way to fix it.
// Add ?force=1 to the URL to bypass this check and re-seed from scratch.
$existingIncidents = (int)$db->query('SELECT COUNT(*) FROM incidents')->fetchColumn();
if ($existingIncidents > 0 && empty($_GET['force'])) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>ElderShield — Already Seeded</title>
        <style>
            body { font-family: sans-serif; max-width: 540px; margin: 60px auto; padding: 0 20px; color: #333; }
            h2   { color: #856404; }
            .box { background: #fff3cd; border: 1px solid #ffc107; border-radius: 6px; padding: 16px 20px; margin: 16px 0; }
            .tip { background: #d1ecf1; border: 1px solid #bee5eb; border-radius: 6px; padding: 12px 16px; margin: 16px 0; }
            code { background: #f4f4f4; padding: 2px 5px; border-radius: 3px; font-size: .9em; }
        </style>
    </head>
    <body>
        <h2>⚠️ Database Already Seeded</h2>
        <div class="box">
            <p>Found <strong><?= $existingIncidents ?> existing incident(s)</strong> in the database.</p>
            <p>Running seed.php again would create duplicate incidents. No changes have been made.</p>
        </div>
        <div class="tip">
            <strong>To re-seed from scratch:</strong><br>
            Drop and reimport <code>eldershield.sql</code> in phpMyAdmin, then visit:<br><br>
            <code><?= htmlspecialchars('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . '?force=1') ?></code>
        </div>
        <p>Remember to <strong>delete this file</strong> when you are done!</p>
    </body>
    </html>
    <?php
    exit;
}

// ── Force mode notice ─────────────────────────────────────────
if (!empty($_GET['force'])) {
    echo "<p style='background:#f8d7da;border:1px solid #f5c6cb;border-radius:6px;padding:10px 14px;font-family:sans-serif'>
        ⚠️ <strong>Force mode active.</strong> All existing incidents will be duplicated. 
        Truncate the incidents and analysis tables first if you want a clean re-seed.
    </p>";
}

// ============================================================
// USERS  [full_name, email, password, role, plan]
// ============================================================
$users = [
    ['Admin User',      'admin@eldershield.com',  'password123', 'admin',     'premium'],
    ['Dorothy Johnson', 'dorothy@example.com',    'password123', 'elder',     'free'],
    ['Sarah Johnson',   'sarah@example.com',      'password123', 'caregiver', 'free'],
    ['Bob Smith',       'bsmith@eldershield.com', 'mysecret',    'admin',     'premium'],
    ['Patricia Jones',  'pjones@example.com',     'acrobat',     'elder',     'free'],
    ['Harold Turner',   'hturner@example.com',    'password123', 'elder',     'free'],
    ['Evelyn Martinez', 'emartinez@example.com',  'password123', 'elder',     'free'],
    ['Walter Nguyen',   'wnguyen@example.com',    'password123', 'elder',     'free'],
    ['Betty Kowalski',  'bkowalski@example.com',  'password123', 'elder',     'free'],
    ['Raymond Chen',    'rchen@example.com',      'password123', 'elder',     'free'],
    ['Gloria Okafor',   'gokafor@example.com',    'password123', 'elder',     'free'],
    ['Franklin Patel',  'fpatel@example.com',     'password123', 'elder',     'free'],
    ['Mildred Haynes',  'mhaynes@example.com',    'password123', 'elder',     'free'],
    ['Chester Bloom',   'cbloom@example.com',     'password123', 'elder',     'free'],
    ['Michael Johnson', 'mjohnson@example.com',   'password123', 'caregiver', 'premium'],
    ['Angela Rivera',   'arivera@example.com',    'password123', 'caregiver', 'premium'],
    ['Tom Bradley',     'tbradley@example.com',   'password123', 'caregiver', 'free'],
    ['Linda Park',      'lpark@example.com',      'password123', 'caregiver', 'free'],
    ['James Osei',      'josei@example.com',      'password123', 'caregiver', 'free'],
];

echo "<h2>👤 Creating Users</h2>";
foreach ($users as [$name, $email, $pass, $role, $plan]) {
    $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
    $db->prepare(
        'INSERT INTO users (full_name, email, password_hash, role, plan)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), plan=VALUES(plan)'
    )->execute([$name, $email, $hash, $role, $plan]);
    echo "✅ {$email} ({$role} / {$plan})<br>";
}

function uid(PDO $db, string $email): ?int {
    $s = $db->prepare('SELECT user_id FROM users WHERE email=?');
    $s->execute([$email]);
    $v = $s->fetchColumn();
    return $v ? (int)$v : null;
}
function addLink(PDO $db, string $cg, string $elder, string $status = 'active'): void {
    $cgId = uid($db, $cg); $eid = uid($db, $elder);
    if (!$cgId || !$eid) { echo "⚠️ Link failed {$cg}→{$elder}<br>"; return; }
    $db->prepare('INSERT IGNORE INTO account_links (elder_user_id, caregiver_user_id, status) VALUES (?,?,?)')
       ->execute([$eid, $cgId, $status]);
    echo "🔗 {$cg} → {$elder} ({$status})<br>";
}

// ============================================================
// ACCOUNT LINKS
// ============================================================
echo "<h2>🔗 Creating Links</h2>";
addLink($db, 'sarah@example.com',    'dorothy@example.com');
addLink($db, 'sarah@example.com',    'fpatel@example.com');
addLink($db, 'mjohnson@example.com', 'dorothy@example.com');
addLink($db, 'mjohnson@example.com', 'hturner@example.com');
addLink($db, 'mjohnson@example.com', 'emartinez@example.com');
addLink($db, 'mjohnson@example.com', 'wnguyen@example.com');
addLink($db, 'arivera@example.com',  'bkowalski@example.com');
addLink($db, 'arivera@example.com',  'rchen@example.com');
addLink($db, 'arivera@example.com',  'gokafor@example.com');
addLink($db, 'tbradley@example.com', 'hturner@example.com');
addLink($db, 'tbradley@example.com', 'rchen@example.com');
addLink($db, 'lpark@example.com',    'emartinez@example.com');
addLink($db, 'lpark@example.com',    'pjones@example.com');
addLink($db, 'josei@example.com',    'bkowalski@example.com');

// ============================================================
// INCIDENTS + ANALYSIS
// Each entry: [elder_email, daysAgo, content, status, probability, category, tactics, explanation, action]
// daysAgo can be a decimal (e.g. 0.3 = ~7 hours ago) for recent entries
// ============================================================
echo "<h2>📋 Creating Incidents &amp; Analysis</h2>";

$incidents = [
    // ── Dorothy — varied history ─────────────────────────────
    ['dorothy@example.com', 0.1,
     'I got a call from someone saying they were from Medicare. They said my benefits were being cancelled unless I confirmed my Social Security number and bank account details over the phone. They were very insistent and said I only had 24 hours.',
     'analyzed', 92, 'impersonation',
     ['urgency','authority_impersonation','personal_info_request'],
     'This is a Medicare impersonation scam. Real Medicare will never call you to ask for your Social Security number or bank information.',
     'Do not call back. Do not provide any personal information. Report to 1-800-MEDICARE and your local police.'],

    ['dorothy@example.com', 1,
     'An email said I won a $500 Amazon gift card. It said I needed to click a link and pay a small $4.99 processing fee to claim my prize.',
     'analyzed', 85, 'lottery_prize',
     ['false_reward','small_fee_request'],
     'This is a prize scam. You did not win anything. The small fee is a trick to get your credit card details.',
     'Delete the email. Do not click any links. Do not pay the processing fee.'],

    ['dorothy@example.com', 4,
     'Someone texted me saying my bank account was compromised and I need to verify my identity by clicking this link immediately.',
     'analyzed', 88, 'phishing',
     ['urgency','fake_security_alert','impersonation'],
     'This is a phishing text (smishing). Your bank will never ask you to verify your identity by clicking a link in a text message.',
     'Do not click the link. Call your bank directly using the number on the back of your card to verify your account is safe.'],

    ['dorothy@example.com', 12,
     'I received a letter saying I qualify for a free medical alert device. I just need to confirm my Medicare number and date of birth to activate it.',
     'analyzed', 78, 'impersonation',
     ['authority_impersonation','personal_info_request','false_benefit'],
     'This is likely a Medicare fraud scheme. Scammers use free device offers to steal Medicare numbers which can be used for billing fraud.',
     'Do not respond. Shred the letter. Report to 1-800-HHS-TIPS if you suspect Medicare fraud.'],

    ['dorothy@example.com', 22,
     'My grandson called saying he was in a car accident in Mexico and needed $3,000 wired immediately. He said not to tell his parents.',
     'analyzed', 96, 'grandparent_scam',
     ['urgency','family_impersonation','secrecy','wire_transfer_request'],
     'This is a grandparent scam. Criminals pretend to be a grandchild in distress. The request for secrecy is a major warning sign.',
     'Hang up immediately. Call your grandson directly on his known number to verify he is safe. Never wire money to someone you cannot verify.'],

    // ── Patricia Jones ────────────────────────────────────────
    ['pjones@example.com', 0.5,
     'A pop-up appeared on my computer saying it was infected with viruses and I needed to call Microsoft support at a 1-800 number immediately or my files would be deleted.',
     'analyzed', 90, 'tech_support',
     ['urgency','fear_based_language','fake_alert','authority_impersonation'],
     'This is a fake Microsoft tech support scam. Microsoft never sends pop-ups asking you to call them.',
     'Close the browser window or restart your computer. Do not call the number. Run your normal antivirus scan.'],

    ['pjones@example.com', 3,
     'I received an email from what looks like my bank saying there was suspicious activity and I need to log in to confirm my transactions.',
     'analyzed', 82, 'phishing',
     ['urgency','fake_security_alert','credential_harvesting'],
     'This is a phishing email designed to steal your banking login. The email is faked to look like your bank.',
     'Do not click any links in the email. Go directly to your bank website by typing the address. Call your bank to confirm.'],

    ['pjones@example.com', 15,
     'Someone on a dating website has been very friendly for 3 weeks and now says they need help with an emergency and asked me to buy $500 in iTunes gift cards.',
     'analyzed', 97, 'romance_scam',
     ['emotional_manipulation','gift_card_request','isolation','manufactured_crisis'],
     'This is a romance scam. The person has built a fake relationship to gain your trust before asking for money. Gift card requests are always a scam.',
     'Stop all contact with this person. Do not send any money or gift cards. Report to the FTC at reportfraud.ftc.gov.'],

    // ── Harold Turner ─────────────────────────────────────────
    ['hturner@example.com', 2,
     'Got a call from IRS saying I owe back taxes and if I dont pay $1,200 in Google Play cards within the hour they will send police to arrest me.',
     'analyzed', 98, 'impersonation',
     ['urgency','authority_impersonation','threat_of_arrest','gift_card_request'],
     'This is an IRS impersonation scam. The real IRS will never call demanding gift card payment or threatening immediate arrest.',
     'Hang up. The IRS contacts taxpayers by mail first. If worried, call the IRS directly at 1-800-829-1040 to check your account.'],

    ['hturner@example.com', 8,
     'A company called saying I signed up for a computer protection service two years ago and they are now billing me $399 automatically. They offered a refund if I give them remote access.',
     'analyzed', 91, 'tech_support',
     ['false_billing','remote_access_request','refund_scam'],
     'This is a refund scam. Allowing remote access will let criminals steal money and personal information from your computer.',
     'Do not allow remote access. Hang up. Check your bank statements for any unauthorized charges and call your bank if you see any.'],

    ['hturner@example.com', 18,
     'I met someone online who says they are a doctor working abroad. They want to come visit but their luggage got stuck in customs and they need $800 to release it.',
     'analyzed', 95, 'romance_scam',
     ['emotional_manipulation','manufactured_crisis','money_transfer_request'],
     'This is a romance scam with a common "stuck in customs" story. Real people do not ask strangers for money to release luggage.',
     'Do not send money. Block and report this person on the platform where you met them.'],

    // ── Evelyn Martinez ───────────────────────────────────────
    ['emartinez@example.com', 0.8,
     'I got an email saying a package could not be delivered and I need to click a link to reschedule and pay a $1.99 redelivery fee.',
     'analyzed', 74, 'phishing',
     ['fake_delivery_notice','small_fee_request','credential_harvesting'],
     'This is a fake package delivery phishing scam. The small fee is used to capture your credit card information.',
     'Do not click the link or pay anything. Check the actual shipping carrier website directly using the tracking number from your original order.'],

    ['emartinez@example.com', 6,
     'Someone called saying they are from Social Security and my number has been suspended due to suspicious activity. They said I need to confirm my information.',
     'analyzed', 93, 'impersonation',
     ['authority_impersonation','urgency','personal_info_request','threat'],
     'This is a Social Security Administration impersonation scam. The SSA does not suspend Social Security numbers.',
     'Hang up. Report the call to the SSA Inspector General at 1-800-269-0271 or oig.ssa.gov.'],

    ['emartinez@example.com', 25,
     'I received a check in the mail for $3,400 saying I won a sweepstakes. I need to deposit it and send back $500 to cover taxes before I can keep the rest.',
     'analyzed', 99, 'lottery_prize',
     ['fake_check','overpayment_scam','upfront_fee'],
     'This is a fake check overpayment scam. The check will bounce after you send your real money, and you will lose the $500.',
     'Do not deposit the check. Do not send any money. Shred the check and the letter. Report to the FTC.'],

    // ── Walter Nguyen ─────────────────────────────────────────
    ['wnguyen@example.com', 1,
     'An investment advisor found me on Facebook and says he can turn $5,000 into $50,000 in 30 days using a special crypto trading strategy. He showed me screenshots of other peoples profits.',
     'analyzed', 94, 'investment_fraud',
     ['false_returns','urgency','social_proof_manipulation','cryptocurrency'],
     'This is an investment fraud/pig butchering scam. Guaranteed high returns in crypto are always fraudulent. Screenshots of profits are easily faked.',
     'Do not invest any money. Block this person. Report to the SEC at investor.gov and the FTC.'],

    ['wnguyen@example.com', 9,
     'I received a call about refinancing my mortgage at a much lower rate. They need an upfront processing fee of $450 to lock in the rate.',
     'analyzed', 80, 'investment_fraud',
     ['false_savings','upfront_fee_request','urgency'],
     'Legitimate mortgage lenders do not charge upfront fees before processing a refinance application. This is likely a scam.',
     'Do not pay any upfront fees. Contact your current mortgage lender or a HUD-approved housing counselor at 1-800-569-4287.'],

    // ── Betty Kowalski ────────────────────────────────────────
    ['bkowalski@example.com', 3,
     'My computer screen locked up and showed a blue screen with a Microsoft logo saying my computer was hacked. A phone number was displayed and I was told not to turn off my computer.',
     'analyzed', 89, 'tech_support',
     ['fear_based_language','fake_alert','urgency','authority_impersonation'],
     'This is a tech support scam using a fake blue screen. Real Windows errors do not include phone numbers to call.',
     'Force restart your computer by holding the power button. Do not call the number. Run a virus scan after restarting.'],

    ['bkowalski@example.com', 14,
     'I got a call from someone saying they are from my electric company and my service will be cut off in 2 hours unless I pay an overdue bill immediately with a prepaid Visa card.',
     'analyzed', 87, 'impersonation',
     ['urgency','utility_impersonation','prepaid_card_request','threat'],
     'Utility companies do not demand prepaid card payments or give 2-hour shutoff notices by phone.',
     'Hang up. Call your electric company using the number on your bill to check your actual account balance.'],

    // ── Raymond Chen ─────────────────────────────────────────
    ['rchen@example.com', 0.3,
     'A friendly person online wants to send me a check for $8,000 if I help them transfer money through my account. They say I can keep $1,000 for myself.',
     'analyzed', 97, 'investment_fraud',
     ['money_mule_recruitment','overpayment_scam','false_reward'],
     'This is a money mule scam. Using your account to transfer money for strangers is illegal and the check will be fake.',
     'Decline immediately. Do not provide any bank account information. Using your account this way could result in criminal charges.'],

    ['rchen@example.com', 7,
     'Someone texted offering a job working from home reviewing products for Amazon. I just need to pay $99 for a starter kit to get access to the jobs.',
     'analyzed', 76, 'investment_fraud',
     ['work_from_home_fraud','upfront_fee','false_opportunity'],
     'Legitimate employers do not charge fees to start work. This is a job scam.',
     'Do not pay any fees. Report to the FTC at reportfraud.ftc.gov.'],

    // ── Gloria Okafor ─────────────────────────────────────────
    ['gokafor@example.com', 5,
     'I got an email saying my Netflix account will be suspended. I need to update my payment information by clicking the link within 24 hours.',
     'analyzed', 71, 'phishing',
     ['urgency','fake_billing','credential_harvesting'],
     'This is a Netflix phishing email. Check the sender address — it will not be from a real Netflix domain.',
     'Do not click any links. Log into Netflix directly at netflix.com to check your actual account status.'],

    ['gokafor@example.com', 20,
     'A man called saying he is from the government and I qualify for a $9,000 federal grant that I don\'t have to pay back. I just need to pay a $200 processing fee.',
     'analyzed', 92, 'impersonation',
     ['false_benefit','upfront_fee','authority_impersonation'],
     'The government does not call people to offer grants, and never requires a fee to receive one.',
     'Hang up. There is no grant. Do not pay anything. Report to the FTC.'],

    // ── Franklin Patel ────────────────────────────────────────
    ['fpatel@example.com', 2,
     'I received an email that looks like it is from PayPal saying I received $2,500 and need to pay $300 in shipping to receive the money.',
     'analyzed', 88, 'phishing',
     ['overpayment_scam','fake_payment_notice','upfront_fee'],
     'PayPal does not ask you to pay money to receive a payment. This is a fake PayPal email.',
     'Do not pay anything. Check your real PayPal account by going directly to paypal.com. Delete the email.'],

    ['fpatel@example.com', 11,
     'Someone is selling a used car online for a very low price and says they are military deployed overseas. They want a deposit sent by wire transfer to hold the car.',
     'analyzed', 83, 'investment_fraud',
     ['false_listing','wire_transfer_request','military_impersonation'],
     'This is an online vehicle scam. Never wire money for a car you have not inspected in person.',
     'Do not send any money by wire transfer or gift card. Only buy vehicles you can inspect in person from verified sellers.'],

    // ── Mildred Haynes (no caregiver) ─────────────────────────
    ['mhaynes@example.com', 4,
     'I received a call from someone claiming to be my bank fraud department. They said my debit card was used in another state and asked me to verify my card number and PIN to freeze the card.',
     'analyzed', 91, 'phishing',
     ['authority_impersonation','urgency','credential_harvesting'],
     'Your bank\'s fraud department will never ask for your PIN. This is a scam designed to steal your card details.',
     'Hang up. Call your bank using the number on the back of your card to check on any real fraud alerts.'],

    // ── Chester Bloom (no caregiver) ──────────────────────────
    ['cbloom@example.com', 6,
     'A call from publisher clearing house saying I won $50,000 but need to pay $1,500 in taxes upfront before they can send the prize money.',
     'analyzed', 95, 'lottery_prize',
     ['false_prize','upfront_tax_fee','urgency'],
     'Legitimate prize organizations never require you to pay taxes upfront before receiving winnings. This is always a scam.',
     'Do not pay anything. You cannot win a contest you did not enter. Hang up and report to the FTC.'],

    // ── Low-risk / legitimate checks ─────────────────────────
    ['dorothy@example.com', 16,
     'I got an email from my doctor\'s office reminding me about my appointment next Tuesday and asking me to confirm via their patient portal.',
     'analyzed', 8, 'not_a_scam',
     [],
     'This appears to be a legitimate appointment reminder from a medical office. Patient portals are normal for healthcare communication.',
     'This looks safe. If you want to be sure, call your doctor\'s office directly to confirm the appointment.'],

    ['hturner@example.com', 27,
     'My bank sent me a notice in the mail about a change to their terms of service and provided a phone number to call with questions.',
     'analyzed', 12, 'not_a_scam',
     [],
     'Written mail from your bank about terms changes is normal. The low-pressure nature and mail delivery are good signs.',
     'This appears legitimate. If you have questions, call the number on the back of your bank card rather than the one in the letter to be extra safe.'],
];

$incStmt = $db->prepare(
    'INSERT INTO incidents (user_id, content, image_path, status, submitted_at)
     VALUES (?, ?, NULL, ?, ?)'
);
$anaStmt = $db->prepare(
    'INSERT INTO analysis
        (incident_id, scam_probability, scam_category, manipulation_tactics,
         explanation_simple, recommended_action)
     VALUES (?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
        scam_probability=VALUES(scam_probability),
        scam_category=VALUES(scam_category),
        manipulation_tactics=VALUES(manipulation_tactics),
        explanation_simple=VALUES(explanation_simple),
        recommended_action=VALUES(recommended_action)'
);

$count = 0;
foreach ($incidents as [$email, $daysAgo, $content, $status, $prob, $cat, $tactics, $expl, $action]) {
    $userId = uid($db, $email);
    if (!$userId) { echo "⚠️ User not found: {$email}<br>"; continue; }

    $secsAgo = (int)($daysAgo * 86400);
    $ts      = date('Y-m-d H:i:s', time() - $secsAgo);

    $incStmt->execute([$userId, $content, $status, $ts]);
    $incId = (int)$db->lastInsertId();

    if ($status === 'analyzed' || $status === 'reviewed') {
        $anaStmt->execute([
            $incId, $prob, $cat,
            json_encode($tactics), $expl, $action
        ]);
    }
    $count++;
    echo "📋 Incident #{$incId} — {$email} ({$cat}, {$prob}%) — " . date('M j', time() - $secsAgo) . "<br>";
}

echo "<br><strong>✅ {$count} incidents created.</strong><br>";

// ============================================================
// SUMMARY
// ============================================================
echo "<br><h2>📊 Summary</h2>";
echo "<table border='1' cellpadding='6' cellspacing='0' style='border-collapse:collapse;font-family:monospace;font-size:.85em'>";
echo "<tr style='background:#ddd'><th>Email</th><th>Role</th><th>Plan</th><th>Password</th><th>Notes</th></tr>";
$summary = [
    ['admin@eldershield.com',  'admin',     'premium', 'password123', 'Original admin'],
    ['bsmith@eldershield.com', 'admin',     'premium', 'mysecret',    'Test admin'],
    ['dorothy@example.com',    'elder',     'free',    'password123', '5 incidents; linked to sarah, mjohnson'],
    ['pjones@example.com',     'elder',     'free',    'acrobat',     '2 incidents; linked to lpark'],
    ['hturner@example.com',    'elder',     'free',    'password123', '3 incidents; linked to mjohnson, tbradley'],
    ['emartinez@example.com',  'elder',     'free',    'password123', '3 incidents; linked to mjohnson, lpark'],
    ['wnguyen@example.com',    'elder',     'free',    'password123', '2 incidents; linked to mjohnson'],
    ['bkowalski@example.com',  'elder',     'free',    'password123', '2 incidents; linked to arivera, josei'],
    ['rchen@example.com',      'elder',     'free',    'password123', '2 incidents; linked to arivera, tbradley'],
    ['gokafor@example.com',    'elder',     'free',    'password123', '2 incidents; linked to arivera'],
    ['fpatel@example.com',     'elder',     'free',    'password123', '2 incidents; linked to sarah'],
    ['mhaynes@example.com',    'elder',     'free',    'password123', '1 incident; NO caregiver'],
    ['cbloom@example.com',     'elder',     'free',    'password123', '1 incident; NO caregiver'],
    ['sarah@example.com',      'caregiver', 'free',    'password123', '2 links (free limit)'],
    ['mjohnson@example.com',   'caregiver', 'premium', 'password123', '4 links (premium)'],
    ['arivera@example.com',    'caregiver', 'premium', 'password123', '3 links (premium)'],
    ['tbradley@example.com',   'caregiver', 'free',    'password123', '2 links (free limit)'],
    ['lpark@example.com',      'caregiver', 'free',    'password123', '2 links (free limit)'],
    ['josei@example.com',      'caregiver', 'free',    'password123', '1 link'],
];
foreach ($summary as [$email, $role, $plan, $pass, $notes]) {
    $bg = match($role) { 'admin' => '#fff3cd', 'caregiver' => '#d1ecf1', default => '#f8f9fa' };
    echo "<tr style='background:{$bg}'><td>{$email}</td><td>{$role}</td><td>{$plan}</td><td><code>{$pass}</code></td><td>{$notes}</td></tr>";
}
echo "</table>";
echo "<br><strong style='color:red'>⚠️ Delete this file now! (database/seed.php)</strong>";
