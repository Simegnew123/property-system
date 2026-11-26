<?php
/**
 * ============================================================
 *  i18n.php — English ⇄ Amharic (አማርኛ) translation layer
 * ------------------------------------------------------------
 *  Activated automatically because config/db.php requires it.
 *  Everything degrades gracefully to English when offline.
 *
 *  Features
 *   1. Session-based language toggle:   ?lang=en  |  ?lang=am
 *   2. Static UI dictionary translation of the whole rendered page
 *      (text nodes only — never touches <script>/<style>/attributes
 *       except placeholder / alt / aria-label).
 *   3. Floating language switcher injected on every page.
 *   4. tr() — machine translation of *dynamic* DB text (EN⇄AM)
 *      via an external API, cached in MySQL, with offline fallback.
 *
 *  NOTE: Personal names, invoice numbers, phone numbers, codes and
 *  any identifier are NEVER machine-translated (see tr()).
 * ============================================================
 */
declare(strict_types=1);

/* ---------- 1. Language selection (persisted in session) ---------- */
/* Master switch for machine translation of dynamic DB text.
   Set to false to disable all external API calls (UI dictionary still works). */
if (!defined('I18N_MACHINE')) {
    define('I18N_MACHINE', true);
}

if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = 'en';
}
if (isset($_GET['lang'])) {
    $l = strtolower((string) $_GET['lang']);
    if ($l === 'en' || $l === 'am') {
        $_SESSION['lang'] = $l;
    }
}
if (!function_exists('current_lang')) {
    function current_lang(): string
    {
        return $_SESSION['lang'] ?? 'en';
    }
}

