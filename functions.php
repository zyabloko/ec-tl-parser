<?php

declare(strict_types=1);

include 'logger.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * @param $config
 * @return array
 */
function getCountries(): array
{
    global $logger, $config;
    $logger->debug('Start of getCountries()');
    // get country list
    $uri       = 'https://webgate.ec.europa.eu/tl-browser/api/home';
    $client    = new Client($config['guzzle']);
    $countries = [];

    try {
        $request = $client->request('GET', $uri);
    } catch (GuzzleException $e) {
        debugMessage($e->getMessage());
        die($e->getMessage());
    }

    $result = json_decode($request->getBody()->getContents(), true);
    $logger->info(sprintf('Counted %d countries', count($result['content']['tls'])));

    // looping each country:
    foreach ($result['content']['tls'] as $country) {
        $countryCode = $country['territoryCode'];
        $countryName = $country['countryName'];

        // filter countries:
        if ($config['filter_countries'] === true && !in_array($countryCode, $config['include_countries'], true)) {

            $logger->debug(sprintf('Country %s (%s) will not be included.', $countryName, $countryCode));
            continue;
        }

        $logger->info(sprintf('Will include country %s (%s)', $countryName, $countryCode));
        $countries[] =
            [
                'code'      => $countryCode,
                'name'      => $countryName,
                'providers' => getProviders($countryCode, $countryName),
            ];
    }

    $logger->debug('End of getCountries()');

    return $countries;
}

/**
 * @param string $countryCode
 * @param string $countryName
 * @return array
 */
function getProviders(string $countryCode, string $countryName): array
{
    global $config, $logger;
    $providers = [];
    // getting providers for this country.
    $countryUri = sprintf('https://webgate.ec.europa.eu/tl-browser/api/download/%s', $countryCode);
    $client     = new Client($config['guzzle']);
    $logger->debug(sprintf('Downloading providers for %s (%s)', $countryName, $countryUri));

    try {
        $countryRequest = $client->request('GET', $countryUri);
    } catch (GuzzleException $e) {
        $logger->error($e->getMessage());
        $logger->error($e->getTraceAsString());
        echo sprintf('Could not download %s', $countryUri);
        exit(1);
    }

    $logger->debug('Got everything!');
    $countryData = json_decode($countryRequest->getBody()->getContents(), true);
    $xml         = base64_decode($countryData['content']);
    $array       = xml2array($xml);
    // loop each provider from this country:
    if (!isset($array['TrustServiceProviderList'])) {
        $logger->warn(sprintf('No services listed for %s', $countryName));

        return [];
    }

    foreach ($array['TrustServiceProviderList'] as $list) {
        foreach ($list as $xList) {
            foreach ($xList as $provider) {
                $current = [];
                // name of the provider
                $providerName = $provider['TSPInformation'][0]['TSPName'][0]['Name'][0];

                // include it?
                if ($config['filter_qtsps'] === true && !in_array($providerName, $config['include_qtsp'], true)) {
                    $logger->debug(sprintf('Skip QTSP %s because its name is not in the list.', $providerName));
                    continue;
                }
                if ($config['filter_qtsps'] === true && in_array($providerName, $config['include_qtsp'], true)) {
                    $logger->debug(sprintf('Will include QTSP %s', $providerName));
                }

                $current['name']     = $providerName;
                $current['services'] = getServices($countryCode, $providerName, $provider);

                // store in array:
                $providers[] = $current;
            }
        }
    }

    return $providers;
}

/**
 * @param string $countryCode
 * @param string $providerName
 * @param array $provider
 * @return array
 */
function getServices(string $countryCode, string $providerName, array $provider): array
{
    global $logger, $config;
    $services = [];
    $logger->debug(sprintf('Now downloading services for %s', $providerName));
    // loop provider services:
    foreach ($provider['TSPServices'][0]['TSPService'] as $service) {
        $services++;
        // some properties if the service:
        $serviceType  = $service['ServiceInformation'][0]['ServiceTypeIdentifier'][0];
        $serviceName  = $service['ServiceInformation'][0]['ServiceName'][0]['Name'][0];
        $serviceState = $service['ServiceInformation'][0]['ServiceStatus'][0];

        $serviceName = cleanupServiceName($serviceName);

        // filter on provider service type
        if ($config['filter_types'] === true && !in_array($serviceType, $config['include_types'], true)) {
            $logger->debug(sprintf('Provider "%s" of type "%s" will be ignored.', $providerName, translateType($serviceType)));
            continue;
        }

        // filter on provider service state:
        if ($config['filter_statuses'] === true && !in_array($serviceState, $config['include_statuses'], true)) {
            $logger->debug(sprintf('Provider "%s" with state "%s" will be ignored.', $providerName, translateState($serviceState)));
            continue;
        }

        $current = [
            'type'      => $serviceType,
            'name'      => $serviceName,
            'state'     => $serviceState,
            'abilities' => getAbilities($service),
        ];

        // filter on abilities.
        if ($config['filter_abilities'] === true && !compareArray($current['abilities'], $config['include_abilities'])) {
            // not included.
            $logger->debug(sprintf('"%s" service "%s" is not a QWAC, so it will be ignored.', $providerName, $serviceName));
            continue;
        }

        $logger->debug(sprintf('"%s" service "%s will be included.', $providerName, $serviceName));

        // get the certificates:
        $current['certificates'] = getCertificates($countryCode, $providerName, $serviceName, $service);


        // put in array
        $services[] = $current;
    }

    return $services;
}

