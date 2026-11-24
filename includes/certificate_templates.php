<?php

const CERTIFICATE_TEMPLATE_MAX_BYTES = 10485760;
const CERTIFICATE_TEMPLATE_MAX_EXPANDED_BYTES = 52428800;

function certificate_template_directory(): string {
    return dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'BrgyTraceCertificateTemplates';
}

function certificate_template_path(string $fileName): string {
    if (!preg_match('/\A[a-f0-9]{32}\.docx\z/i', $fileName)) {
        throw new RuntimeException('The certificate template file name is invalid.');
    }
    return certificate_template_directory() . DIRECTORY_SEPARATOR . $fileName;
}

function certificate_template_is_valid_docx(string $path): bool {
    if (!is_file($path) || filesize($path) < 1 || filesize($path) > CERTIFICATE_TEMPLATE_MAX_BYTES) {
        return false;
    }

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
        return false;
    }

    try {
        if ($zip->numFiles < 3 || $zip->numFiles > 2000) {
            return false;
        }
        $expandedBytes = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->statIndex($index);
            if ($entry === false || !isset($entry['size'])) {
                return false;
            }
            $expandedBytes += (int)$entry['size'];
            if ($expandedBytes > CERTIFICATE_TEMPLATE_MAX_EXPANDED_BYTES) {
                return false;
            }
        }

        $contentTypes = $zip->getFromName('[Content_Types].xml');
        $documentXml = $zip->getFromName('word/document.xml');
        if (!is_string($contentTypes) || strpos($contentTypes, 'wordprocessingml.document.main+xml') === false
            || !is_string($documentXml)) {
            return false;
        }

        $document = new DOMDocument();
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        if (!@$document->loadXML($documentXml, LIBXML_NONET | LIBXML_COMPACT)) {
            return false;
        }
        return $document->documentElement !== null
            && $document->documentElement->localName === 'document'
            && $document->documentElement->namespaceURI === 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    } finally {
        $zip->close();
    }
}

function certificate_template_placeholders(): array {
    return [
        'resident_name' => 'Resident full name',
        'resident_first_name' => 'Resident first name',
        'resident_middle_name' => 'Resident middle name',
        'resident_last_name' => 'Resident last name',
        'resident_suffix' => 'Resident suffix',
        'resident_age' => 'Resident age',
        'sex' => 'Resident sex',
        'civil_status' => 'Civil status',
        'purok' => 'Purok',
        'address' => 'Resident address',
        'certificate_type' => 'Certificate type',
        'purpose' => 'Certificate purpose',
        'reference_number' => 'Certificate reference number',
        'issue_date' => 'Issue date',
        'fee' => 'Fee',
        'receipt_type' => 'Receipt type',
        'receipt_number' => 'Receipt number',
        'receipt_date' => 'Receipt date paid',
        'barangay_name' => 'Barangay name',
        'municipality' => 'Municipality',
        'province' => 'Province',
        'punong_barangay' => 'Punong Barangay',
    ];
}

function certificate_template_render_html(string $sourcePath, array $values): string {
    if (!is_file($sourcePath)) {
        throw new RuntimeException('The selected certificate template is unavailable.');
    }

    $zip = new ZipArchive();
    if ($zip->open($sourcePath, ZipArchive::CHECKCONS) !== true) {
        throw new RuntimeException('Could not open the selected certificate template.');
    }

    try {
        $documentXml = $zip->getFromName('word/document.xml');
        if (!is_string($documentXml)) {
            throw new RuntimeException('The selected certificate template has no document content.');
        }
        $headerHtml = '';
        $footerHtml = '';
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $partName = $zip->getNameIndex($index);
            if (!is_string($partName)) {
                continue;
            }
            if (preg_match('#\Aword/header\d*\.xml\z#', $partName)) {
                $xml = $zip->getFromIndex($index);
                if (is_string($xml)) {
                    $headerHtml .= '<header class="docx-header">' . certificate_template_render_part(
                        $xml,
                        $zip,
                        'word/_rels/' . basename($partName) . '.rels',
                        $values
                    ) . '</header>';
                }
            } elseif (preg_match('#\Aword/footer\d*\.xml\z#', $partName)) {
                $xml = $zip->getFromIndex($index);
                if (is_string($xml)) {
                    $footerHtml .= '<footer class="docx-footer">' . certificate_template_render_part(
                        $xml,
                        $zip,
                        'word/_rels/' . basename($partName) . '.rels',
                        $values
                    ) . '</footer>';
                }
            }
        }

        return $headerHtml
            . certificate_template_render_part($documentXml, $zip, 'word/_rels/document.xml.rels', $values, true)
            . $footerHtml;
    } finally {
        $zip->close();
    }
}