/* ---------- 2. English → Amharic dictionary (static UI) ---------- */
if (!function_exists('i18n_am_map')) {
    function i18n_am_map(): array
    {
        static $m = null;
        if ($m !== null) {
            return $m;
        }
        $m = [
            // Branding / general
            'Property Management System' => 'የንብረት አስተዳደር ስርዓት',
            'Building & Apartment Property Management System' => 'የሕንፃ እና አፓርታማ ንብረት አስተዳደር ስርዓት',
            'Admin Panel' => 'የአስተዳዳሪ ፓነል',
            'Admin Login' => 'የአስተዳዳሪ መግቢያ',
            'Admin & Staff Login' => 'የአስተዳዳሪ እና ሠራተኛ መግቢያ',
            'Sign in to the management dashboard' => 'ወደ አስተዳደር ዳሽቦርድ ይግቡ',
            'Sign In' => 'ግባ',
            'Login' => 'መግቢያ',
            'Logout' => 'ውጣ',
            'Show' => 'አሳይ',
            'Hide' => 'ደብቅ',
            'Demo Credentials' => 'የሙከራ መረጃዎች',
            'for testing only' => 'ለሙከራ ብቻ',
            'Are you a tenant?' => 'ተከራይ ነዎት?',
            'Go to Tenant Portal' => 'ወደ ተከራይ ፖርታል ይሂዱ',
            'Tenant? ' => 'ተከራይ? ',
            'Manage buildings, units & tenants' => 'ሕንፃዎችን፣ ክፍሎችን እና ተከራዮችን ያስተዳድሩ',
            'Track rent payments in ETB' => 'የኪራይ ክፍያዎችን በብር ይከታተሉ',
            'Maintenance requests & reports' => 'የጥገና ጥያቄዎች እና ሪፖርቶች',
            'Dashboards & financial analytics' => 'ዳሽቦርዶች እና የፋይናንስ ትንታኔ',
            'Email Address' => 'የኢሜይል አድራሻ',
            'Password' => 'የይለፍ ቃል',
            'Enter your password' => 'የይለፍ ቃልዎን ያስገቡ',

            // Tenant login
            'Tenant Portal' => 'የተከራይ ፖርታል',
            'Enter the phone number and access code provided in your lease contract.' => 'በኪራይ ውልዎ የተሰጠውን የስልክ ቁጥር እና የመግቢያ ኮድ ያስገቡ።',
            'Phone Number' => 'የስልክ ቁጥር',
            'Access Code' => 'የመግቢያ ኮድ',
            'Enter your lease access code' => 'የኪራይ መግቢያ ኮድዎን ያስገቡ',
            'Invalid phone number or access code' => 'የተሳሳተ የስልክ ቁጥር ወይም የመግቢያ ኮድ',
            'Security token expired. Please try again.' => 'የደህንነት ቶከን ጊዜው አልፎበታል። እባክዎ እንደገና ይሞክሩ።',
            'Security token expired. Please refresh and try again.' => 'የደህንነት ቶከን ጊዜው አልፎበታል። እባክዎ ገጹን ያድሱና እንደገና ይሞክሩ።',
            'Please enter both your phone number and access code.' => 'እባክዎ የስልክ ቁጥርዎን እና የመግቢያ ኮድዎን ሁለቱንም ያስገቡ።',
            'Please enter both email and password.' => 'እባክዎ ኢሜይል እና የይለፍ ቃል ሁለቱንም ያስገቡ።',

            // Navigation
            'Welcome' => 'እንኳን ደህና መጡ',
            'Request Repair' => 'ጥገና ጠይቅ',
            'Payments' => 'ክፍያዎች',
            'Dashboard' => 'ዳሽቦርድ',
            'Leases' => 'ውሎች',
            'Generate Bills' => 'ደረሰኞች ፍጠር',
            'Verify Payments' => 'ክፍያዎችን አረጋግጥ',
            'Maintenance' => 'ጥገና',
            'Buildings & Units' => 'ሕንፃዎች እና ክፍሎች',

            // Lease / invoice table
            'Lease Information' => 'የኪራይ መረጃ',
            'Tenant' => 'ተከራይ',
            'Phone' => 'ስልክ',
            'Building' => 'ሕንፃ',
            'Unit' => 'ክፍል',
            'Lease Status' => 'የኪራይ ሁኔታ',
            'Lease Period' => 'የኪራይ ጊዜ',
            'Current Invoice' => 'የአሁኑ ደረሰኝ',
            'Billing Month' => 'የክፍያ ወር',
            'Due Date' => 'የመክፈያ ቀን',
            'Rent' => 'ኪራይ',
            'Service Charge' => 'የአገልግሎት ክፍያ',
            'Utility Share' => 'የፍጆታ ድርሻ',
            'VAT' => 'ተ.እ.ታ',
            'Total Amount' => 'ጠቅላላ መጠን',
            'Already Paid' => 'ከዚህ ቀደም የተከፈለ',
            'Balance Due' => 'የሚከፈል ቀሪ ሂሳብ',
            'Status' => 'ሁኔታ',
            'Invoice ID' => 'የደረሰኝ መለያ',
            'Invoice' => 'ደረሰኝ',

            // Payment form
            'Upload Payment Receipt' => 'የክፍያ ደረሰኝ ይስቀሉ',
            'Payment Amount (ETB)' => 'የክፍያ መጠን (ብር)',
            'Payment Method' => 'የክፍያ ዘዴ',
            'Transaction Reference' => 'የግብይት ማጣቀሻ',
            'Receipt / Proof of Payment' => 'ደረሰኝ / የክፍያ ማስረጃ',
            'Submit Payment' => 'ክፍያ አስረክብ',
            'Payment History' => 'የክፍያ ታሪክ',
            'Date' => 'ቀን',
            'Month' => 'ወር',
            'Method' => 'ዘዴ',
            'Reference' => 'ማጣቀሻ',
            'Amount' => 'መጠን',
            'Telebirr' => 'ቴሌብር',
            'CBE Birr' => 'ሲቢኢ ብር',
            'Bank Transfer' => 'የባንክ ዝውውር',
            'Chapa' => 'ቻፓ',
            'Cash' => 'ጥሬ ገንዘብ',
            'Prefilled with your balance due. Adjust if paying a partial amount.' => 'በቀሪ ሂሳብዎ ተሞልቷል። ከፊል ክፍያ ከሚከፍሉ ያስተካክሉ።',
            'Leave blank only for cash payments.' => 'ለጥሬ ገንዘብ ክፍያ ብቻ ባዶ ይተዉት።',
            'JPG, PNG, WEBP, or PDF — max 5 MB' => 'JPG, PNG, WEBP, ወይም PDF — ከፍተኛው 5 ሜባ',
            'This invoice is fully paid. No payment is due at this time.' => 'ይህ ደረሰኝ ሙሉ በሙሉ ተከፍሏል። በአሁኑ ጊዜ የሚከፈል ክፍያ የለም።',
            'Your payment is pending verification. Please wait for management to confirm.' => 'ክፍያዎ በማረጋገጥ ላይ ነው። እባክዎ አስተዳደሩ እስኪያረጋግጥ ይጠብቁ።',
            'No invoices found for your lease at this time.' => 'በአሁኑ ጊዜ ለኪራይዎ ደረሰኝ አልተገኘም።',
            'No payments recorded yet.' => 'እስካሁን የተመዘገበ ክፍያ የለም።',
            'Payment receipt uploaded successfully! Your payment is now pending verification by the management team.' => 'የክፍያ ደረሰኝ በተሳካ ሁኔታ ተሰቅሏል! ክፍያዎ አሁን በአስተዳደር ቡድኑ በማረጋገጥ ላይ ነው።',

            // Repair page
            'Submit a Maintenance Request' => 'የጥገና ጥያቄ ያስረክቡ',
            'Category' => 'ምድብ',
            'Description' => 'መግለጫ',
            'Priority' => 'ቅድሚያ',
            'Submit Request' => 'ጥያቄ አስረክብ',
            'Your Previous Requests' => 'የቀደሙ ጥያቄዎችዎ',
            'You have not submitted any maintenance requests yet.' => 'እስካሁን ምንም የጥገና ጥያቄ አላስረከቡም።',
            'Describe the problem in detail — when it started, what you\'ve noticed, etc.' => 'ችግሩን በዝርዝር ይግለጹ — መቼ እንደተጀመረ፣ ያስተዋሉት ነገር፣ ወዘተ።',
            'Please select a valid category.' => 'እባክዎ ትክክለኛ ምድብ ይምረጡ።',
            'Please describe the issue.' => 'እባክዎ ችግሩን ይግለጹ።',
            'Invalid priority level.' => 'የተሳሳተ የቅድሚያ ደረጃ።',
            'Your maintenance request has been submitted successfully. The management team will review it shortly.' => 'የጥገና ጥያቄዎ በተሳካ ሁኔታ ተሰርቷል። የአስተዳደር ቡድኑ በቅርቡ ይገመግማል።',
            'Low — Minor inconvenience' => 'ዝቅተኛ — ትንሽ ችግር',
            'Medium — Needs attention' => 'መካከለኛ — ትኩረት ይፈልጋል',
            'High — Urgent issue' => 'ከፍተኛ — አስቸኳይ ችግር',
            'Urgent — Emergency' => 'አስቸኳይ — ድንገተኛ አደጋ',
            'Please enter a title for your request.' => 'እባክዎ ለጥያቄዎ ርዕስ ያስገቡ።',
            'Use "Urgent" only for emergencies (water leak, electrical hazard, gas, etc.).' => '"አስቸኳይ" ን ለድንገተኛ አደጋዎች ብቻ ይጠቀሙ (የውሃ መፍሰስ፣ የኤሌክትሪክ አደጋ፣ ጋዝ፣ ወዘተ)።',
            'Select category' => 'ምድብ ይምረጡ',

            // Statuses
            'Active' => 'ንቁ',
            'Expired' => 'ጊዜው አልፎበታል',
            'Terminated' => 'ተቋርጧል',
            'Vacant' => 'ባዶ',
            'Occupied' => 'ተይዟል',
            'Paid' => 'ተከፍሏል',
            'Unpaid' => 'አልተከፈለም',
            'Partial' => 'ከፊል',
            'Pending' => 'በመጠባበቅ ላይ',
            'Approved' => 'ጸድቋል',
            'Rejected' => 'ተቀባይነት አላገኘም',
            'Overdue' => 'ጊዜ ያለፈበት',
            'Pending Verification' => 'በማረጋገጥ ላይ',
            'Open' => 'ክፍት',
            'In Progress' => 'በሂደት ላይ',
            'Resolved' => 'ተፈትቷል',
            'Closed' => 'ተዘግቷል',
            'Low' => 'ዝቅተኛ',
            'Medium' => 'መካከለኛ',
            'High' => 'ከፍተኛ',
            'Urgent' => 'አስቸኳይ',

            // Categories
            'Plumbing' => 'የቧንቧ ሥራ',
            'Electrical' => 'ኤሌክትሪክ',
            'Structural' => 'መዋቅራዊ',
            'Appliance' => 'መሣሪያ',
            'Other' => 'ሌላ',

            // CRUD / admin common
            'Actions' => 'ተግባራት',
            'Save' => 'አስቀምጥ',
            'Cancel' => 'ሰርዝ',
            'Delete' => 'ሰርዝ',
            'Edit' => 'አርትዕ',
            'Add' => 'አክል',
            'Search' => 'ፈልግ',
            'Name' => 'ስም',
            'Location' => 'አካባቢ',
            'Total Floors' => 'ጠቅላላ ፎቆች',
            'Unit Code' => 'የክፍል ኮድ',
            'Floor' => 'ፎቅ',
            'Type' => 'ዓይነት',
            'Area' => 'ስፋት',
            'Monthly Rent' => 'ወርሃዊ ኪራይ',
            'Deposit' => 'ተቀማጭ ገንዘብ',
            'Start Date' => 'መጀመሪያ ቀን',
            'End Date' => 'ማብቂያ ቀን',
            'Full Name' => 'ሙሉ ስም',
            'TIN Number' => 'የቲን ቁጥር',
            'Email' => 'ኢሜይል',
            'Total' => 'ጠቅላላ',
            'Balance' => 'ቀሪ ሂሳብ',
            'Paid Amount' => 'የተከፈለ መጠን',
            'No data' => 'መረጃ የለም',

            // Print docs
            'Print / Save PDF' => 'አትም / PDF አስቀምጥ',
            'Lease Contract' => 'የኪራይ ውል',
            'Payment Receipt' => 'የክፍያ ደረሰኝ',
            'FULLY PAID' => 'ሙሉ በሙሉ ተከፍሏል',
            'Shared Utility / Building Expense' => 'የጋራ ፍጆታ / የሕንፃ ወጪ',
            'Payment methods accepted' => 'የሚተቀበሉ የክፍያ ዘዴዎች',

            '— Select —' => '— ይምረጡ —',
        ];
        return $m;
    }
}

