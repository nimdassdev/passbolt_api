<?php
declare(strict_types=1);

/**
 * Passbolt ~ Open source password manager for teams
 * Copyright (c) Passbolt SA (https://www.passbolt.com)
 *
 * Licensed under GNU Affero General Public License version 3 of the or any later version.
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Passbolt SA (https://www.passbolt.com)
 * @license       https://opensource.org/licenses/AGPL-3.0 AGPL License
 * @link          https://www.passbolt.com Passbolt(tm)
 * @since         2.0.0
 */
namespace App\Utility;

use App\Model\Entity\Role;
use App\Model\Validation\EmailValidationRule;
use App\Utility\Application\FeaturePluginAwareTrait;
use App\Utility\Filesystem\DirectoryUtility;
use App\Utility\Healthchecks\CoreHealthchecks;
use App\Utility\Healthchecks\DatabaseHealthchecks;
use App\Utility\Healthchecks\GpgHealthchecks;
use App\Utility\Healthchecks\SslHealthchecks;
use Cake\Core\Configure;
use Cake\Core\Exception\CakeException;
use Cake\Http\Client;
use Cake\ORM\TableRegistry;
use Cake\Validation\Validation;
use Passbolt\JwtAuthentication\Service\AccessToken\JwtAbstractService;
use Passbolt\JwtAuthentication\Service\AccessToken\JwtKeyPairService;
use Passbolt\SelfRegistration\Service\Healthcheck\SelfRegistrationHealthcheckService;
use Passbolt\SmtpSettings\Service\SmtpSettingsHealthcheckService;

class Healthchecks
{
    use FeaturePluginAwareTrait;

    /**
     * The minimum PHP version soon required. Healthcheck will warn if not satisfied yet.
     */
    public const PHP_NEXT_MIN_VERSION_CONFIG = 'php.nextMinVersion';

    /**
     * The minimum PHP version required. Healthcheck will fail if not satisfied yet.
     */
    public const PHP_MIN_VERSION_CONFIG = 'php.minVersion';

    /**
     * Run all healthchecks
     *
     * @param ?\Cake\Http\Client $client client used to query the healthcheck endpoint
     * @return array
     */
    public static function all(?Client $client): array
    {
        $checks = [];
        $checks = Healthchecks::environment($checks);
        $checks = Healthchecks::configFiles($checks);
        $checks = (new CoreHealthchecks($client))->all($checks);
        $checks = (new SslHealthchecks($client))->all($checks);
        $checks = Healthchecks::database('default', $checks);
        $checks = Healthchecks::gpg($checks);
        $checks = Healthchecks::application($checks);
        $checks = Healthchecks::smtpSettings($checks);

        return $checks;
    }

    /**
     * Application checks
     * - latestVersion: true if using latest version
     * - schema: schema up to date no need to do a migration
     * - info.remoteVersion
     * - sslForce: enforcing the use of SSL
     * - seleniumDisabled: true if selenium API is disabled
     * - registrationClosed: info on the self registration
     * - jsProd: true if using minified/concatenated javascript
     *
     * @param array|null $checks List of checks
     * @return array
     * @access private
     */
    public static function application(?array $checks = []): array
    {
        try {
            $checks['application']['info']['remoteVersion'] = Migration::getLatestTagName();
            $checks['application']['latestVersion'] = Migration::isLatestVersion();
        } catch (\Exception $e) {
            $checks['application']['info']['remoteVersion'] = 'undefined';
            $checks['application']['latestVersion'] = null;
        }
        try {
            $checks['application']['schema'] = !Migration::needMigration();
        } catch (\Exception $e) {
            // Cannot connect to the database
            $checks['application']['schema'] = false;
        }
        $robots = strpos(Configure::read('passbolt.meta.robots'), 'noindex');
        $checks['application']['robotsIndexDisabled'] = ($robots !== false);
        $checks['application']['sslForce'] = Configure::read('passbolt.ssl.force');
        $https = strpos(Configure::read('App.fullBaseUrl'), 'https') === 0;
        $checks['application']['sslFullBaseUrl'] = ($https !== false);
        $checks['application']['seleniumDisabled'] = !Configure::read('passbolt.selenium.active');
        $checks['application']['registrationClosed'] = (new SelfRegistrationHealthcheckService())->getHealthcheck();
        $checks['application']['hostAvailabilityCheckEnabled'] = Configure::read(EmailValidationRule::MX_CHECK_KEY);
        $checks['application']['jsProd'] = (Configure::read('passbolt.js.build') === 'production');
        $sendEmailJson = json_encode(Configure::read('passbolt.email.send'));
        $checks['application']['emailNotificationEnabled'] = !(preg_match('/false/', $sendEmailJson) === 1);

        $checks = array_merge(Healthchecks::appUser(), $checks);

        return $checks;
    }

