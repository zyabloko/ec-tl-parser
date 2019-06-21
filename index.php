<?php

require 'vendor/autoload.php';
require 'functions.php';
require 'config.php';


use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

// some debug info:
if ($config['filter_countries'] === true) {
    debugMessage(sprintf('Will only include these countries: %s', implode(', ', $config['include_countries'])));
}
if ($config['filter_countries'] === true) {
    debugMessage('Will include ALL countries.');
}


$countries = getCountries();

// store all PEM files:
foreach ($countries as $country) {
    foreach ($country['providers'] as $provider) {
        foreach ($provider['services'] as $service) {
            $count = count($service['certificates']);
            foreach ($service['certificates'] as $index => $certificate) {
                $fileName = sprintf('./certificates/%s - %s - %s.pem', $country['code'], $provider['name'], $service['name']);
                if ($count > 0) {
                    $fileName = sprintf('./certificates/%s - %s - %s - %d.pem', $country['code'], $provider['name'], $service['name'], $index);
                }
                file_put_contents($fileName, $certificate['certificate-content']);
            }
        }
    }
}

//$logger->debug(sprintf('Country data: %s', json_encode($countries)));


exit;
// get country list
$uri    = 'https://webgate.ec.europa.eu/tl-browser/api/home';
$client = new Client($config['guzzle']);

try {
    $request = $client->request('GET', $uri);
} catch (GuzzleException $e) {
    die($e->getMessage());
}

$result = json_decode($request->getBody()->getContents(), true);
debugMessage('Counted ' . count($result['content']['tls']) . ' countries.');