/* ---------- 3. t() — translate a static English string ---------- */
if (!function_exists('t')) {
    function t(string $en): string
    {
        if (current_lang() !== 'am') {
            return $en;
        }
        $map = i18n_am_map();
        return $map[$en] ?? $en;
    }
}

/* ---------- 4. Ethiopic script detection ---------- */
if (!function_exists('i18n_has_ethiopic')) {
    function i18n_has_ethiopic(string $s): bool
    {
        return (bool) preg_match('/[\x{1200}-\x{137F}]/u', $s);
    }
}

/* ---------- 5. tr() — machine-translate dynamic DB text ---------- */
if (!function_exists('tr')) {
    /**
     * Translate arbitrary DB text to the current UI language.
     *  - Skips identifiers / numbers / personal names (configurable).
     *  - Caches results in `translation_cache` table.
     *  - Falls back to the original text when offline / API fails.
     */
    function tr(?string $text, bool $allowNames = false): string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }
        $target = current_lang();

        // If UI is English, only translate text that is clearly Amharic.
        // If UI is Amharic, translate text that is clearly not Amharic.
        $isAmharicText = i18n_has_ethiopic($text);

        if ($target === 'en') {
            if (!$isAmharicText) {
                return $text; // already English — nothing to do
            }
            $sl = 'am'; $tl = 'en';
        } else { // target 'am'
            if ($isAmharicText) {
                return $text; // already Amharic
            }
            $sl = 'en'; $tl = 'am';
        }

        // Never translate things that look like identifiers / pure numbers.
        if (preg_match('/^[\s\p{N}+()\-.\/#,:%]+$/u', $text)) {
            return $text;
        }

        // Machine translation disabled / offline → return original.
        if (!defined('I18N_MACHINE') || I18N_MACHINE !== true) {
            return $text;
        }

        $hash = md5($sl . '|' . $tl . '|' . $text);

        // --- Cache lookup (MySQL) ---
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            try {
                $stmt = $GLOBALS['pdo']->prepare(
                    'SELECT translated_text FROM translation_cache WHERE source_hash = :h LIMIT 1'
                );
                $stmt->execute([':h' => $hash]);
                $cached = $stmt->fetchColumn();
                if ($cached !== false && $cached !== null) {
                    return (string) $cached;
                }
            } catch (Throwable $e) {
                // cache table may not exist yet — ignore
            }
        }

        $translated = i18n_api_translate($text, $sl, $tl);
        if ($translated === null || $translated === '') {
            return $text; // graceful fallback
        }

        // --- Cache store ---
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            try {
                $stmt = $GLOBALS['pdo']->prepare(
                    'INSERT IGNORE INTO translation_cache
                        (source_hash, source_text, source_lang, target_lang, translated_text)
                     VALUES (:h, :s, :sl, :tl, :t)'
                );
                $stmt->execute([
                    ':h' => $hash, ':s' => $text,
                    ':sl' => $sl, ':tl' => $tl, ':t' => $translated,
                ]);
            } catch (Throwable $e) {
                // ignore cache write errors
            }
        }
        return $translated;
    }
}

