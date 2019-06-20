<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**

Download all country names. ['NL', 'BE', ...]
*/
function getCountries($config) {
	debugMessage('Start of getCountries()');
	// get country list
	$uri    = 'https://webgate.ec.europa.eu/tl-browser/api/home';
	$client = new Client($config['guzzle']);
	$countries = [];

	try {
		$request = $client->request('GET', $uri);
	} catch (GuzzleException $e) {
		debugMessage($e->getMessage());
		die($e->getMessage());
	}
	
	$result = json_decode($request->getBody()->getContents(), true);
	debugMessage('Counted ' . count($result['content']['tls']) . ' countries.');

	// looping each country:
	foreach ($result['content']['tls'] as $country) {
		$key      = $country['territoryCode'];
		$fullName = $country['countryName'];
		
		// filter countries:
		if (!in_array($key, $config['include_countries'], true) && $config['filter_countries'] === true) {
			debugMessage(sprintf('Skip %s (%s)...', $fullName, $key));
			continue;
		}
		
		debugMessage(sprintf('Will include %s (%s)', $fullName, $key));
		$countries[$key] = $fullName;
	}
	
	debugMessage('End of getCountries()');
	return $countries;
}

function getProviders($config, $countryCode, $countryName) {
	$providers = [];
    // getting providers for this country.
    $countryUri = sprintf('https://webgate.ec.europa.eu/tl-browser/api/download/%s', $countryCode);
    $client = new Client($config['guzzle']);
    debugMessage(sprintf('Downloading for %s (%s)', $countryName, $countryUri));
	
    try {
        $countryRequest = $client->request('GET', $countryUri);
    } catch (GuzzleException $e) {
        die($e->getMessage());
    }
	
    debugMessage('Got everything!');
    $countryData = json_decode($countryRequest->getBody()->getContents(), true);
    $xml         = base64_decode($countryData['content']);
    $array       = xml2array($xml);
    // loop each provider from this country:
    if (!isset($array['TrustServiceProviderList'])) {
        debugMessage(sprintf('No services listed for %s', $countryName));
        return [];
    }
	
    foreach ($array['TrustServiceProviderList'] as $list) {
        foreach ($list as $xList) {
            foreach ($xList as $provider) {
				$current= [];
                // name of the provider
                $providerName = $provider['TSPInformation'][0]['TSPName'][0]['Name'][0];
				
				// include it?
				if($config['filter_qtsps'] === true && !in_array($providerName, $config['include_qtsp'], true)) {
					//debugMessage(sprintf('Skip QTSP %s', $providerName));
					continue;
				}
				if($config['filter_qtsps'] === true && in_array($providerName, $config['include_qtsp'], true)) {
					//debugMessage(sprintf('Include QTSP %s', $providerName));
				}
				
				
				$current['name'] = $providerName;
				$current['services'] = getServices($config, $countryCode, $provider);
				
				// store in array:
				$providers[] = $current;
			}
		}
	}
	return $providers;
}

function getServices($config, $countryCode, $provider) {
	$services = [];
	//debugMessage($name);
	// loop provider services:
	foreach ($provider['TSPServices'][0]['TSPService'] as $service) {
		$services++;
		// some properties if the service:
		$serviceType  = $service['ServiceInformation'][0]['ServiceTypeIdentifier'][0];
		$serviceName  = $service['ServiceInformation'][0]['ServiceName'][0]['Name'][0];
		$serviceState = $service['ServiceInformation'][0]['ServiceStatus'][0];
		
		// filter on provider service type
		if ($config['filter_types'] === true && !in_array($serviceType, $config['include_types'], true)) {
			//debugMessage(sprintf('  Type "%s" will be ignored.', translateType($serviceType)));
			continue;
		}
		
		// filter on provider service state:
		if($config['filter_statuses'] === true && !in_array($serviceState, $config['include_statuses'], true)) {
			//debugMessage(sprintf('  State "%s" will be ignored.', $serviceState));
			continue;
		}
		
		$current = [
			'type' => $serviceType,
			'name' => $serviceName,
			'state' => $serviceState,
			'abilities' => getAbilities($config, $service),
		];
		
		// filter on abilities.
		if($config['filter_abilities'] === true && !compareArray($current['abilities'], $config['include_abilities'])) {
			// not included.
			//debugMessage('  Is not a QWAC');
			continue;
		}
		// get the certificates:
		getCertificates($countryCode, $current, $service);
		
		
		// put in array
		$services[] = $current;
	}
	return $services;
}

function getCertificates($countryCode, $provider, $service) {
	$identities = [];
	if(isset($service['ServiceInformation'][0]['ServiceDigitalIdentity'][0]['DigitalId'])) {
		$identities = $service['ServiceInformation'][0]['ServiceDigitalIdentity'][0]['DigitalId'];
	}
	$loop = 0;
	foreach($identities as $index => $identity) {
		$loop++;
		foreach(array_keys($identity) as $key) {
			switch($key) {
				default:
					//debugMessage(sprintf('Current index is %s', $key));
					break;
				case 'X509Certificate':
					extractCertificate($countryCode, $provider, $loop, $identity[$key][0]);
				break;
			}
		}
	}
}

function extractCertificate($countryCode, $provider, $index, $certificateData) {
	$certContent    = '-----BEGIN CERTIFICATE-----' . "\n";
	$certContent    .= chunk_split(trim($certificateData), 64, "\n");
	$certContent    .= '-----END CERTIFICATE-----' . "\n";
	
	$fileName = sprintf('%s - %d - %s.cer', $countryCode, $index, $provider['name']);
	$fileName = str_replace(['(',')','/'],'', $fileName);
	$fileName = './certificates/'.$fileName;
	// store file somewhere.
	file_put_contents($fileName, $certContent);
}



function compareArray($abilities, $allowed) {
	$result = false;
	foreach($allowed as $allowedRole) {
		foreach($abilities as $ability) {
			if($ability === $allowedRole) {
				$result= true;
			}
		}
	}
	return $result;
}

function getAbilities($config, $service) {
	$return = [];
	if(!isset($service['ServiceInformation'][0]['ServiceInformationExtensions'][0]['Extension'])) {
		return [];
	}
	// loop:
	$extensions = [];
	foreach($service['ServiceInformation'][0]['ServiceInformationExtensions'][0]['Extension'] as $currentExt) {
		if(isset($currentExt['AdditionalServiceInformation'])) {
			$infoExtName= $currentExt['AdditionalServiceInformation'][0]['URI'][0];
			$return[] = $infoExtName;
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