function cleanupServiceName(string $name): string
{
    $return = $name;
    if (0 === strpos($name, 'CN=')) {
        // explode in parts:
        $parts = explode(', ', $name);
        foreach ($parts as $part) {
            $moreParts = explode('=', $part);
            if ('CN' === $moreParts[0]) {
                $return = $moreParts[1];
            }
        }
    }

    return $return;
}


/**
 * Download certificate and root certificates if possible.
 *
 * @param string $countryCode
 * @param string $providerName
 * @param string $serviceName
 * @param array $service
 * @return array
 */
function getCertificates(string $countryCode, string $providerName, string $serviceName, array $service): array
{
    global $config;
    $return = [];
    global $logger;
    $identities = $service['ServiceInformation'][0]['ServiceDigitalIdentity'][0]['DigitalId'] ?? [];
    $loop       = 0;
    foreach ($identities as $index => $identity) {
        $loop++;
        foreach (array_keys($identity) as $key) {
            switch ($key) {
                default:
                    $logger->debug(sprintf('Current index is %s', $key));
                    break;
                case 'X509Certificate':
                    $logger->debug('Will extract certificate.');
                    $certificate = extractCertificate($countryCode, $providerName, $serviceName, $loop, $identity[$key][0]);
                    $result      = inspectCertificate($certificate);
                    // not filtering and not expired.
                    if (true === $result['expired'] && true === $config['filter_expired_certificates']) {
                        break;
                    }
                    $return[] = $result;


                    break;
            }
        }
    }

    return $return;
}

/**
 * @param string $fileName
 * @return array
 */
function inspectCertificate(string $fileName): array
{
    global $config;

    $content = file_get_contents($fileName);
    $return  = [
        'file_name'           => $fileName,
        'expired'             => false,
        'certificate-content' => getCertificateChain('', $fileName),
    ];
    $cert    = openssl_x509_parse($content);
    $key     = openssl_pkey_get_public($content);;
    if (false === $key || null === $cert['signatureTypeLN'] || null === $cert['signatureTypeSN']) {
        // probably a bad cert, decide not to include it.
        $return['pub_key_algo']   = 'unknown';
        $return['signature_algo'] = 'unknown';

    }
    else {
        $res                            = openssl_pkey_get_details($key);
        $return['pub_key_algo']         = translateAlgorithm($cert['signatureTypeLN'] ?? '', $res['bits']);
        $return['signature_algo']       = translateSig($cert['signatureTypeSN']);
        $return['serial-number']        = $cert['serialNumberHex'];
        $return['CRL-refresh-seconds']  = '';
        $return['OCSP']                 = '';
        $return['OCSP-refresh-seconds'] = '';
        $return['link-to-certificate']  = '';
        $return['valid-from']           = date('Y-m-d', $cert['validFrom_time_t']);
        $return['valid-until']          = date('Y-m-d', $cert['validTo_time_t']);
    }
    if (false === $cert) {
        return $return;
    }

    $return['crl'] = [];
    if (isset($cert['extensions']['crlDistributionPoints'])) {
        $return['crl'] = patchCRL($cert['extensions']['crlDistributionPoints']);
    }

    $subject     = getCertificateSubject($cert);
    $currentTime = time();
    $expireTime  = (int)$cert['validTo_time_t'];
    if ($expireTime <= $currentTime) {
        $return['expired'] = true;
    }
    $return['subject'] = $subject;
    // extract and save root certificates:
    //$root = getParentCertificate($cert);

    return $return;
}

/**
 * Download the current certificate, and place it in front of the PEM.
 *
 * Then inspect the certificate and search for root certificates. If they are present, submit the current PEM with the URL of the root.
 * This should repear the process.
 *
 * @param string $pem
 * @param string $url
 * @return string
 */