/* ---------- 6. External translation API (Google gtx, free endpoint) ---------- */
if (!function_exists('i18n_proxy')) {
    /**
     * Detect an HTTP(S) proxy: env vars first, then the Windows system proxy.
     * Returns e.g. "http://proxy.huawei.com:8080" or '' when none.
     */
    function i18n_proxy(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        foreach (['HTTPS_PROXY', 'https_proxy', 'HTTP_PROXY', 'http_proxy'] as $k) {
            $v = getenv($k);
            if ($v) {
                $cached = (strpos($v, '://') === false) ? 'http://' . $v : $v;
                return $cached;
            }
        }
        // Windows system proxy from the registry
        if (function_exists('shell_exec') && PHP_OS_FAMILY === 'Windows') {
            $key = 'HKCU\\Software\\Microsoft\\Windows\\CurrentVersion\\Internet Settings';
            $enabled = @shell_exec('reg query "' . $key . '" /v ProxyEnable 2>nul');
            if ($enabled && preg_match('/0x1/i', $enabled)) {
                $srv = @shell_exec('reg query "' . $key . '" /v ProxyServer 2>nul');
                if ($srv && preg_match('/ProxyServer\s+REG_SZ\s+(\S+)/i', $srv, $mm)) {
                    $p = trim($mm[1]);
                    $cached = (strpos($p, '://') === false) ? 'http://' . $p : $p;
                    return $cached;
                }
            }
        }
        $cached = '';
        return $cached;
    }
}