$debugInfo = '';
// looping each one.
$index = 0;
foreach ($result['content']['tls'] as $country) {
    //    if ($index > 9) {
    //        debugMessage('End of script');
    //        break;
    //    }

    $key      = $country['territoryCode'];
    $fullName = $country['countryName'];

    if (!in_array($key, $config['include_countries'], true) && $config['filter_countries'] === true) {
        debugMessage(sprintf('Skip %s (%s)...', $fullName, $key));
        continue;
    }
    sleep(1);

    // getting providers for this country.
    $countryUri = sprintf('https://webgate.ec.europa.eu/tl-browser/api/download/%s', $key);
    $client     = new Client(['proxy' => 'nl-userproxy-access.net.abnamro.com:8080']);
    debugMessage(sprintf('Downloading for %s (%s)', $fullName, $countryUri));
    try {
        $countryRequest = $client->request('GET', $countryUri);
    } catch (GuzzleException $e) {
        die($e->getMessage());
    }
    //debugMessage('Got everything!');
    $countryData = json_decode($countryRequest->getBody()->getContents(), true);
    $xml         = base64_decode($countryData['content']);
    $array       = xml2array($xml);
    $providers   = 0;
    $services    = 0;
    // loop each provider from this country:
    if (!isset($array['TrustServiceProviderList'])) {
        debugMessage(sprintf('No services listed for %s', $fullName));
        continue;
    }


    foreach ($array['TrustServiceProviderList'] as $list) {
        foreach ($list as $xList) {
            foreach ($xList as $provider) {
                $providers++;
                // name of the provider
                $name = $provider['TSPInformation'][0]['TSPName'][0]['Name'][0];

                // filter provider name:
                if ($config['filter_qtsps'] === true && !in_array($name, $config['include_qtsp'], true)) {
                    //debugMessage(sprintf('Skip QTSP %s', $name));
                    continue;
                }
                else {
                    // debugMessage(sprintf('Include QTSP %s', $name));
                }

                //debugMessage($name);
                // loop provider services:
                foreach ($provider['TSPServices'][0]['TSPService'] as $service) {
                    $services++;
                    // some properties if the service:
                    $serviceType  = $service['ServiceInformation'][0]['ServiceTypeIdentifier'][0];
                    $serviceName  = $service['ServiceInformation'][0]['ServiceName'][0]['Name'][0];
                    $serviceState = $service['ServiceInformation'][0]['ServiceStatus'][0];

                    // need to know additional info:
                    $serviceAdditional = null;


                    if (!in_array($serviceType, $config['include_types'], true)) {
                        //debugMessage(sprintf('  Type "%s" will be ignored.', translateType($serviceType)));
                        continue;
                    }
                    if (!in_array($serviceState, $config['include_statuses'], true)) {
                        //debugMessage(sprintf('  State "%s" will be ignored.', $serviceState));
                        continue;
                    }
                    //debugMessage(sprintf('  Include service %s', $serviceName));
                    //$debugInfo .= sprintf("\n\n\nService: %s\n", $serviceName);
                    //$debugInfo .= print_r(array_keys($service['ServiceInformation'][0]['ServiceInformationExtensions'][0]['Extension']), true);
                    //$debugInfo .= print_r($service['ServiceInformation'][0]['ServiceInformationExtensions'][0]['Extension'], true);

                    //

                    if ($config['service_filter'] === true) {
                        $infoExtensions = [];
                        foreach ($service['ServiceInformation'][0]['ServiceInformationExtensions'][0]['Extension'] as $currentExt) {
                            if (isset($currentExt['AdditionalServiceInformation'])) {
                                $infoExtName      = $currentExt['AdditionalServiceInformation'][0]['URI'][0];
                                $infoExtensions[] = $infoExtName;
                            }
                        }
                        // search for extension (double array search):
                        $foundExt = false;
                        foreach ($infoExtensions as $infoExt) {
                            if (in_array($infoExt, $config['service_info'], true)) {
                                $foundExt = true;
                            }
                        }
                        if (false === $foundExt) {
                            //debugMessage(sprintf('  %s is not QWAC', $serviceName));
                            continue;
                        }
                        debugMessage(sprintf('  %s is a QWAC provider', $serviceName));
                    }


                    $translatedType  = translateType($serviceType);
                    $translatedState = translateState($serviceState);

                    // could have no certificate info.
                    if (isset($service['ServiceInformation'][0]['ServiceDigitalIdentity'][0]['DigitalId'][0]['X509Certificate'])) {
                        // try to read certificate.
                        $certificate = $service['ServiceInformation'][0]['ServiceDigitalIdentity'][0]['DigitalId'][0]['X509Certificate'][0];
                        $fullCert    = '-----BEGIN CERTIFICATE-----' . "\n";
                        $fullCert    .= chunk_split(trim($certificate), 64, "\n");
                        $fullCert    .= '-----END CERTIFICATE-----' . "\n";


                        $cert = openssl_x509_parse($fullCert);
                        $key  = openssl_pkey_get_public($fullCert);
                        if (false === $key || null === $cert['signatureTypeLN'] || null === $cert['signatureTypeSN']) {
                            // probably a bad cert, decide not to include it.
                            $pubKeyAlgo = 'unknown';
                            $sigAlgo    = 'unknown';
                        }
                        else {
                            $res        = openssl_pkey_get_details($key);
                            $pubKeyAlgo = translateAlgorithm($cert['signatureTypeLN'] ?? '', $res['bits']);
                            $sigAlgo    = translateSig($cert['signatureTypeSN']);

                            //var_dump($cert['extensions']['authorityInfoAccess']);
                        }
                    }
                    else {
                        $cert = false;
                    }


                    if (false !== $cert) {
                        $crl = '';
                        if (isset($cert['extensions']['crlDistributionPoints'])) {
                            $crl = patchXCRL($cert['extensions']['crlDistributionPoints']);
                        }
                        $subject = $cert['subject']['CN'] ?? false;
                        if (false === $subject) {
                            $subject = joinSubject($cert['subject']);
                        }
                        $currentTime = time();
                        if ((int)$cert['validTo_time_t'] > $currentTime) {
                            $root      = [
                                'group'                => $translatedType,
                                'environment'          => 'pr',
                                'country'              => $fullName,
                                'title'                => $serviceName,
                                'commonName'           => $subject,
                                'valid-from'           => date('Y-m-d', $cert['validFrom_time_t']),
                                'valid-until'          => date('Y-m-d', $cert['validTo_time_t']),
                                'algorithm-signature'  => $sigAlgo,
                                'algorithm-pubkey'     => $pubKeyAlgo,
                                'serial-number'        => $cert['serialNumberHex'],
                                'CRL'                  => $crl,
                                'CRL-refresh-seconds'  => '',
                                'OCSP'                 => '',
                                'OCSP-refresh-seconds' => '',
                                'link-to-certificate'  => '',
                                'certificate-content'  => $certificate,
                                'description'          => $serviceName,
                                'OAR-id'               => '',
                                'contact-details'      => 'crypto.services@nl.abnamro.com',
                                'state'                => $translatedState,
                                'info-complete'        => 'TRUE',
                            ];
                            $roots[]   = $root;
                            $rootCount = count($roots);
                            // write to file:
                            $fileName = $rootCount . ' - ' . str_replace(['/'], '_', $subject) . '.cer';

                            $fullCert = '-----BEGIN CERTIFICATE-----' . "\n" . wordwrap($certificate, 64, "\n", true) . "\n-----END CERTIFICATE-----";

                            file_put_contents('./certificates/' . $fileName, $fullCert);

                            if (isset($cert['extensions']['authorityInfoAccess'])) {
                                // get and dump root certificate.
                                //var_dump($cert['extensions']['authorityInfoAccess']);
                                downloadRoot($rootCount . ' - ' . str_replace(['/'], '_', $subject) . ' ROOT.cer', $cert['extensions']['authorityInfoAccess']);
                            }
                        }
                    }

                }

            }
        }
    }

    //debugMessage(sprintf('%d providers and %d services', $providers, $services));
    $index++;
}
//file_put_contents('debug.txt', $debugInfo);

$fp = fopen('output.csv', 'wb');

if (isset($roots[0])) {
    fputcsv($fp, array_keys($roots[0]), ';');

}
foreach ($roots as $fields) {
    fputcsv($fp, $fields, ';');
}

fclose($fp);


function downloadRoot($fileName, $rootUri)
{
    $parts = explode("\n", $rootUri);

    foreach ($parts as $current) {
        if (0 === strpos($current, 'CA Issuers - URI:')) {
            $url    = str_replace('CA Issuers - URI:', '', $current);
            $client = new Client(['proxy' => 'nl-userproxy-access.net.abnamro.com:8080']);
            try {
                $request = $client->request('GET', $url);
            } catch (GuzzleException $e) {
                debugMessage(sprintf('URL is "%s"', $url));

                //debugMessage($e->getMessage());
                return;
            }
            $content = $request->getBody()->getContents();
            file_put_contents('./certificates/' . $fileName, $content);
        }
    }
}

