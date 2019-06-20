<?php


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
	foreach($types as $key => $type) {
	echo '| ' . ucfirst($type) .' | See for more information, [this page]('.$key.'). | '."\n";
	}