function certificate_template_render_part(
    string $xml,
    ZipArchive $zip,
    string $relationshipsPath,
    array $values,
    bool $documentPart = false
): string {
    $replacements = [];
    foreach ($values as $placeholder => $value) {
        $cleanValue = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$value);
        $replacements['{{' . $placeholder . '}}'] = htmlspecialchars(
            $cleanValue === null ? (string)$value : $cleanValue,
            ENT_QUOTES | ENT_XML1,
            'UTF-8'
        );
    }
    $xml = str_replace(array_keys($replacements), array_values($replacements), $xml);

    $document = new DOMDocument();
    $document->resolveExternals = false;
    $document->substituteEntities = false;
    if (!@$document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
        throw new RuntimeException('The selected certificate template contains invalid Word document content.');
    }
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
    $images = certificate_template_part_images($zip, $relationshipsPath);
    $root = $documentPart ? $xpath->query('/w:document/w:body')->item(0) : $document->documentElement;
    if (!$root instanceof DOMNode) {
        return '';
    }
    return certificate_template_render_nodes($root, $xpath, $images);
}

function certificate_template_part_images(ZipArchive $zip, string $relationshipsPath): array {
    $relationshipXml = $zip->getFromName($relationshipsPath);
    if (!is_string($relationshipXml)) {
        return [];
    }

    $relationships = new DOMDocument();
    $relationships->resolveExternals = false;
    $relationships->substituteEntities = false;
    if (!@$relationships->loadXML($relationshipXml, LIBXML_NONET | LIBXML_COMPACT)) {
        return [];
    }
    $images = [];
    foreach ($relationships->documentElement->childNodes as $relationship) {
        if (!$relationship instanceof DOMElement || $relationship->localName !== 'Relationship'
            || $relationship->getAttribute('TargetMode') === 'External'
            || substr($relationship->getAttribute('Type'), -6) !== '/image') {
            continue;
        }
        $relationshipId = $relationship->getAttribute('Id');
        $target = str_replace('\\', '/', rawurldecode($relationship->getAttribute('Target')));
        if ($relationshipId === '' || $target === '' || preg_match('/\A[a-z][a-z0-9+.-]*:/i', $target)) {
            continue;
        }

        $segments = strpos($target, '/') === 0 ? [] : ['word'];
        foreach (explode('/', ltrim($target, '/')) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if (!$segments) {
                    continue 2;
                }
                array_pop($segments);
            } else {
                $segments[] = $segment;
            }
        }
        $entryName = implode('/', $segments);
        $extension = strtolower(pathinfo($entryName, PATHINFO_EXTENSION));
        $mimeTypes = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif'];
        if (!isset($mimeTypes[$extension])) {
            continue;
        }
        $contents = $zip->getFromName($entryName);
        if (!is_string($contents) || strlen($contents) > 5 * 1024 * 1024) {
            continue;
        }
        $images[$relationshipId] = 'data:' . $mimeTypes[$extension] . ';base64,' . base64_encode($contents);
    }
    return $images;
}

function certificate_template_render_nodes(DOMNode $parent, DOMXPath $xpath, array $images): string {
    $html = '';
    foreach ($parent->childNodes as $child) {
        $html .= certificate_template_render_node($child, $xpath, $images);
    }
    return $html;
}

function certificate_template_render_node(DOMNode $node, DOMXPath $xpath, array $images): string {
    if (!$node instanceof DOMElement
        || $node->namespaceURI !== 'http://schemas.openxmlformats.org/wordprocessingml/2006/main') {
        return '';
    }

    switch ($node->localName) {
        case 'p':
            return '<p style="' . certificate_template_paragraph_style($node, $xpath) . '">'
                . certificate_template_render_nodes($node, $xpath, $images) . '</p>';
        case 'tbl':
            $rows = '';
            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement && $child->localName === 'tr') {
                    $rows .= certificate_template_render_node($child, $xpath, $images);
                }
            }
            return '<table class="docx-table"><tbody>' . $rows . '</tbody></table>';
        case 'tr':
            $cells = '';
            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement && $child->localName === 'tc') {
                    $cells .= certificate_template_render_node($child, $xpath, $images);
                }
            }
            return '<tr>' . $cells . '</tr>';
        case 'tc':
            return '<td>' . certificate_template_render_nodes($node, $xpath, $images) . '</td>';
        case 'r':
            return certificate_template_render_run($node, $xpath, $images);
        case 't':
            return htmlspecialchars($node->textContent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        case 'tab':
            return '&emsp;';
        case 'br':
        case 'cr':
            return $node->getAttribute('w:type') === 'page' ? '<br class="docx-page-break">' : '<br>';
        case 'drawing':
            $html = '';
            foreach ($xpath->query('.//a:blip', $node) as $blip) {
                if (!$blip instanceof DOMElement) {
                    continue;
                }
                $relationshipId = $blip->getAttributeNS(
                    'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
                    'embed'
                );
                if (isset($images[$relationshipId])) {
                    $html .= '<img class="docx-inline-image" src="' . $images[$relationshipId] . '" alt="">';
                }
            }
            return $html;
        case 'del':
        case 'pPr':
        case 'rPr':
        case 'tblPr':
        case 'tblGrid':
        case 'trPr':
        case 'tcPr':
        case 'sectPr':
        case 'proofErr':
        case 'bookmarkStart':
        case 'bookmarkEnd':
        case 'commentRangeStart':
        case 'commentRangeEnd':
        case 'lastRenderedPageBreak':
            return '';
        default:
            return certificate_template_render_nodes($node, $xpath, $images);
    }
}

