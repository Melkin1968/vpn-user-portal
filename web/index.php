<?php

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2019, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

require_once dirname(__DIR__).'/vendor/autoload.php';
$baseDir = dirname(__DIR__);

use fkooman\Jwt\Keys\EdDSA\SecretKey;
use fkooman\OAuth\Server\OAuthServer;
use fkooman\SeCookie\Cookie;
use fkooman\SeCookie\CookieOptions;
use fkooman\SeCookie\Session;
use fkooman\SeCookie\SessionOptions;
use LC\Common\Config;
use LC\Common\FileIO;
use LC\Common\Http\CsrfProtectionHook;
use LC\Common\Http\HtmlResponse;
use LC\Common\Http\InputValidation;
use LC\Common\Http\LanguageSwitcherHook;
use LC\Common\Http\Request;
use LC\Common\Http\Service;
use LC\Common\Http\StaticPermissions;
use LC\Common\Http\TwoFactorHook;
use LC\Common\Http\TwoFactorModule;
use LC\Common\HttpClient\CurlHttpClient;
use LC\Common\HttpClient\ServerClient;
use LC\Common\Logger;
use LC\Portal\AccessHook;
use LC\Portal\AdminHook;
use LC\Portal\AdminPortalModule;
use LC\Portal\ClientFetcher;
use LC\Portal\DisabledUserHook;
use LC\Portal\FormLdapAuthentication;
use LC\Portal\FormPdoAuthentication;
use LC\Portal\FormRadiusAuthentication;
use LC\Portal\LogoutModule;
use LC\Portal\MellonAuthentication;
use LC\Portal\OAuth\PublicSigner;
use LC\Portal\OAuthModule;
use LC\Portal\PhpSamlSpAuthentication;
use LC\Portal\SeCookie;
use LC\Portal\SeSession;
use LC\Portal\ShibAuthentication;
use LC\Portal\Storage;
use LC\Portal\Tpl;
use LC\Portal\TwoFactorEnrollModule;
use LC\Portal\UpdateSessionInfoHook;
use LC\Portal\VpnPortalModule;

$logger = new Logger('vpn-user-portal');

