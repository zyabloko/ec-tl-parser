<?php

declare(strict_types=1);


/**
 * Parse XML thing to a recursive array.
 *
 * @param string $content
 *
 * @return array
 */
function xml2array(string $content): array
{
    $sxi = new SimpleXmlIterator($content, 0, false);

    return sxiToArray($sxi);
}

/**
 * Translate official type URI to human readable string.
 *
 * @param string $type
 *
 * @return string
 */
function translateType(string $type): string
{
    $types = [
        'http://uri.etsi.org/TrstSvc/Svctype/CA/QC'                    => 'qualified certificate issuing trust service',
        'http://uri.etsi.org/TrstSvc/Svctype/TSA/QTST'                 => 'qualified electronic time stamp generation service',
        'http://uri.etsi.org/TrstSvc/Svctype/TSA'                      => 'time-stamping generation service, not qualified',
        'http://uri.etsi.org/TrstSvc/Svctype/EDS/Q'                    => 'qualified electronic delivery service',
        'https://uri.etsi.org/TrstSvc/Svctype/CA/PKC/'                 => 'certificate generation service, not qualified',
        'http://uri.etsi.org/TrstSvc/Svctype/CA/PKC'                   => 'certificate generation service, not qualified',
        'http://uri.etsi.org/TrstSvc/Svctype/Certstatus/OCSP/QC'       => 'qualified OCSP responder',
        'http://uri.etsi.org/TrstSvc/Svctype/QESValidation/Q'          => 'qualified validation service for qualified electronic signatures and/or qualified electronic seals',
        'http://uri.etsi.org/TrstSvc/Svctype/Certstatus/OCSP'          => 'certificate validity status service, not qualified',
        'http://uri.etsi.org/TrstSvc/Svctype/PSES/Q'                   => 'qualified preservation service for qualified electronic signatures and/or qualified electronic seals',
        'http://uri.etsi.org/TrstSvc/Svctype/NationalRootCA-QC'        => 'national root signing CA',
        'http://uri.etsi.org/TrstSvd/Svctype/TLIssuer'                 => 'service issuing trusted lists',
        'http://uri.etsi.org/TrstSvc/Svctype/EDS/REM/Q'                => 'qualified electronic registered mail delivery service',
        'http://uri.etsi.org/TrstSvc/Svctype/TSA/TSS-QC'               => 'time-stamping service, not qualified',
        'http://uri.etsi.org/TrstSvc/Svctype/IdV'                      => 'identity verification service',
        'http://uri.etsi.org/TrstSvc/Svctype/TSA/TSS-AdESQCandQES'     => 'time-stamping service, not qualified',
        'https://uri.etsi.org/TrstSvc/Svctype/ACA/'                    => 'attribute certificate generation service',
        'http://uri.etsi.org/TrstSvc/Svctype/ACA'                      => 'attribute certificate generation service',
        'http://uri.etsi.org/TrstSvc/Svctype/unspecified'              => 'trust service of an unspecified type',
        'http://uri.etsi.org/TrstSvc/Svctype/RA'                       => 'registration service',
        'http://uri.etsi.org/TrstSvc/Svctype/SignaturePolicyAuthority' => 'service responsible for issuing, publishing or maintenance of signature policies',
        'https://uri.etsi.org/TrstSvc/Svctype/IdV/nothavingPKIid/'     => 'Identity verification service that cannot be identified by a specific PKI-based public key.',

    ];
    if (!isset($types[$type])) {
        debugMessage('UNKNOWN TYPE: ' . $type);

        return $type;
    }

    return $types[$type];
}

/**
 * Join subject string and catch some weird situations.
 *
 * @param array $fields
 *
 * @return string
 */
function joinSubject(array $fields): string
{
    $return = '';
    foreach ($fields as $index => $value) {
        if ($index === '0') {
            $return .= $$value;
            continue;
        }
        if (is_array($value)) {
            $value = join(' ', $value);
        }
        $return .= sprintf(
            '%s=%s',
            $index,
            $value
        );
    }

    return $return;
}

/**
 * Return readable algorithm string.
 *
 * @param string $algo
 * @param int    $bits
 *
 * @return string
 */
function translateAlgorithm(string $algo, int $bits)
{

    if (substr($algo, -17) === 'WithRSAEncryption') {
        return 'RSA-' . $bits;
    }

    return $algo;
}

function translateSig(string $algo)
{
    if (trim($algo) === 'RSA-SHA256') {
        return 'SHA-256';
    }

    if (trim($algo) === 'RSA-SHA1') {
        return 'SHA-1';
    }

    return $algo;
}


function translateState(string $state): string
{
    $states = [
        'http://uri.etsi.org/TrstSvc/TrustedList/Svcstatus/withdrawn'                 => 'withdrawn',
        'http://uri.etsi.org/TrstSvc/TrustedList/Svcstatus/granted'                   => 'granted',
        'http://uri.etsi.org/TrstSvc/TrustedList/Svcstatus/deprecatedatnationallevel' => 'deprecated at national level',
        'http://uri.etsi.org/TrstSvc/TrustedList/Svcstatus/recognisedatnationallevel' => 'recognized at national level',

    ];

    return $states[$state];
}

/**
 * @param SimpleXMLIterator $sxi
 *
 * @return array
 */
function sxiToArray(SimpleXMLIterator $sxi): array
{
    $a = [];
    for ($sxi->rewind(); $sxi->valid(); $sxi->next()) {
        if (!array_key_exists($sxi->key(), $a)) {
            $a[$sxi->key()] = [];
        }
        if ($sxi->hasChildren()) {
            $a[$sxi->key()][] = sxiToArray($sxi->current());
        } else {
            $a[$sxi->key()][] = strval($sxi->current());
        }
    }

    return $a;
}

function debugMessage(string $message): void
{
    echo $message . "\n";
}


function patchCRL($string)
{
    $return = str_replace(['Full Name:', "\n", '  URI:'], '', $string);

    return $return;

}