    /**
     * Check that users are set in the database
     * - app.adminCount there is at least an admin in the database
     *
     * @param array|null $checks List of checks
     * @return array
     */
    public static function appUser(?array $checks = []): array
    {
        // no point checking for records if can not connect
        $checks = array_merge(Healthchecks::database(), $checks);
        $checks['application']['adminCount'] = false;
        if (!$checks['database']['connect']) {
            return $checks;
        }

        // check number of admin user
        $User = TableRegistry::getTableLocator()->get('Users');
        try {
            $i = $User->find('all')
                ->contain(['Roles'])
                ->where(['Roles.name' => Role::ADMIN])
                ->count();

            $checks['application']['adminCount'] = ($i > 0);
        } catch (CakeException $e) {
        }

        return $checks;
    }

    /**
     * Return config file checks:
     * - configFile.app true if file is present, false otherwise
     *
     * @param array|null $checks List of checks
     * @return array
     */
    public static function configFiles(?array $checks = []): array
    {
        $files = ['app', 'passbolt'];
        foreach ($files as $file) {
            $checks['configFile'][$file] = (file_exists(CONFIG . $file . '.php'));
        }

        return $checks;
    }

    /**
     * Check core file configuration
     * - cache: settings are set
     * - debugDisabled: the core.debug is set to 0
     * - salt: true if non default salt is used
     * - cipherSeed: true if non default cipherSeed is used
     *
     * @param ?\Cake\Http\Client $client Client
     * @param array|null $checks List of checks
     * @return array
     */
    public static function core(?Client $client = null, ?array $checks = []): array
    {
        return (new CoreHealthchecks($client))->all($checks);
    }

    /**
     * Return database checks:
     * - connect: can connect to the database
     * - tablesPrefixes: not using tablesPrefix
     * - tableCount: at least one table is present
     * - info.tableCount: number of tables installed
     * - defaultContent: some default content (4 roles)
     *
     * @param string|null $datasource Datasource name
     * @param array|null $checks List of checks
     * @return array
     */
    public static function database(?string $datasource = 'default', ?array $checks = []): array
    {
        return DatabaseHealthchecks::all($datasource, $checks);
    }

    /**
     * Return core checks:
     * - phpVersion: php version is superior to 7.0
     * - pcre: unicode support
     * - tmpWritable: the TMP directory is writable for the current user
     *
     * @param array|null $checks List of checks
     * @return array
     */
    public static function environment(?array $checks = []): array
    {
        $checks['environment']['phpVersion'] = version_compare(
            PHP_VERSION,
            Configure::read(self::PHP_MIN_VERSION_CONFIG),
            '>='
        );
        $checks['environment']['nextMinPhpVersion'] = version_compare(
            PHP_VERSION,
            Configure::read(self::PHP_NEXT_MIN_VERSION_CONFIG),
            '>='
        );
        $checks['environment']['pcre'] = Validation::alphaNumeric('passbolt');
        $checks['environment']['mbstring'] = extension_loaded('mbstring');
        $checks['environment']['gnupg'] = extension_loaded('gnupg');
        $checks['environment']['intl'] = extension_loaded('intl');
        $checks['environment']['image'] = (extension_loaded('gd') || extension_loaded('imagick'));
        $checks['environment']['tmpWritable'] = self::_checkRecursiveDirectoryWritable(TMP);
        $checks['environment']['logWritable'] = is_writable(LOGS);
        //$checks['environment']['allow_url_fopen'] = ini_get('allow_url_fopen') === '1';

        return $checks;
    }

