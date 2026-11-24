<?php

require_once __DIR__ . '/certificate_templates.php';

function certificate_document_values(PDO $pdo, array $certificate): array {
    $issueTimestamp = !empty($certificate['issued_at']) ? strtotime($certificate['issued_at']) : time();
    $age = (int)date_diff(date_create($certificate['birthdate']), date_create('today'))->y;
    $barangayName = app_setting($pdo, 'barangay_name', BRGY_NAME);
    $municipality = app_setting($pdo, 'barangay_municipality', BRGY_MUNI);
    $province = app_setting($pdo, 'barangay_province', BRGY_PROVINCE);

    return [
        'resident_name' => full_name($certificate),
        'resident_first_name' => (string)$certificate['first_name'],
        'resident_middle_name' => (string)($certificate['middle_name'] ?? ''),
        'resident_last_name' => (string)$certificate['last_name'],
        'resident_suffix' => (string)($certificate['suffix'] ?? ''),
        'resident_age' => (string)$age,
        'sex' => (string)$certificate['sex'],
        'civil_status' => (string)$certificate['civil_status'],
        'purok' => (string)$certificate['purok'],
        'address' => (string)($certificate['address'] ?? ''),
        'certificate_type' => (string)$certificate['cert_type'],
        'purpose' => (string)$certificate['cpurpose'],
        'reference_number' => ref_no($certificate['cid']),
        'issue_date' => date('F j, Y', $issueTimestamp),
        'fee' => 'PHP ' . number_format((float)$certificate['fee'], 2),
        'receipt_type' => (string)($certificate['receipt_type'] ?? ''),
        'receipt_number' => (string)($certificate['receipt_no'] ?? ''),
        'receipt_date' => !empty($certificate['receipt_date'])
            ? date('F j, Y', strtotime($certificate['receipt_date']))
            : '',
        'barangay_name' => (string)$barangayName,
        'municipality' => (string)$municipality,
        'province' => (string)$province,
        'punong_barangay' => (string)app_setting($pdo, 'barangay_punong_barangay', ''),
    ];
}

function certificate_uploaded_document_html(PDO $pdo, array $certificate, string $fileName): string {
    return certificate_template_render_html(
        certificate_template_path($fileName),
        certificate_document_values($pdo, $certificate)
    );
}

function certificate_standard_document_html(PDO $pdo, array $certificate): string {
    $barangayName = app_setting($pdo, 'barangay_name', BRGY_NAME);
    $municipality = app_setting($pdo, 'barangay_municipality', BRGY_MUNI);
    $province = app_setting($pdo, 'barangay_province', BRGY_PROVINCE);
    $punongBarangay = app_setting($pdo, 'barangay_punong_barangay', '');
    $sealName = app_setting($pdo, 'barangay_seal', '');
    $sealUrl = '';
    if (preg_match('/\A[a-f0-9]{32}\.(?:png|jpg|webp)\z/i', $sealName)
        && is_file(__DIR__ . '/../assets/uploads/barangay-seals/' . $sealName)) {
        $sealUrl = 'assets/uploads/barangay-seals/' . $sealName;
    }

    $name = strtoupper(full_name($certificate));
    $age = (int)date_diff(date_create($certificate['birthdate']), date_create('today'))->y;
    $civil = strtolower((string)$certificate['civil_status']);
    $place = $certificate['purok'] . ', Barangay ' . $barangayName . ', ' . $municipality . ', ' . $province;
    $purpose = e($certificate['cpurpose']);
    $intro = 'This is to certify that <b>' . e($name) . '</b>, of legal age (' . $age
        . ' years old), ' . e($civil) . ', is a bona fide resident of ' . e($place) . '.';
    switch ($certificate['cert_type']) {
        case 'Barangay Clearance':
            $body = $intro . ' This clearance is issued upon request for <b>' . $purpose . '</b> purposes. The person named above has '
                . ($certificate['has_pending_case'] ? 'a pending case on the barangay blotter.' : 'no pending case on the barangay blotter as of this date.');
            break;
        case 'Business Clearance':
            $body = $intro . ' This clearance is issued to certify that the barangay has no objection to the business or activity stated: <b>'
                . $purpose . '</b>.';
            break;
        case 'Residency':
            $body = $intro . ' This certification is issued upon request for <b>' . $purpose . '</b> purposes.';
            break;
        case 'Good Moral Character':
            $body = $intro . ' The person named above is known to be of good moral character and has not been involved in any case in this barangay. Issued for <b>'
                . $purpose . '</b>.';
            break;
        case 'Certificate of Indigency':
            $body = $intro . ' The person named above belongs to an indigent family of this barangay. Issued for <b>' . $purpose . '</b>.';
            break;
        case 'Solo Parent':
            $body = $intro . ' The person named above is a solo parent. Issued for <b>' . $purpose . '</b>.';
            break;
        default:
            $body = $intro;
    }
    $issuedTimestamp = !empty($certificate['issued_at']) ? strtotime($certificate['issued_at']) : time();
    $sealMarkup = $sealUrl !== '' ? '<img class="certificate-seal" src="' . e($sealUrl) . '" alt="Barangay seal">' : '';
    $officialMarkup = $punongBarangay !== ''
        ? '<b>' . e($punongBarangay) . '</b><br>Punong Barangay'
        : '<b>[NAME OF OFFICIAL ON DUTY]</b><br>[Position]';
    $receiptMarkup = !empty($certificate['receipt_no'])
        ? ' · ' . e($certificate['receipt_type']) . ' No. ' . e($certificate['receipt_no']) . ' · ' . peso($certificate['fee'])
        : ' · Free of charge';

    return $sealMarkup
        . '<div class="certificate-letterhead" style="text-align:center;font-size:15px;line-height:1.5">'
        . 'Republic of the Philippines<br>Province of ' . e($province) . ' · Municipality of ' . e($municipality) . '<br>'
        . '<b style="font-size:20px">BARANGAY ' . e(strtoupper($barangayName)) . '</b><br>Office of the Punong Barangay</div>'
        . '<hr><h2>' . e(strtoupper((string)$certificate['cert_type'])) . '</h2>'
        . '<p><b>TO WHOM IT MAY CONCERN:</b></p><p>' . $body . '</p>'
        . '<p>Issued this ' . e(date('jS \d\a\y \o\f F Y', $issuedTimestamp)) . ' at the Barangay Hall of Barangay '
        . e($barangayName) . '.</p>'
        . '<p class="certificate-signature" style="margin-top:50px;text-align:right">' . $officialMarkup . '</p>'
        . '<p class="certificate-reference" style="font-size:12px;text-align:left;margin-top:30px">Ref. '
        . e(ref_no($certificate['cid'])) . $receiptMarkup . '</p>';
}