try {
    $request = new Request($_SERVER, $_GET, $_POST);

    $dataDir = sprintf('%s/data', $baseDir);
    FileIO::createDir($dataDir, 0700);

    $config = Config::fromFile(sprintf('%s/config/config.php', $baseDir));
    $templateDirs = [
        sprintf('%s/views', $baseDir),
        sprintf('%s/config/views', $baseDir),
    ];
    $localeDirs = [
        sprintf('%s/locale', $baseDir),
        sprintf('%s/config/locale', $baseDir),
    ];
    if ($config->hasItem('styleName')) {
        $styleName = $config->getItem('styleName');
        $templateDirs[] = sprintf('%s/views/%s', $baseDir, $styleName);
        $templateDirs[] = sprintf('%s/config/views/%s', $baseDir, $styleName);
        $localeDirs[] = sprintf('%s/locale/%s', $baseDir, $styleName);
        $localeDirs[] = sprintf('%s/config/locale/%s', $baseDir, $styleName);
    }

    $sessionExpiry = $config->getItem('sessionExpiry');

    // we always want browser session to expiry after PT8H hours, *EXCEPT* when
    // the configured "sessionExpiry" is < PT8H, then we want to follow that
    // setting...
    $sessionOptions = SessionOptions::init();
    $dateTime = new DateTime();
    if (date_add(clone $dateTime, new DateInterval('PT30M')) > date_add(clone $dateTime, new DateInterval($sessionExpiry))) {
        $sessionOptions = SessionOptions::init()->withExpiresIn(new DateInterval($sessionExpiry));
    }

    $secureCookie = $config->hasItem('secureCookie') ? $config->getItem('secureCookie') : true;
    $cookieOptions = $secureCookie ? CookieOptions::init() : CookieOptions::init()->withoutSecure();
    $seCookie = new SeCookie(
        new Cookie(
            $cookieOptions
                ->withSameSiteLax()
                ->withMaxAge(60 * 60 * 24 * 90)  // 90 days
        )
    );
    $seSession = new SeSession(
        new Session(
            $sessionOptions,
            $cookieOptions
                ->withPath($request->getRoot())
                ->withSameSiteLax()
        )
    );

    $supportedLanguages = $config->getSection('supportedLanguages')->toArray();
    // the first listed language is the default language
    $uiLang = array_keys($supportedLanguages)[0];
    if (null !== $cookieUiLang = $seCookie->get('ui_lang')) {
        $uiLang = InputValidation::uiLang($cookieUiLang);
    }
    $cssRoot = sprintf('%s/web/css', $baseDir);
    $tpl = new Tpl($templateDirs, $localeDirs, $cssRoot);
    $tpl->setLanguage($uiLang);
    $tpl->addDefault(
        [
            'requestUri' => $request->getUri(),
            'requestRoot' => $request->getRoot(),
            'requestRootUri' => $request->getRootUri(),
            'supportedLanguages' => $supportedLanguages,
            '_show_logout_button' => true,
            'uiLang' => $uiLang,
            'useRtl' => 0 === strpos($uiLang, 'ar_') || 0 === strpos($uiLang, 'fa_') || 0 === strpos($uiLang, 'he_'),
        ]
    );

    $serverClient = new ServerClient(
        new CurlHttpClient([$config->getItem('apiUser'), $config->getItem('apiPass')]),
        $config->getItem('apiUri')
    );

    $service = new Service($tpl);
    $service->addBeforeHook('csrf_protection', new CsrfProtectionHook());
    $service->addBeforeHook('language_switcher', new LanguageSwitcherHook(array_keys($supportedLanguages), $seCookie));

    // Authentication
    $authMethod = $config->getItem('authMethod');

    $logoutUrl = null;
    $returnParameter = 'ReturnTo';
    if ('PhpSamlSpAuthentication' === $authMethod) {
        $logoutUrl = $request->getScheme().'://'.$request->getAuthority().'/php-saml-sp/logout';
    }
    if ('MellonAuthentication' === $authMethod) {
        $logoutUrl = $request->getScheme().'://'.$request->getAuthority().'/saml/logout';
    }
    if ('ShibAuthentication' === $authMethod) {
        $logoutUrl = $request->getScheme().'://'.$request->getAuthority().'/Shibboleth.sso/Logout';
        $returnParameter = 'return';
    }

    // StaticPermissions for the local authentiction mechanisms
    // (PDO, LDAP, RADIUS)
    $staticPermissions = null;
    $staticPermissionsFile = sprintf('%s/config/static_permissions.json', $baseDir);
    if (FileIO::exists($staticPermissionsFile)) {
        $staticPermissions = new StaticPermissions($staticPermissionsFile);
    }

    $storage = new Storage(
        new PDO(sprintf('sqlite://%s/db.sqlite', $dataDir)),
        sprintf('%s/schema', $baseDir),
        new DateInterval($sessionExpiry)
    );
    $storage->update();

    $service->addModule(new LogoutModule($seSession, $logoutUrl, $returnParameter));
    switch ($authMethod) {
        case 'PhpSamlSpAuthentication':
            $phpSamlSpAuthentication = new PhpSamlSpAuthentication(
                $config->getSection('PhpSamlSpAuthentication')
            );
            $service->addBeforeHook('auth', $phpSamlSpAuthentication);
            break;
        case 'MellonAuthentication':
            $service->addBeforeHook('auth', new MellonAuthentication($config->getSection('MellonAuthentication')));
            break;
        case 'ShibAuthentication':
            $service->addBeforeHook('auth', new ShibAuthentication($config->getSection('ShibAuthentication')));
            break;
        case 'FormLdapAuthentication':
            $formLdapAuthentication = new FormLdapAuthentication(
                $config->getSection('FormLdapAuthentication'),
                $seSession,
                $tpl,
                $logger
            );
            if (null !== $staticPermissions) {
                $formLdapAuthentication->setStaticPermissions($staticPermissions);
            }
            $service->addBeforeHook('auth', $formLdapAuthentication);
            $service->addModule($formLdapAuthentication);
            break;
        case 'FormRadiusAuthentication':
            $formRadiusAuthentication = new FormRadiusAuthentication(
                $config->getSection('FormRadiusAuthentication'),
                $seSession,
                $tpl,
                $logger
            );
            if (null !== $staticPermissions) {
                $formRadiusAuthentication->setStaticPermissions($staticPermissions);
            }
            $service->addBeforeHook('auth', $formRadiusAuthentication);
            $service->addModule($formRadiusAuthentication);
            break;
        case 'FormPdoAuthentication':
            $formPdoAuthentication = new FormPdoAuthentication(
                new Config([]),
                $seSession,
                $tpl,
                $storage
            );
            if (null !== $staticPermissions) {
                $formPdoAuthentication->setStaticPermissions($staticPermissions);
            }
            $service->addBeforeHook('auth', $formPdoAuthentication);
            $service->addModule($formPdoAuthentication);
            break;
         default:
            // try to dynamically load the authentication mechanism
            $authClass = sprintf('LC\Portal\%s', $authMethod);
            if (!class_exists($authClass)) {
                throw new RuntimeException('unsupported authentication mechanism');
            }
            $userAuth = new $authClass(
                // we make the rootUri and baseDir part of the configuration,
                // agreed, it is a bit hacky, but avoids needing to manually
                // specify the URL on which the service is configured...
                $config->getSection($authMethod)
                    ->setItem('_rootUri', $request->getRootUri())
                    ->setItem('_baseDir', $baseDir),
                $seSession,
                $tpl
            );

            $implementedInterfaces = class_implements($userAuth);
            if (!in_array('LC\Common\Http\BeforeHookInterface', $implementedInterfaces, true)) {
                throw new RuntimeException('authentication class MUST implement "LC\Common\Http\BeforeHookInterface"');
            }
            $service->addBeforeHook('auth', $userAuth);
            // optional "ServiceModuleInterface"
            if (in_array('LC\Common\Http\ServiceModuleInterface', $implementedInterfaces, true)) {
                $service->addModule($userAuth);
            }
    }

    $tpl->addDefault(
        [
            'allowPasswordChange' => 'FormPdoAuthentication' === $authMethod,
        ]
    );

    if (null !== $accessPermissionList = $config->optionalItem('accessPermissionList')) {
        // hasAccess
        $service->addBeforeHook(
            'has_access',
            new AccessHook(
                $accessPermissionList
            )
        );
    }

    $twoFactorMethods = $config->optionalItem('twoFactorMethods', ['totp']);
    if (0 !== count($twoFactorMethods)) {
        $service->addBeforeHook(
            'two_factor',
            new TwoFactorHook(
                $seSession,
                $tpl,
                $serverClient,
                $config->hasItem('requireTwoFactor') ? $config->getItem('requireTwoFactor') : false
            )
        );
    }

    $service->addBeforeHook('disabled_user', new DisabledUserHook($serverClient));
    $service->addBeforeHook('update_session_info', new UpdateSessionInfoHook($seSession, $serverClient, new DateInterval($sessionExpiry)));

    // two factor module
    if (0 !== count($twoFactorMethods)) {
        $twoFactorModule = new TwoFactorModule($serverClient, $seSession, $tpl);
        $service->addModule($twoFactorModule);
    }

    // isAdmin
    $service->addBeforeHook(
        'is_admin',
        new AdminHook(
            $config->optionalItem('adminPermissionList', []),
            $config->optionalItem('adminUserIdList', []),
            $tpl
        )
    );

    $clientFetcher = new ClientFetcher($config);

    // portal module
    $vpnPortalModule = new VpnPortalModule(
        $config,
        $tpl,
        $serverClient,
        $seSession,
        $storage,
        $clientFetcher
    );
    $service->addModule($vpnPortalModule);

    $adminPortalModule = new AdminPortalModule(
        $tpl,
        $storage,
        $serverClient
    );
    $service->addModule($adminPortalModule);

    if (0 !== count($twoFactorMethods)) {
        $twoFactorEnrollModule = new TwoFactorEnrollModule($twoFactorMethods, $seSession, $tpl, $serverClient);
        $service->addModule($twoFactorEnrollModule);
    }

    // OAuth module
    $secretKey = SecretKey::fromEncodedString(
        FileIO::readFile(
            sprintf('%s/config/oauth.key', $baseDir)
        )
    );
    if ($config->hasSection('Api')) {
        $oauthServer = new OAuthServer(
            $storage,
            $clientFetcher,
            new PublicSigner($secretKey->getPublicKey(), $secretKey)
        );

        $oauthServer->setAccessTokenExpiry(
            new DateInterval(
                $config->getSection('Api')->hasItem('tokenExpiry') ? sprintf('PT%dS', $config->getSection('Api')->getItem('tokenExpiry')) : 'PT1H'
            )
        );

        $oauthModule = new OAuthModule(
            $tpl,
            $oauthServer
        );
        $service->addModule($oauthModule);
    }

    $service->run($request)->send();
} catch (Exception $e) {
    $logger->error($e->getMessage());
    $response = new HtmlResponse($e->getMessage(), 500);
    $response->send();
}