    /**
     * Returns JWT related checks:
     *  - is the JWT Authentication enabled
     *  - if true, are the JWT key files correctly set and valid.
     *
     * @param \Passbolt\JwtAuthentication\Service\AccessToken\JwtKeyPairService|null $jwtKeyPairService JWT Service
     * @param array $checks List of checks
     * @return array
     */
    public static function jwt(?JwtKeyPairService $jwtKeyPairService = null, array $checks = []): array
    {
        if (is_null($jwtKeyPairService)) {
            $jwtKeyPairService = new JwtKeyPairService();
        }
        try {
            $jwtKeyPairService->validateKeyPair();
            $keyPairIsValid = true;
        } catch (\Throwable $e) {
            $keyPairIsValid = false;
        }

        $checks['jwt']['isEnabled'] = (Configure::read('passbolt.plugins.jwtAuthentication.enabled') === true);
        $checks['jwt']['keyPairValid'] = $keyPairIsValid;
        $checks['jwt']['jwtWritable'] = is_writable(JwtAbstractService::JWT_CONFIG_DIR);

        return $checks;
    }

    /**
     * Gpg checks
     *
     * @param array|null $checks List of checks
     * @return array
     */
    public static function gpg(?array $checks = []): array
    {
        return GpgHealthchecks::all($checks);
    }

    /**
     * SSL certs check
     * - ssl.peerValid
     * - ssl.hostValid
     * - ssl.notSelfSigned
     *
     * @param ?\Cake\Http\Client $client Client
     * @param array|null $checks List of checks
     * @return array
     */
    public static function ssl(?Client $client = null, ?array $checks = []): array
    {
        return (new SslHealthchecks($client))->all($checks);
    }

    /**
     * SmtpSettings check
     * - PASS: SMTP settings are set in the DB and decryptable
     * - WARN: SMTP settings are not set in the DB
     * - FAIL: SMTP settings are set in the DB and not decryptable
     *
     * @param array|null $checks List of checks
     * @return array
     */
    public static function smtpSettings(?array $checks = []): array
    {
        // Since the plugin might be removed from various passbolt solutions, we check
        // the availability of the plugin before calling classes of the plugin
        if (!(new self())->isFeaturePluginEnabled('SmtpSettings')) {
            $checks['smtpSettings']['isEnabled'] = false;

            return $checks;
        }

        return (new SmtpSettingsHealthcheckService())->check($checks);
    }

    /**
     * Check that a directory and its content are writable
     *
     * @param string $path the directory path
     * @return bool
     */
    private static function _checkRecursiveDirectoryWritable(string $path): bool
    {
        clearstatcache();

        /** @var \SplFileInfo[] $iterator */
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $name => $fileInfo) {
            if (in_array($fileInfo->getFilename(), ['.', '..', 'empty'])) {
                continue;
            }
            // No file should be executable in tmp
            if ($fileInfo->isFile() && DirectoryUtility::isExecutable($name)) {
                return false;
            }
            if (!$fileInfo->isWritable()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get schema tables list. (per version number).
     *
     * @param int $version passbolt major version number.
     * @return array
     */
    public static function getSchemaTables(int $version = 2): array
    {
        // List of tables for passbolt v1.
        $tables = [
            'authentication_tokens',
            'avatars',
            'comments',
            'email_queue',
            'favorites',
            'gpgkeys',
            'groups',
            'groups_users',
            'permissions',
            'profiles',
            'resources',
            'roles',
            'secrets',
            'users',
        ];

        // Extra tables for passbolt v2.
        if ($version == 2) {
            $tables = array_merge($tables, [
                //'burzum_file_storage_phinxlog', // dropped in v2.8
                //'email_queue_phinxlog',
                'phinxlog',
            ]);
        }

        return $tables;
    }
}