if (!function_exists('i18n_api_translate')) {
    function i18n_api_translate(string $text, string $sl, string $tl): ?string
    {
        $q  = rawurlencode($text);
        $url = "https://translate.googleapis.com/translate_a/single"
             . "?client=gtx&sl={$sl}&tl={$tl}&dt=t&q={$q}";
        $proxy = i18n_proxy();

        $body = null;
        try {
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                $opts = [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 8,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_USERAGENT      => 'Mozilla/5.0',
                ];
                if ($proxy !== '') {
                    $opts[CURLOPT_PROXY] = $proxy;
                }
                curl_setopt_array($ch, $opts);
                $body = curl_exec($ch);
                curl_close($ch);
            } else {
                $http = ['timeout' => 8, 'header' => "User-Agent: Mozilla/5.0\r\n"];
                if ($proxy !== '') {
                    $http['proxy']           = $proxy;
                    $http['request_fulluri'] = true;
                }
                $ctx  = stream_context_create(['http' => $http]);
                $body = @file_get_contents($url, false, $ctx);
            }
        } catch (Throwable $e) {
            return null;
        }

        if (!$body) {
            return null;
        }
        $json = json_decode($body, true);
        if (!is_array($json) || !isset($json[0]) || !is_array($json[0])) {
            return null;
        }
        $out = '';
        foreach ($json[0] as $seg) {
            if (isset($seg[0])) {
                $out .= $seg[0];
            }
        }
        $out = trim($out);
        return $out !== '' ? $out : null;
    }
}