function certificate_template_render_run(DOMElement $run, DOMXPath $xpath, array $images): string {
    $properties = $xpath->query('./w:rPr', $run)->item(0);
    $styles = [];
    if ($properties instanceof DOMElement) {
        foreach (['b' => 'font-weight:bold', 'i' => 'font-style:italic', 'strike' => 'text-decoration:line-through'] as $tag => $style) {
            $property = $xpath->query('./w:' . $tag, $properties)->item(0);
            if ($property instanceof DOMElement && $property->getAttribute('w:val') !== '0') {
                $styles[] = $style;
            }
        }
        $underline = $xpath->query('./w:u', $properties)->item(0);
        if ($underline instanceof DOMElement && $underline->getAttribute('w:val') !== 'none') {
            $styles[] = 'text-decoration:underline';
        }
        $fontSize = $xpath->query('./w:sz', $properties)->item(0);
        if ($fontSize instanceof DOMElement && ctype_digit($fontSize->getAttribute('w:val'))) {
            $size = max(6, min(96, (int)$fontSize->getAttribute('w:val') / 2));
            $styles[] = 'font-size:' . $size . 'pt';
        }
        $color = $xpath->query('./w:color', $properties)->item(0);
        if ($color instanceof DOMElement && preg_match('/\A[0-9a-f]{6}\z/i', $color->getAttribute('w:val'))) {
            $styles[] = 'color:#' . $color->getAttribute('w:val');
        }
        $font = $xpath->query('./w:rFonts', $properties)->item(0);
        if ($font instanceof DOMElement) {
            $fontName = $font->getAttribute('w:ascii');
            if (preg_match('/\A[A-Za-z0-9 ,._-]{1,60}\z/', $fontName)) {
                $styles[] = 'font-family:' . $fontName;
            }
        }
    }
    $content = certificate_template_render_nodes($run, $xpath, $images);
    return $styles ? '<span style="' . implode(';', $styles) . '">' . $content . '</span>' : $content;
}

function certificate_template_paragraph_style(DOMElement $paragraph, DOMXPath $xpath): string {
    $properties = $xpath->query('./w:pPr', $paragraph)->item(0);
    if (!$properties instanceof DOMElement) {
        return '';
    }
    $styles = [];
    $alignment = $xpath->query('./w:jc', $properties)->item(0);
    if ($alignment instanceof DOMElement) {
        $alignments = ['left' => 'left', 'center' => 'center', 'right' => 'right', 'both' => 'justify'];
        $value = $alignment->getAttribute('w:val');
        if (isset($alignments[$value])) {
            $styles[] = 'text-align:' . $alignments[$value];
        }
    }
    $spacing = $xpath->query('./w:spacing', $properties)->item(0);
    if ($spacing instanceof DOMElement) {
        foreach (['before' => 'margin-top', 'after' => 'margin-bottom'] as $attribute => $style) {
            $value = $spacing->getAttribute('w:' . $attribute);
            if (ctype_digit($value)) {
                $styles[] = $style . ':' . min(1440, (int)$value) / 20 . 'pt';
            }
        }
    }
    $indent = $xpath->query('./w:ind', $properties)->item(0);
    if ($indent instanceof DOMElement) {
        $left = $indent->getAttribute('w:left');
        if (ctype_digit($left)) {
            $styles[] = 'margin-left:' . min(2880, (int)$left) / 20 . 'pt';
        }
        $firstLine = $indent->getAttribute('w:firstLine');
        if (ctype_digit($firstLine)) {
            $styles[] = 'text-indent:' . min(2880, (int)$firstLine) / 20 . 'pt';
        }
    }
    return implode(';', $styles);
}