function getCertificateChain(string $pem, string $url): string
{
    global $debug;
    $debug->debug(sprintf('Now in getCertificateChain(%s)', $url));
    if ('' === $url) {
        return $pem;
    }
    $result  = '';
    $content = file_get_contents($url);
    // TODO convert to base64?

    // insert into result:
    $result = $content . "\n" . $pem;

    $cert = openssl_x509_parse($content);
    if (false !== $cert) {
        $root   = getParentCertificate($cert);
        $result = getCertificateChain($result, $root);
    }
    $debug->debug('Done with getCertificateChain!');

    return $result;
}


/**
 * @param array $cert
 */
function getParentCertificate(array $cert): string
{
    $return = '';
    global $debug;
    if (isset($cert['extensions']['authorityInfoAccess'])) {
        $authInfo = $cert['extensions']['authorityInfoAccess'];
        $strPos   = strpos($authInfo, 'CA Issuers - URI:');
        //echo $authInfo;
        if (false !== $strPos) {
            $urls = explode("\n", $authInfo);
            foreach ($urls as $url) {
                if ('' !== trim($url)) {
                    $strPos2 = strpos($url, 'CA Issuers - URI:');
                    if (false !== $strPos2) {
                        $return = trim(str_replace('CA Issuers - URI:', '', $url));
                        //$debug->debug(sprintf('Root is: "%s"', $url));
                        // download this root certificate into a temp file.
                        // then inspect it for further roots?
                    }
                }
            }

        }
    }

    return $return;
}


/**
 * @param string $countryCode
 * @param string $providerName
 * @param string $serviceName
 * @param int $index
 * @param string $certificateData
 * @return string
 */
function extractCertificate(string $countryCode, string $providerName, string $serviceName, int $index, string $certificateData): string
{
    $certContent = '-----BEGIN CERTIFICATE-----' . "\n";
    $certContent .= chunk_split(trim($certificateData), 64, "\n");
    $certContent .= '-----END CERTIFICATE-----' . "\n";

    $fileName = sprintf('%s - %d - %s - %s.pem', $countryCode, $index, $providerName, $serviceName);
    $fileName = str_replace(['(', ')', '/'], '', $fileName);
    $fileName = sprintf('./temp/%s', $fileName);

    // store file:
    file_put_contents($fileName, $certContent);

    return $fileName;
}

/**
 * @param $abilities
 * @param $allowed
 * @return bool
 */
function compareArray($abilities, $allowed)
{
    $result = false;
    foreach ($allowed as $allowedRole) {
        foreach ($abilities as $ability) {
            if ($ability === $allowedRole) {
                $result = true;
            }
        }
    }

    return $result;
}


/**
 * @param array $service
 * @return array
 */
function getAbilities(array $service): array
{
    global $logger;
    $logger->debug('Now listing the abilities of the provider.');
    $return = [];
    if (!isset($service['ServiceInformation'][0]['ServiceInformationExtensions'][0]['Extension'])) {
        return [];
    }
    // loop:
    foreach ($service['ServiceInformation'][0]['ServiceInformationExtensions'][0]['Extension'] as $currentExt) {
        if (isset($currentExt['AdditionalServiceInformation'])) {
            $infoExtName = $currentExt['AdditionalServiceInformation'][0]['URI'][0];
            $return[]    = $infoExtName;
        }
    }

    return $return;
}


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
 * @param int $bits
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
        }
        else {
            $a[$sxi->key()][] = (string)$sxi->current();
        }
    }

    return $a;
}

/**
 * @param array $certificate
 * @return string
 */
function getCertificateSubject(array $certificate): string
{
    global $debug;

    $subject = $certificate['subject']['CN'] ?? '';
    if ('' === $subject) {
        $debug->info('Subject: ', $certificate['subject']);
    }

    //$debug->debug(sprintf('Subject is "%s"', $subject));

    return $subject;
}

function debugMessage(string $message): void
{
    global $debug;
    $debug->debug($message);
}


/**
 * Get nice URL's from CRL.
 *
 * @param string $string
 * @return array
 */
function patchCRL(string $string): array
{
    global $debug;
    $search  = ['Full Name:', 'URI:'];
    $replace = '';
    $return  = [];

    $string = str_replace($search, $replace, $string);
    $string = trim($string);

    // may have multiple CRL's in the certificate.
    if (false === strpos($string, ' ')) {
        $return[] = $string;

        //$debug->info(sprintf('CRL is "%s"', $string));

        return $return;
    }
    $parts = explode(' ', $string);
    foreach ($parts as $part) {
        $temp = trim($part);
        if ('' !== $temp) {
            $return[] = $temp;
        }
    }

    //$debug->info('CRL string', $return);

    return $return;
}