/* ---------- 7. Language switcher HTML ---------- */
if (!function_exists('i18n_switcher_html')) {
    function i18n_switcher_html(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        // strip existing lang param
        $parts = parse_url($uri);
        $path  = $parts['path'] ?? '/';
        $query = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        unset($query['lang']);
        $base = $path;
        $enUrl = $base . ($query ? '?' . http_build_query($query + ['lang' => 'en']) : '?lang=en');
        $amUrl = $base . ($query ? '?' . http_build_query($query + ['lang' => 'am']) : '?lang=am');

        $cur  = current_lang();
        $enOn = $cur === 'en' ? ' i18n-on' : '';
        $amOn = $cur === 'am' ? ' i18n-on' : '';

        return <<<HTML
<style>
#i18n-switch{position:fixed;top:12px;right:12px;z-index:99999;display:flex;gap:2px;
  background:#fff;border:1px solid #dadce0;border-radius:20px;padding:3px;
  box-shadow:0 2px 8px rgba(0,0,0,.12);font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;}
#i18n-switch a{display:block;padding:4px 12px;border-radius:16px;font-size:12px;font-weight:600;
  text-decoration:none;color:#5f6368;line-height:1.4;}
#i18n-switch a.i18n-on{background:#1a73e8;color:#fff;}
#i18n-switch a:not(.i18n-on):hover{background:#f1f3f4;}
@media print{#i18n-switch{display:none}}
</style>
<div id="i18n-switch" role="group" aria-label="Language / ቋንቋ">
  <a href="{$enUrl}" class="i18n-en{$enOn}" title="English">EN</a>
  <a href="{$amUrl}" class="i18n-am{$amOn}" title="አማርኛ">አማ</a>
</div>
HTML;
    }
}

/* ---------- 8. HTML post-processor (dictionary translation) ---------- */
if (!function_exists('i18n_translate_html')) {
    function i18n_translate_html(string $html): string
    {
        // Only rewrite text when Amharic is selected.
        if (current_lang() !== 'am') {
            return $html;
        }
        $map = i18n_am_map();
        // Longest keys first so phrases win over sub-words.
        uksort($map, fn($a, $b) => strlen($b) <=> strlen($a));

        // Tokenise: script/style blocks stay untouched; other text nodes translated.
        $tokens = preg_split(
            '/(<script\b[^>]*>.*?<\/script>|<style\b[^>]*>.*?<\/style>)/is',
            $html, -1, PREG_SPLIT_DELIM_CAPTURE
        );
        if ($tokens === false) {
            return $html;
        }
        $out = '';
        foreach ($tokens as $tok) {
            if ($tok === '' ) { continue; }
            if ($tok[0] === '<' && preg_match('/^<(script|style)\b/i', $tok)) {
                $out .= $tok; // script/style — leave as-is
                continue;
            }
            // Translate text nodes: replace known phrases inside text between tags.
            $out .= preg_replace_callback(
                '/>([^<]+)</u',
                function ($mm) use ($map) {
                    return '>' . strtr($mm[1], $map) . '<';
                },
                $tok
            );
            // Translate safe attributes (placeholder / alt / aria-label).
            $out = preg_replace_callback(
                '/\b(placeholder|alt|aria-label)="([^"]*)"/u',
                function ($mm) use ($map) {
                    return $mm[1] . '="' . strtr($mm[2], $map) . '"';
                },
                $out
            );
        }
        return $out;
    }
}

/* ---------- 9. Auto-activate output buffering on every page ---------- */
if (!defined('I18N_BUFFER_ACTIVE')) {
    define('I18N_BUFFER_ACTIVE', true);
    ob_start(function (string $buffer, int $phase) {
        if (($phase & PHP_OUTPUT_HANDLER_FINAL) === 0) {
            return $buffer;
        }
        if ($buffer === '') {
            return $buffer;
        }
        // Skip non-HTML responses
        if (stripos($buffer, '<html') === false && stripos($buffer, '</body>') === false) {
            return $buffer;
        }
        $buffer = i18n_translate_html($buffer);
        // Inject the floating language switcher before </body>
        if (stripos($buffer, '</body>') !== false) {
            $buffer = preg_replace('/<\/body>/i', i18n_switcher_html() . '</body>', $buffer, 1);
        }
        return $buffer;
    });
}
