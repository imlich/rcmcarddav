<?php

/*
 * RCMCardDAV - CardDAV plugin for Roundcube webmail
 *
 * Copyright (C) 2011-2022 Benjamin Schieder <rcmcarddav@wegwerf.anderdonau.de>,
 *                         Michael Stilkerich <ms@mike2k.de>
 *
 * This file is part of RCMCardDAV.
 *
 * RCMCardDAV is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * RCMCardDAV is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with RCMCardDAV. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

use MStilkerich\CardDavClient\{Account, AddressbookCollection};
use Psr\Log\LoggerInterface;
use MStilkerich\CardDavAddressbook4Roundcube\{Addressbook, Config, RoundcubeLogger, DataConversion};
use MStilkerich\CardDavAddressbook4Roundcube\Db\{Database, AbstractDatabase};
use Sabre\VObject\Component\VCard;

/**
 * @psalm-type PasswordStoreScheme = 'plain' | 'base64' | 'des_key' | 'encrypted'
 * @psalm-type ConfigurablePresetAttribute = 'name'|'url'|'username'|'password'|'active'|'refresh_time'
 * @psalm-type SpecialAbookType = 'collected_recipients'|'collected_senders'
 * @psalm-type SpecialAbookMatch = array{preset: string, matchname?: string, matchurl?: string}
 * @psalm-type Preset = array{
 *     name: string,
 *     url: string,
 *     username: string,
 *     password: string,
 *     active: bool,
 *     use_categories: bool,
 *     readonly: bool,
 *     refresh_time: int,
 *     fixed: list<ConfigurablePresetAttribute>,
 *     require_always: list<string>,
 *     hide: bool,
 *     carddav_name_only: bool,
 *     rediscover_mode: 'none' | 'fulldiscovery',
 * }
 * @psalm-type AbookSettings = array{
 *     name?: string,
 *     username?: string,
 *     password?: string,
 *     url?: string,
 *     refresh_time?: int,
 *     active?: bool,
 *     use_categories?: bool,
 *     presetname?: string
 * }
 * @psalm-import-type FullAbookRow from AbstractDatabase
 * @psalm-import-type SaveDataFromDC from DataConversion
 */
// phpcs:ignore PSR1.Classes.ClassDeclaration, Squiz.Classes.ValidClassName -- class name(space) expected by roundcube
class carddav extends rcube_plugin
{
    /**
     * The version of this plugin.
     *
     * During development, it is set to the last release and added the suffix +dev.
     */
    public const PLUGIN_VERSION = 'v4.4.5';

    /**
     * Information about this plugin that is queried by roundcube.
     */
    private const PLUGIN_INFO = [
        'name' => 'carddav',
        'vendor' => 'Michael Stilkerich, Benjamin Schieder',
        'version' => self::PLUGIN_VERSION,
        'license' => 'GPL-2.0',
        'uri' => 'https://github.com/mstilkerich/rcmcarddav/'
    ];

    /** @var list<PasswordStoreScheme> List of supported password store schemes */
    private const PWSTORE_SCHEMES = [ 'plain', 'base64', 'des_key', 'encrypted' ];

    /**
     * @var AbookSettings Template for addressbook settings from the settings page.
     *      The default values in this template also serve do determine the type (bool, int, string).
     */
    private const ABOOK_TEMPLATE = [
        // standard addressbook settings
        'name' => '',
        'url' => '',
        'username' => '',
        'password' => '',
        'active' => true,
        'use_categories' => true,
        'refresh_time' => 3600,
    ];

    /**
     * @var Preset Template for a preset; has the standard addressbook settings plus some extra properties.
     *      The default values in this template also serve do determine the type (bool, int, string, array).
     */
    private const PRESET_TEMPLATE = self::ABOOK_TEMPLATE + [
        // extra settings for presets
        'readonly' => false,
        'carddav_name_only' => false,
        'hide' => false,
        'fixed' => [],
        'require_always' => [],
        'rediscover_mode' => 'fulldiscovery',
    ];

    /** @var PasswordStoreScheme encryption scheme */
    private $pwStoreScheme = 'encrypted';

    /** @var array<SpecialAbookType,SpecialAbookMatch> Match settings for special addressbooks */
    private $specialAbookMatchers = [];

    /** @var bool Global preference "fixed" */
    private $forbidCustomAddressbooks = false;

    /** @var bool Global preference "hide_preferences" */
    private $hidePreferences = false;

    /** @var array<string, Preset> Presets from config.inc.php */
    private $presets = [];

    public $task = 'addressbook|login|mail|settings|calendar';

    /** @var ?array<string, FullAbookRow> $abooksDb Cache of the user's addressbook DB entries.
     *                                              Associative array mapping addressbook IDs to DB rows.
     */
    private $abooksDb = null;

    /**
     * Provide information about this plugin.
     *
     * @return array Meta information about a plugin or false if not implemented.
     * As hash array with the following keys:
     *      name: The plugin name
     *    vendor: Name of the plugin developer
     *   version: Plugin version name
     *   license: License name (short form according to http://spdx.org/licenses/)
     *       uri: The URL to the plugin homepage or source repository
     *   src_uri: Direct download URL to the source code of this plugin
     *   require: List of plugins required for this one (as array of plugin names)
     */
    public static function info()
    {
        return self::PLUGIN_INFO;
    }

    /**
     * Default constructor.
     *
     * @param rcube_plugin_api $api Plugin API
     */
    public function __construct($api, array $options = [])
    {
        // This supports a self-contained tarball installation of the plugin, at the risk of having conflicts with other
        // versions of the library installed in the global roundcube vendor directory (-> use not recommended)
        if (file_exists(dirname(__FILE__) . "/vendor/autoload.php")) {
            include_once dirname(__FILE__) . "/vendor/autoload.php";
        }

        parent::__construct($api);

        // we do not currently use the roundcube mechanism to save preferences
        // but store preferences to custom database tables
        $this->allowed_prefs = [];
    }

    public function init(): void
    {
        $infra = Config::inst();
        $logger = $infra->logger();

        try {
            $this->readAdminSettings();

            // initialize carddavclient library
            MStilkerich\CardDavClient\Config::init($logger, $infra->httpLogger());

            $this->add_texts('localization/', false);

            $this->add_hook('addressbooks_list', [$this, 'listAddressbooks']);
            $this->add_hook('addressbook_get', [$this, 'getAddressbook']);
            $this->add_hook('addressbook_export', [$this, 'exportVCards']);

            // if preferences are configured as hidden by the admin, don't register the hooks handling preferences
            if (!$this->hidePreferences) {
                $this->add_hook('preferences_list', [$this, 'buildPreferencesPage']);
                $this->add_hook('preferences_save', [$this, 'savePreferences']);
                $this->add_hook('preferences_sections_list', [$this, 'addPreferencesSection']);
            }

            $this->add_hook('login_after', [$this, 'checkMigrations']);
            $this->add_hook('login_after', [$this, 'initPresets']);

            if (!isset($_SESSION['user_id'])) {
                return;
            }

            // use this address book for autocompletion queries
            // (maybe this should be configurable by the user?)
            $config = rcube::get_instance()->config;
            $sources = (array) $config->get('autocomplete_addressbooks', ['sql']);

            $carddav_sources = array_map(
                function (string $id): string {
                    return "carddav_$id";
                },
                array_keys($this->getAddressbooks())
            );

            $config->set('autocomplete_addressbooks', array_merge($sources, $carddav_sources));
            $skin_path = $this->local_skin_path();
            $this->include_stylesheet($skin_path . '/carddav.css');

            foreach ($this->specialAbookMatchers as $type => $matchSettings) {
                $this->setSpecialAddressbook($type, $matchSettings);
            }
        } catch (\Exception $e) {
            $logger->error("Could not init rcmcarddav: " . $e->getMessage());
        }
    }

    /***************************************************************************************
     *                                    HOOK FUNCTIONS
     **************************************************************************************/

    public function checkMigrations(): void
    {
        $infra = Config::inst();
        $logger = $infra->logger();
        $db = $infra->db();

        try {
            $logger->debug(__METHOD__);

            $scriptDir = dirname(__FILE__) . "/dbmigrations/";
            $config = rcube::get_instance()->config;
            $dbprefix = (string) $config->get('db_prefix', "");
            $db->checkMigrations($dbprefix, $scriptDir);
        } catch (\Exception $e) {
            $logger->error("Error execution DB schema migrations: " . $e->getMessage());
        }
    }

    public function initPresets(): void
    {
        $infra = Config::inst();
        $logger = $infra->logger();
        $rcUserId = (string) $_SESSION['user_id'];

        try {
            $logger->debug(__METHOD__);

            // Group the addressbooks by their preset
            $localAbookRowsByPreset = [];
            foreach ($this->getAddressbooks(false, true) as $abookrow) {
                /** @psalm-var string $presetname Not null because filtered by getAddressbooks() */
                $presetname = $abookrow['presetname'];
                $localAbookRowsByPreset[$presetname][$abookrow['url']] = $abookrow;
            }

            // Walk over the current presets configured by the admin and add or update addressbooks
            foreach ($this->presets as $presetname => $preset) {
                $preset['presetname'] = $presetname;

                try {
                    // determine the rediscovery mode
                    if (isset($localAbookRowsByPreset[$presetname])) {
                        $rediscoverMode = $preset['rediscover_mode'];

                        // record all known addressbooks for this preset for update of fixed settings in case we do not
                        // perform discovery. If we do discover, this will be overwritten.
                        $updateAbookRows = $localAbookRowsByPreset[$presetname];
                    } else {
                        // if we don't have any addressbooks for the preset, force performing a full discovery
                        $rediscoverMode = 'fulldiscovery';
                        $updateAbookRows = [];
                    }

                    if ($rediscoverMode == 'fulldiscovery') {
                        // discover the addressbooks at the server
                        $username = self::replacePlaceholdersUsername($preset['username']);
                        $url = self::replacePlaceholdersUrl($preset['url']);
                        $password = self::replacePlaceholdersPassword($preset['password']);
                        try {
                            $account = Config::makeAccount($url, $username, $password, null);
                        } catch (\Exception $e) {
                            $logger->info("Skip adding preset for $rcUserId: required bearer token not available");
                            continue;
                        }
                        $abooks = $this->determineAddressbooksToAdd($account);

                        // insert all newly discovered addressbooks, record those already present in the DB for update
                        $updateAbookRows = [];
                        foreach ($abooks as $abook) {
                            $url = $abook->getUri();

                            // determine name for the addressbook
                            $abookName = $abook->getName();
                            if (!$preset['carddav_name_only']) {
                                $abookName = $preset['name'] . " ($abookName)";
                            }

                            $abookrow = $localAbookRowsByPreset[$presetname][$url] ?? null;
                            if (isset($abookrow)) {
                                $abookrow['srvname'] = $abookName;
                                $updateAbookRows[] = $abookrow;
                            } else {
                                $logger->info("Inserting new addressbook for preset $presetname at $url for $rcUserId");
                                $abookrow = $preset;
                                $abookrow['url'] = $url;
                                $abookrow['name'] = $abookName;
                                $this->insertAddressbook($abookrow);
                            }
                        }
                    }

                    // Update the fixed prefs in all addressbooks that we already knew locally
                    foreach ($updateAbookRows as $abookrow) {
                        $url = $abookrow['url'];
                        $logger->debug("Updating preset ($presetname) addressbook $url for $rcUserId");
                        $this->updatePresetAddressbook($preset, $abookrow);

                        // delete from localAbookRowsByPreset list so we know it was processed
                        unset($localAbookRowsByPreset[$presetname][$url]);
                    }
                } catch (\Exception $e) {
                    $logger->error("Error adding addressbook from preset $presetname: {$e->getMessage()}");
                }
            }

            // delete existing preset addressbooks that were removed by admin or do not exist on server anymore
            foreach ($localAbookRowsByPreset as $presetname => $ep) {
                foreach ($ep as $abookrow) {
                    $url = $abookrow['url'];
                    $logger->info("Deleting preset ($presetname) addressbook $url for $rcUserId");
                    $this->deleteAddressbook($abookrow['id']);
                }
            }
        } catch (\Exception $e) {
            $logger->error("Error initializing preconfigured addressbooks: " . $e->getMessage());
        }
    }

    /**
     * Adds the user's CardDAV addressbooks to Roundcube's addressbook list.
     *
     * @psalm-type RcAddressbookInfo = array{id: string, name: string, groups: bool, autocomplete: bool, readonly: bool}
     * @psalm-param array{sources: array<string, RcAddressbookInfo>} $p
     * @return array{sources: array<string, RcAddressbookInfo>}
     */
    public function listAddressbooks(array $p): array
    {
        $logger = Config::inst()->logger();

        try {
            $logger->debug(__METHOD__);

            foreach ($this->getAddressbooks() as $abookrow) {
                $abookId = $abookrow["id"];
                $presetname = $abookrow['presetname'] ?? ""; // empty string is not a valid preset name
                $ro = $this->presets[$presetname]['readonly'] ?? false;

                $p['sources']["carddav_$abookId"] = [
                    'id' => "carddav_$abookId",
                    'name' => $abookrow['name'],
                    'groups' => true,
                    'autocomplete' => true,
                    'readonly' => $ro,
                ];
            }
        } catch (\Exception $e) {
            $logger->error("Error reading carddav addressbooks: " . $e->getMessage());
        }

        return $p;
    }

    /**
     * Hook called by roundcube to retrieve the instance of an addressbook.
     *
     * @param array $p The passed array contains the keys:
     *     id: ID of the addressbook as passed to roundcube in the listAddressbooks hook.
     *     writeable: Whether the addressbook needs to be writeable (checked by roundcube after returning an instance).
     * @psalm-param array{id: ?string} $p
     * @return array Returns the passed array extended by a key instance pointing to the addressbook object.
     *     If the addressbook is not provided by the plugin, simply do not set the instance and return what was passed.
     */
    public function getAddressbook(array $p): array
    {
        $infra = Config::inst();
        $logger = $infra->logger();

        $abookId = $p['id'] ?? 'null';

        try {
            $logger->debug(__METHOD__ . "($abookId)");

            if (preg_match(";^carddav_(\d+)$;", $abookId, $match)) {
                $abookId = $match[1];
                $abooks = $this->getAddressbooks(false);

                // check that this addressbook ID actually refers to one of the user's addressbooks
                if (isset($abooks[$abookId])) {
                    $config = $abooks[$abookId];
                    $presetname = $config["presetname"] ?? ""; // empty string is not a valid preset name

                    $readonly = !empty($this->presets[$presetname]["readonly"] ?? '0');
                    $requiredProps = $this->presets[$presetname]["require_always"] ?? [];

                    $config['username'] = self::replacePlaceholdersUsername($config["username"]);
                    $config['password'] = self::replacePlaceholdersPassword(
                        $this->decryptPassword($config["password"])
                    );

                    $abook = new Addressbook(
                        $abookId,
                        $config,
                        $readonly,
                        $requiredProps
                    );
                    $p['instance'] = $abook;

                    // refresh the address book if the update interval expired this requires a completely initialized
                    // Addressbook object, so it needs to be at the end of this constructor
                    $ts_syncdue = $abook->checkResyncDue();
                    if ($ts_syncdue <= 0) {
                        $this->resyncAddressbook($abook);
                    }
                }
            }
        } catch (\Exception $e) {
            $logger->error("Error loading carddav addressbook $abookId: " . $e->getMessage());
        }

        return $p;
    }

    /**
     * Prepares the exported VCards when the user requested VCard export in roundcube.
     *
     * By adding a "vcard" member to a save_data set, we can override roundcube's own VCard creation
     * from the save_data and provide the VCard directly.
     *
     * Beware: This function is called also for non-carddav addressbooks, therefore it must handle entries
     * that cannot be found in the carddav addressbooks.
     *
     * @param array{result: rcube_result_set} $saveDataSet A result set as provided by Addressbook::list_records
     * @return array{abort: bool, result: rcube_result_set} The result set with added vcard members in each save_data
     */
    public function exportVCards(array $saveDataSet): array
    {
        /** @psalm-var SaveDataFromDC $save_data */
        foreach ($saveDataSet["result"]->records as &$save_data) {
            $vcard = $save_data["_carddav_vcard"] ?? null;
            if ($vcard instanceof VCard) {
                $vcf = DataConversion::exportVCard($vcard, $save_data);
                $save_data["vcard"] = $vcf;
            }
        }

        return [ "result" => $saveDataSet["result"], "abort" => false ];
    }

    /**
     * Handler for preferences_list hook.
     * Adds options blocks into CardDAV settings sections in Preferences.
     *
     * @psalm-param array{section: string, blocks: array} $args Original parameters
     * @return array Modified parameters
     */
    public function buildPreferencesPage(array $args): array
    {
        $logger = Config::inst()->logger();

        try {
            $logger->debug(__METHOD__);

            if ($args['section'] != 'cd_preferences') {
                return $args;
            }

            $this->include_stylesheet($this->local_skin_path() . '/carddav.css');
            $abooks = $this->getAddressbooks(false);
            uasort(
                $abooks,
                function (array $a, array $b): int {
                    /** @var FullAbookRow $a */
                    $a = $a;
                    /** @var FullAbookRow $b */
                    $b = $b;
                    // presets first
                    $ret = strcasecmp($b["presetname"] ?? "", $a["presetname"] ?? "");
                    if ($ret == 0) {
                        // then alphabetically by name
                        $ret = strcasecmp($a["name"], $b["name"]);
                    }
                    if ($ret == 0) {
                        // finally by id (normally the names will differ)
                        $ret = $a["id"] <=> $b["id"];
                    }
                    return $ret;
                }
            );


            $fromPresetStringLocalized = rcube::Q($this->gettext('cd_frompreset'));
            foreach ($abooks as $abookrow) {
                $abookId = $abookrow["id"];
                $presetname = $abookrow['presetname'] ?? ""; // empty string is not a valid presetname
                if (!($this->presets[$presetname]['hide'] ?? false)) {
                    $blockhdr = $abookrow['name'];
                    if (!empty($presetname)) {
                        $blockhdr .= str_replace("_PRESETNAME_", $presetname, $fromPresetStringLocalized);
                    }
                    $args["blocks"]["cd_preferences$abookId"] =
                        $this->buildSettingsBlock($blockhdr, $abookrow, $abookId);
                }
            }

            // if allowed by admin, provide a block for entering data for a new addressbook
            if (!$this->forbidCustomAddressbooks) {
                $args['blocks']['cd_preferences_section_new'] = $this->buildSettingsBlock(
                    rcube::Q($this->gettext('cd_newabboxtitle')),
                    $this->getAddressbookSettingsFromPOST('new'),
                    "new"
                );
            }
        } catch (\Exception $e) {
            $logger->error("Error building carddav preferences page: " . $e->getMessage());
        }

        return $args;
    }

    /**
     * add a section to the preferences tab
     * @psalm-param array{list: array, cols: array} $args
     */
    public function addPreferencesSection(array $args): array
    {
        $logger = Config::inst()->logger();

        try {
            $logger->debug(__METHOD__);

            $args['list']['cd_preferences'] = [
                'id'      => 'cd_preferences',
                'section' => rcube::Q($this->gettext('cd_title'))
            ];
        } catch (\Exception $e) {
            $logger->error("Error adding carddav preferences section: " . $e->getMessage());
        }
        return $args;
    }

    /**
     * Hook function called when the user saves the preferences.
     *
     * This function is called for any preferences section, not just that of the carddav plugin, so we need to check
     * first whether we are in the proper section.
     */
    public function savePreferences(array $args): array
    {
        $infra = Config::inst();
        $logger = $infra->logger();

        try {
            $logger->debug(__METHOD__);

            if ($args['section'] != 'cd_preferences') {
                return $args;
            }

            // update existing in DB
            foreach ($this->getAddressbooks(false) as $abookrow) {
                $abookId = $abookrow["id"];
                if (isset($_POST["${abookId}_cd_delete"])) {
                    $this->deleteAddressbook($abookId);
                } else {
                    $newset = $this->getAddressbookSettingsFromPOST($abookId, $abookrow["presetname"]);
                    $this->updateAddressbook($abookId, $newset);

                    // first clear the local cache if requested
                    if (isset($_POST["${abookId}_cd_clearcache"])) {
                        $this->deleteAddressbook($abookId, true);
                    }

                    // then perform a resync if requested
                    if (isset($_POST["${abookId}_cd_resync"])) {
                        [ 'instance' => $backend ] = $this->getAddressbook(['id' => "carddav_$abookId"]);
                        if ($backend instanceof Addressbook) {
                            $this->resyncAddressbook($backend);
                        }
                    }
                }
            }

            // add a new address book?
            $new = $this->getAddressbookSettingsFromPOST('new');
            if (
                !$this->forbidCustomAddressbooks // creation of addressbooks allowed by admin
                && !empty($new['name']) // user entered a name (and hopefully more data) for a new addressbook
            ) {
                try {
                    $new["url"] = $new["url"] ?? "";
                    $new["username"] = $new['username'] ?? "";
                    $new["password"] = $new['password'] ?? "";

                    if (filter_var($new["url"], FILTER_VALIDATE_URL) === false) {
                        throw new \Exception("Invalid URL: " . $new["url"]);
                    }
                    $account = Config::makeAccount(
                        $new["url"],
                        $new['username'],
                        self::replacePlaceholdersPassword($new['password']),
                        null
                    );
                    $abooks = $this->determineAddressbooksToAdd($account);

                    if (count($abooks) > 0) {
                        $basename = $new['name'];

                        foreach ($abooks as $abook) {
                            $new['url'] = $abook->getUri();
                            $new['name'] = "$basename ({$abook->getName()})";

                            $logger->info("Adding addressbook {$new['username']} @ {$new['url']}");
                            $this->insertAddressbook($new);
                        }

                        // new addressbook added successfully -> clear the data from the form
                        foreach (array_keys(self::ABOOK_TEMPLATE) as $k) {
                            unset($_POST["new_cd_$k"]);
                        }
                    } else {
                        throw new \Exception($new['name'] . ': ' . $this->gettext('cd_err_noabfound'));
                    }
                } catch (\Exception $e) {
                    $args['abort'] = true;
                    $args['message'] = $e->getMessage();
                }
            }
        } catch (\Exception $e) {
            $logger->error("Error saving carddav preferences: " . $e->getMessage());
        }

        return $args;
    }


    /***************************************************************************************
     *                              PRIVATE FUNCTIONS
     **************************************************************************************/

    private static function replacePlaceholdersUsername(string $username, bool $quoteRegExp = false): string
    {
        $rcube = rcube::get_instance();
        $rcusername = (string) $_SESSION['username'];

        $transTable = [
            '%u' => $rcusername,
            '%l' => $rcube->user->get_username('local'),
            '%d' => $rcube->user->get_username('domain'),
            // %V parses username for macosx, replaces periods and @ by _, work around bugs in contacts.app
            '%V' => strtr($rcusername, "@.", "__")
        ];

        if ($quoteRegExp) {
            $transTable = array_map('preg_quote', $transTable);
        }

        $username = strtr($username, $transTable);

        return $username;
    }

    private static function replacePlaceholdersUrl(string $url, bool $quoteRegExp = false): string
    {
        // currently same as for username
        return self::replacePlaceholdersUsername($url, $quoteRegExp);
    }

    private static function replacePlaceholdersPassword(string $password): string
    {
        if ($password == '%p') {
            $rcube = rcube::get_instance();
            $password = $rcube->decrypt((string) $_SESSION['password']);
            if ($password === false) {
                $password = "";
            }
        }

        return $password;
    }

    /**
     * Parses a time string to seconds.
     *
     * The time string must have the format HH[:MM[:SS]]. If the format does not match, an exception is thrown.
     *
     * @param string $refresht The time string to parse
     * @return int The time in seconds
     */
    private static function parseTimeParameter(string $refresht): int
    {
        if (preg_match('/^(\d+)(:([0-5]?\d))?(:([0-5]?\d))?$/', $refresht, $match)) {
            $ret = 0;

            $ret += intval($match[1] ?? 0) * 3600;
            $ret += intval($match[3] ?? 0) * 60;
            $ret += intval($match[5] ?? 0);
        } else {
            throw new \Exception("Time string $refresht could not be parsed");
        }

        return $ret;
    }

    /**
     * Compares the path components of two URIs.
     *
     * @return bool True if the normalized path components are equal.
     */
    private static function compareUrlPaths(string $url1, string $url2): bool
    {
        $comp1 = \Sabre\Uri\parse($url1);
        $comp2 = \Sabre\Uri\parse($url2);
        $p1 = trim(rtrim($comp1["path"] ?? "", "/"), "/");
        $p2 = trim(rtrim($comp2["path"] ?? "", "/"), "/");
        return $p1 === $p2;
    }

    /**
     * @param AbookSettings $pa Array with the settings to update
     */
    private function updateAddressbook(string $abookId, array $pa): void
    {
        // encrypt the password before storing it
        if (isset($pa['password'])) {
            $pa['password'] = $this->encryptPassword($pa['password']);
        }

        // optional fields
        $qf = [];
        $qv = [];

        foreach (array_keys(self::ABOOK_TEMPLATE) as $f) {
            if (isset($pa[$f])) {
                $v = $pa[$f];

                $qf[] = $f;
                if (is_bool($v)) {
                    $qv[] = $v ? '1' : '0';
                } else {
                    $qv[] = (string) $v;
                }
            }
        }

        if (!empty($qf)) {
            $db = Config::inst()->db();
            $db->update($abookId, $qf, $qv, "addressbooks");
            $this->abooksDb = null;
        }
    }

    /**
     * Converts a password to storage format according to the password storage scheme setting.
     *
     * @param string $clear The password in clear text.
     * @return string The password in storage format (e.g. encrypted with user password as key)
     */
    private function encryptPassword(string $clear): string
    {
        $scheme = $this->pwStoreScheme;

        if (strcasecmp($scheme, 'plain') === 0) {
            return $clear;
        }

        if (strcasecmp($scheme, 'encrypted') === 0) {
            try {
                // encrypted with IMAP password
                $rcube = rcube::get_instance();

                $imap_password = $this->getDesKey();
                $rcube->config->set('carddav_des_key', $imap_password);

                $crypted = $rcube->encrypt($clear, 'carddav_des_key');

                // there seems to be no way to unset a preference
                $rcube->config->set('carddav_des_key', '');

                if ($crypted === false) {
                    throw new \Exception("Password encryption with user password failed");
                }

                return '{ENCRYPTED}' . $crypted;
            } catch (\Exception $e) {
                $logger = Config::inst()->logger();
                $logger->warning(
                    "Could not encrypt password with 'encrypted' method, falling back to 'des_key': " . $e->getMessage()
                );
                $scheme = 'des_key';
            }
        }

        if (strcasecmp($scheme, 'des_key') === 0) {
            // encrypted with global des_key
            $rcube = rcube::get_instance();
            $crypted = $rcube->encrypt($clear);

            if ($crypted === false) {
                throw new \Exception("Could not encrypt password with 'des_key' method: ");
            }
            return '{DES_KEY}' . $crypted;
        }

        // default: base64-coded password
        return '{BASE64}' . base64_encode($clear);
    }

    private function decryptPassword(string $crypt): string
    {
        $logger = Config::inst()->logger();

        if (strpos($crypt, '{ENCRYPTED}') === 0) {
            try {
                $crypt = substr($crypt, strlen('{ENCRYPTED}'));
                $rcube = rcube::get_instance();

                $imap_password = $this->getDesKey();
                $rcube->config->set('carddav_des_key', $imap_password);
                $clear = $rcube->decrypt($crypt, 'carddav_des_key');
                // there seems to be no way to unset a preference
                $rcube->config->set('carddav_des_key', '');
                if ($clear === false) {
                    $clear = "";
                }

                return $clear;
            } catch (\Exception $e) {
                $logger->warning("Cannot decrypt password: " . $e->getMessage());
                return "";
            }
        }

        if (strpos($crypt, '{DES_KEY}') === 0) {
            $crypt = substr($crypt, strlen('{DES_KEY}'));
            $rcube = rcube::get_instance();
            $clear = $rcube->decrypt($crypt);
            if ($clear === false) {
                $clear = "";
            }

            return $clear;
        }

        if (strpos($crypt, '{BASE64}') === 0) {
            $crypt = substr($crypt, strlen('{BASE64}'));
            return base64_decode($crypt);
        }

        // unknown scheme, assume cleartext
        return $crypt;
    }

    /**
     * Updates the fixed fields of a preset addressbook derived from presets against the current admin settings.
     * @param Preset $preset
     * @param FullAbookRow $abookrow Database row of an existing addressbook for the preset
     */
    private function updatePresetAddressbook(array $preset, array $abookrow): void
    {
        if (!is_array($preset["fixed"] ?? "")) {
            return;
        }

        // decrypt password so that the comparison works
        $abookrow['password'] = $this->decryptPassword($abookrow['password']);

        // update only those attributes marked as fixed by the admin
        // otherwise there may be user changes that should not be destroyed
        $pa = [];

        foreach ($preset['fixed'] as $k) {
            if (isset($abookrow[$k]) && isset($preset[$k])) {
                // only update the name if it is used
                if ($k === 'name') {
                    $newname = $abookrow['name'];

                    // if we performed a rediscovery, we have the desired name including the current server-side name in
                    // the srvname field of $abookrow - use it
                    if (isset($abookrow['srvname'])) {
                        $newname = (string) $abookrow['srvname'];

                    // otherwise we can only update the admin-configured name of the addressbook
                    // AdminName (ServersideName)
                    } elseif (!$preset['carddav_name_only']) {
                        $cnpos = strpos($newname, ' (');
                        if ($cnpos === false && $preset['name'] != $newname) {
                            $newname = $preset['name'];
                        } elseif ($cnpos !== false && $preset['name'] != substr($newname, 0, $cnpos)) {
                            $newname = $preset['name'] . substr($newname, $cnpos);
                        }
                    }

                    if ($abookrow['name'] != $newname) {
                        $pa['name'] = $newname;
                    }
                } elseif ($k === 'url') {
                    // the URL cannot be automatically updated, as it was discovered and normally will
                    // not exactly match the discovery URI. Resetting it to the discovery URI would
                    // break the addressbook record
                } elseif ($abookrow[$k] != $preset[$k]) {
                    $pa[$k] = $preset[$k];
                }
            }
        }

        // only update if something changed
        if (!empty($pa)) {
            /** @psalm-var AbookSettings $pa */
            $this->updateAddressbook($abookrow['id'], $pa);
        }
    }

    /**
     * @param ?string $presetName If the setting is checked for an addressbook from a preset, the key of the preset.
     *                            Null if the setting is checked for a user-defined addressbook.
     * @return bool True if the setting is fixed for the given preset. Always false for user-defined addressbooks.
     */
    private function noOverrideAllowed(string $pref, ?string $presetName): bool
    {
        // generally, url is fixed, as it results from discovery and has no direct correlation with the admin setting
        // if the URL of the addressbook changes, all URIs of our database objects would have to change, too -> in such
        // cases, deleting and re-adding the addressbook would be simpler
        if ($pref == "url") {
            return true;
        }

        $pn = $presetName ?? ""; // empty string is not a valid presetname
        return in_array($pref, $this->presets[$pn]['fixed'] ?? []);
    }

    /**
     * @param null|string|bool $value Value to show if the field can be edited.
     * @param null|string|bool $roValue Value to show if the field is shown in non-editable form.
     */
    private function buildSettingField(
        string $abookId,
        string $attr,
        $value,
        ?string $presetName,
        $roValue = null
    ): string {
        // if the value is not set, use the default from the addressbook template
        $value = $value ?? self::ABOOK_TEMPLATE[$attr];
        $roValue = $roValue ?? $value;
        // For new addressbooks, no attribute is fixed (note: noOverrideAllowed always returns true for URL)
        $attrFixed = $abookId != "new" && $this->noOverrideAllowed($attr, $presetName);

        if (is_bool(self::ABOOK_TEMPLATE[$attr])) {
            // boolean settings as a checkbox
            if ($attrFixed) {
                $content = $roValue ? $this->gettext('cd_enabled') : $this->gettext('cd_disabled');
            } else {
                // check box for activating
                $checkbox = new html_checkbox(['name' => "${abookId}_cd_$attr", 'value' => 1]);
                $content = $checkbox->show($value ? "1" : "0");
            }
        } elseif (is_string(self::ABOOK_TEMPLATE[$attr])) {
            if ($attrFixed) {
                $content = (string) $roValue;
            } else {
                // input box for username
                $input = new html_inputfield([
                    'name' => "${abookId}_cd_$attr",
                    'type' => ($attr == 'password') ? 'password' : 'text',
                    'autocomplete' => 'off',
                    'value' => $value
                ]);
                $content = $input->show();
            }
        } else {
            throw new \Exception("unsupported type");
        }

        return $content;
    }

    /**
     * Builds a setting block for one address book for the preference page.
     * @param FullAbookRow|AbookSettings $abook
     */
    private function buildSettingsBlock(string $blockheader, array $abook, string $abookId): array
    {
        $presetName = $abook["presetname"] ?? null;
        $content_active = $this->buildSettingField($abookId, "active", $abook['active'] ?? null, $presetName);
        $content_use_categories =
            $this->buildSettingField($abookId, "use_categories", $abook['use_categories'] ?? null, $presetName);
        $content_name = $this->buildSettingField($abookId, "name", $abook['name'] ?? null, $presetName);
        $content_username = $this->buildSettingField(
            $abookId,
            "username",
            $abook['username'] ?? null,
            $presetName,
            self::replacePlaceholdersUsername($abook['username'] ?? "")
        );
        $content_password = $this->buildSettingField(
            $abookId,
            "password",
            // only display the password if it was entered for a new addressbook
            ($abookId == "new") ? ($abook['password'] ?? "") : "",
            $presetName,
            "***"
        );
        $content_url = $this->buildSettingField(
            $abookId,
            "url",
            $abook['url'] ?? null,
            $presetName
        );

        // input box for refresh time
        if (isset($abook['refresh_time'])) {
            $rt = intval($abook['refresh_time']);
            $refresh_time_str = sprintf("%02d:%02d:%02d", intdiv($rt, 3600), intdiv($rt, 60) % 60, $rt % 60);
        } else {
            $refresh_time_str = "";
        }
        if ($this->noOverrideAllowed('refresh_time', $presetName)) {
            $content_refresh_time =  $refresh_time_str . ", ";
        } else {
            $input = new html_inputfield([
                'name' => $abookId . '_cd_refresh_time',
                'type' => 'text',
                'autocomplete' => 'off',
                'value' => $refresh_time_str,
                'size' => 10
            ]);
            $content_refresh_time = $input->show();
        }

        if (!empty($abook['last_updated'])) { // if never synced, last_updated is 0 -> don't show
            $content_refresh_time .=  rcube::Q($this->gettext('cd_lastupdate_time')) . ": ";
            $content_refresh_time .=  date("Y-m-d H:i:s", intval($abook['last_updated']));
        }

        $retval = [
            'options' => [
                ['title' => rcube::Q($this->gettext('cd_name')), 'content' => $content_name],
                ['title' => rcube::Q($this->gettext('cd_active')), 'content' => $content_active],
                ['title' => rcube::Q($this->gettext('cd_use_categories')), 'content' => $content_use_categories],
                ['title' => rcube::Q($this->gettext('cd_username')), 'content' => $content_username],
                ['title' => rcube::Q($this->gettext('cd_password')), 'content' => $content_password],
                ['title' => rcube::Q($this->gettext('cd_url')), 'content' => $content_url],
                ['title' => rcube::Q($this->gettext('cd_refresh_time')), 'content' => $content_refresh_time],
            ],
            'name' => $blockheader
        ];

        if ($abookId != "new") {
            if (empty($presetName)) {
                $checkbox = new html_checkbox(['name' => $abookId . '_cd_delete', 'value' => 1]);
                $content_delete = $checkbox->show("0");
                $retval['options'][] = ['title' => rcube::Q($this->gettext('cd_delete')), 'content' => $content_delete];
            }

            $checkbox = new html_checkbox(['name' => $abookId . '_cd_clearcache', 'value' => 1]);
            $content_clearcache = $checkbox->show("0");
            $retval['options'][] = [
                'title' => rcube::Q($this->gettext('cd_clearcache')),
                'content' => $content_clearcache
            ];

            $checkbox = new html_checkbox(['name' => $abookId . '_cd_resync', 'value' => 1]);
            $content_resync = $checkbox->show("0");
            $retval['options'][] = ['title' => rcube::Q($this->gettext('cd_resync')), 'content' => $content_resync];
        }

        return $retval;
    }

    /**
     * This function gets the addressbook settings from a POST request.
     *
     * The result array will only have keys set for POSTed values.
     *
     * For fixed settings of preset addressbooks, no setting values will be contained.
     *
     * Boolean settings will always be present in the result, since there is no way to differentiate whether a checkbox
     * was not checked or the value was not submitted at all - so the absence of a boolean setting is considered as a
     * false value for the setting.
     *
     * @param string $abookId The ID of the addressbook ("new" for new addressbooks, otherwise the numeric DB id)
     * @param ?string $presetName Name of the preset the addressbook belongs to; null for user-defined addressbook.
     * @return AbookSettings An array with addressbook column keys and their setting.
     */
    private function getAddressbookSettingsFromPOST(string $abookId, ?string $presetName = null): array
    {
        $result = [];

        // Fill $result with all values that have been POSTed; for unset boolean values, false is assumed
        foreach (array_keys(self::ABOOK_TEMPLATE) as $attr) {
            // fixed settings for preset addressbooks are ignored
            if ($abookId != "new" && $this->noOverrideAllowed($attr, $presetName)) {
                continue;
            }

            $allow_html = ($attr == 'password');
            $value = rcube_utils::get_input_value("${abookId}_cd_$attr", rcube_utils::INPUT_POST, $allow_html);

            if (is_bool(self::ABOOK_TEMPLATE[$attr])) {
                $result[$attr] = (bool) $value;
            } elseif (is_string($value)) {
                if ($attr == "refresh_time") {
                    try {
                        $result["refresh_time"] = self::parseTimeParameter($value);
                    } catch (\Exception $e) {
                        // will use the DB default for new addressbooks, or leave the value unchanged for existing
                        // ones
                    }
                } elseif ($attr == "url") {
                    $value = trim($value);
                    if (!empty($value)) {
                        // FILTER_VALIDATE_URL requires the scheme component, default to https if not specified
                        if (strpos($value, "://") === false) {
                            $value = "https://$value";
                        }
                    }
                    $result["url"] = $value;
                } elseif ($attr == "password") {
                    // Password is only updated if not empty
                    if (!empty($value)) {
                        $result["password"] = $value;
                    }
                } else {
                    $result[$attr] = $value;
                }
            }
        }

        // Set default values for boolean options of new addressbook; if name is null, it means the form is loaded for
        // the first time, otherwise it has been posted.
        if ($abookId == "new" && !isset($result["name"])) {
            foreach (self::ABOOK_TEMPLATE as $attr => $value) {
                if (is_bool($value)) {
                    $result[$attr] = $value;
                }
            }
        }

        /** @psalm-var AbookSettings */
        return $result;
    }

    /**
     * Deletes an addressbook from the local database, fully or only its address data.
     *
     * @param string $abookId ID of the target addressbook
     * @param bool $dataOnly If true, only the cached address data is deleted and the sync reset to initial state.
     *                       If false, the addressbook is fully removed from the database.
     */
    private function deleteAddressbook(string $abookId, bool $dataOnly = false): void
    {
        $infra = Config::inst();
        $logger = $infra->logger();
        $db = $infra->db();

        try {
            $db->startTransaction(false);

            // we explicitly delete all data belonging to the addressbook, since
            // cascaded deleted are not supported by all database backends
            // ...custom subtypes
            $db->delete(['abook_id' => $abookId], 'xsubtypes');

            // ...groups and memberships
            /** @psalm-var list<string> $delgroups */
            $delgroups = array_column($db->get(['abook_id' => $abookId], ['id'], 'groups'), "id");
            if (!empty($delgroups)) {
                $db->delete(['group_id' => $delgroups], 'group_user');
            }

            $db->delete(['abook_id' => $abookId], 'groups');

            // ...contacts
            $db->delete(['abook_id' => $abookId], 'contacts');

            if ($dataOnly) {
                $db->update($abookId, ["last_updated", "sync_token"], ["0", ""], "addressbooks");
            } else {
                $db->delete($abookId, 'addressbooks');
            }

            $db->endTransaction();
        } catch (\Exception $e) {
            $logger->error("Could not delete addressbook: " . $e->getMessage());
            $db->rollbackTransaction();
        }
        $this->abooksDb = null;
    }

    /**
     * @param AbookSettings $pa Array with the settings for the new addressbook
     */
    private function insertAddressbook(array $pa): void
    {
        $db = Config::inst()->db();

        // check parameters
        if (isset($pa['password'])) {
            $pa['password'] = $this->encryptPassword($pa['password']);
        }

        $pa['user_id'] = (string) $_SESSION['user_id'];
        $pa['sync_token'] = '';

        // required fields
        $qf = ['name','username','password','url','user_id','sync_token'];
        $qv = [];
        foreach ($qf as $f) {
            if (!isset($pa[$f])) {
                throw new \Exception("Required parameter $f not provided for new addressbook");
            }
            $v = $pa[$f];
            if (is_bool($v)) {
                $qv[] = $v ? '1' : '0';
            } else {
                $qv[] = (string) $pa[$f];
            }
        }

        // optional fields
        $qfo = ['active','presetname','use_categories','refresh_time'];
        foreach ($qfo as $f) {
            if (isset($pa[$f])) {
                $qf[] = $f;

                $v = $pa[$f];
                if (is_bool($v)) {
                    $qv[] = $v ? '1' : '0';
                } else {
                    $qv[] = (string) $pa[$f];
                }
            }
        }

        $db->insert("addressbooks", $qf, [$qv]);
        $this->abooksDb = null;
    }

    /**
     * This function read and caches the admin settings from config.inc.php.
     *
     * Upon first call, the config file is read and the result is cached and returned. On subsequent calls, the cached
     * result is returned without reading the file again.
     */
    private function readAdminSettings(): void
    {
        $logger = Config::inst()->logger();
        $httpLogger = Config::inst()->httpLogger();
        $prefs = [];
        $configfile = dirname(__FILE__) . "/config.inc.php";
        if (file_exists($configfile)) {
            include($configfile);
        }

        // Extract global preferences
        if (isset($prefs['_GLOBAL']['pwstore_scheme']) && is_string($prefs['_GLOBAL']['pwstore_scheme'])) {
            $scheme = $prefs['_GLOBAL']['pwstore_scheme'];

            if (in_array($scheme, self::PWSTORE_SCHEMES)) {
                /** @var PasswordStoreScheme $scheme */
                $this->pwStoreScheme = $scheme;
            }
        }

        $this->forbidCustomAddressbooks = ($prefs['_GLOBAL']['fixed'] ?? false) ? true : false;
        $this->hidePreferences = ($prefs['_GLOBAL']['hide_preferences'] ?? false) ? true : false;

        foreach (['loglevel' => $logger, 'loglevel_http' => $httpLogger] as $setting => $loggerobj) {
            if (isset($prefs['_GLOBAL'][$setting]) && is_string($prefs['_GLOBAL'][$setting])) {
                if ($loggerobj instanceof RoundcubeLogger) {
                    $loggerobj->setLogLevel($prefs['_GLOBAL'][$setting]);
                }
            }
        }

        // Store presets
        foreach ($prefs as $presetname => $preset) {
            // _GLOBAL contains plugin configuration not related to an addressbook preset - skip
            if ($presetname === '_GLOBAL') {
                continue;
            }

            if (!is_string($presetname) || empty($presetname)) {
                $logger->error("A preset key must be a non-empty string - ignoring preset!");
                continue;
            }

            if (!is_array($preset)) {
                $logger->error("A preset definition must be an array of settings - ignoring preset $presetname!");
                continue;
            }

            $this->addPreset($presetname, $preset);
        }

        // Extract filter for special addressbooks
        foreach (['collected_recipients', 'collected_senders'] as $setting) {
            if (isset($prefs['_GLOBAL'][$setting]) && is_array($prefs['_GLOBAL'][$setting])) {
                $matchSettings = $prefs['_GLOBAL'][$setting];

                if (
                    isset($matchSettings['preset'])
                    && is_string($matchSettings['preset'])
                    && key_exists($matchSettings['preset'], $this->presets)
                ) {
                    $presetname = $matchSettings['preset'];
                    $matchSettings2 = [ 'preset' => $presetname ];
                    foreach (['matchname', 'matchurl'] as $matchType) {
                        if (isset($matchSettings[$matchType]) && is_string($matchSettings[$matchType])) {
                            $matchexpr = $matchSettings[$matchType];
                            $matchSettings2[$matchType] = $matchexpr;
                        }
                    }

                    if ($this->presets[$presetname]['readonly'] ?? false) {
                        $logger->error("Cannot use addressbooks from read-only preset $presetname for $setting");
                    } else {
                        $this->specialAbookMatchers[$setting] = $matchSettings2;
                    }
                } else {
                    $logger->error("Setting for $setting must include a valid preset attribute");
                }
            }
        }
    }

    /**
     * Adds the given preset from config.inc.php to $this->presets.
     */
    private function addPreset(string $presetname, array $preset): void
    {
        $logger = Config::inst()->logger();

        // Resulting preset initialized with defaults
        $result = self::PRESET_TEMPLATE;

        try {
            foreach (array_keys($result) as $attr) {
                if ($attr == 'refresh_time') {
                    // refresh_time is stored in seconds
                    if (isset($preset["refresh_time"])) {
                        if (is_string($preset["refresh_time"])) {
                            $result["refresh_time"] = self::parseTimeParameter($preset["refresh_time"]);
                        } else {
                            $logger->error("Preset $presetname: setting $attr must be time string like 01:00:00");
                        }
                    }
                } elseif (is_bool($result[$attr])) {
                    if (isset($preset[$attr])) {
                        if (is_bool($preset[$attr])) {
                            $result[$attr] = $preset[$attr];
                        } else {
                            $logger->error("Preset $presetname: setting $attr must be boolean");
                        }
                    }
                } elseif (is_array($result[$attr])) {
                    if (isset($preset[$attr]) && is_array($preset[$attr])) {
                        foreach (array_keys($preset[$attr]) as $k) {
                            if (is_string($preset[$attr][$k])) {
                                $result[$attr][] = $preset[$attr][$k];
                            }
                        }
                    }
                } else {
                    if (isset($preset[$attr]) && is_string($preset[$attr])) {
                        $value = $preset[$attr];

                        if (($attr == 'rediscover_mode') && (!in_array($value, [ 'none', 'fulldiscovery' ]))) {
                            $logger->error("Preset $presetname: invalid value for setting $attr");
                        } else {
                            $result[$attr] = $value;
                        }
                    }
                }
            }

            /** @var Preset */
            $this->presets[$presetname] = $result;
        } catch (\Exception $e) {
            $logger->error("Error in preset $presetname: " . $e->getMessage());
        }
    }

    /**
     * Sets one of the special addressbooks supported by roundcube to one of the carddav addressbooks.
     *
     * These special addressbooks as of roundcube 1.5 are collected recipients and collected senders. The admin can
     * configure a match expression for the name or the URL of the addressbook, that is looked for in a specific preset.
     *
     * @param SpecialAbookType $type
     * @param SpecialAbookMatch $matchSettings
     */
    private function setSpecialAddressbook(string $type, array $matchSettings): void
    {
        $infra = Config::inst();
        $logger = $infra->logger();
        $presetname = $matchSettings['preset'];

        $matches = [];
        foreach ($this->getAddressbooks(true, true) as $abookrow) {
            // check all addressbooks for that preset
            if ($abookrow['presetname'] === $presetname) {
                // All specified matchers must match
                // If no matcher is set, any addressbook of the preset is considered a match
                $isMatch = true;

                foreach (['matchname', 'matchurl'] as $matchType) {
                    $matchexpr = $matchSettings[$matchType] ?? 0;
                    if (is_string($matchexpr)) {
                        $matchexpr = self::replacePlaceholdersUrl($matchexpr, true);
                        if (!preg_match($matchexpr, (string) $abookrow[substr($matchType, 5)])) {
                            $isMatch = false;
                        }
                    }
                }

                if ($isMatch) {
                    $matches[] = $abookrow['id'];
                }
            }
        }

        $numMatches = count($matches);
        if ($numMatches != 1) {
            $logger->error("Cannot set special addressbook $type, there are $numMatches candidates (need: 1)");
            return;
        }

        $config = rcube::get_instance()->config;
        $config->set($type, "carddav_" . $matches[0]);
    }

    // password helpers
    private function getDesKey(): string
    {
        $rcube = rcube::get_instance();

        // if the user logged in via OAuth, we do not have a password to use for encryption / decryption of carddav
        // passwords; roundcube sets SESSION[password] to the encrypted 'Bearer <accesstoken>', so we need to
        // specifically check if oauth is used for login
        if (isset($_SESSION['oauth_token'])) {
            throw new \Exception("No password available to use for encryption because user logged in via OAuth2");
        }

        $imap_password = $rcube->decrypt((string) $_SESSION['password']);
        if ($imap_password === false || strlen($imap_password) == 0) {
            throw new \Exception("No password available to use for encryption");
        }

        while (strlen($imap_password) < 24) {
            $imap_password .= $imap_password;
        }
        return substr($imap_password, 0, 24);
    }

    /**
     * Determines the addressbooks to add for a given URI.
     *
     * We perform discovery to determine all the user's addressbooks.
     *
     * If the given URI might point to an addressbook directly (i.e. it has a non-empty path), we check if it is
     * contained in the discovered addressbooks. If it is not, we check if it actually points to an addressbook. If it
     * does, we add ONLY this addressbook, not the discovered ones.
     *
     * We need to perform the discovery to determine where the user's own addressbooks live (addressbook home).
     *
     * See https://github.com/mstilkerich/rcmcarddav/issues/339 for rationale.
     *
     * @param Account $account The account to discover the addressbooks for. The discovery URI is assumed as user input.
     * @return list<AddressbookCollection> The determined addressbooks, possible empty.
     */
    private function determineAddressbooksToAdd(Account $account): array
    {
        $infra = Config::inst();
        $logger = $infra->logger();

        $uri = $account->getDiscoveryUri();

        $discover = $infra->makeDiscoveryService();
        $abooks = $discover->discoverAddressbooks($account);

        foreach ($abooks as $abook) {
            // If the discovery URI points to an addressbook that was also discovered, we use the discovered results
            // We deliberately only compare the path components, as the server part may contain a port (or not) or even
            // be a different server name after discovery, but the path part should be "unique enough"
            if (self::compareUrlPaths($uri, $abook->getUri())) {
                return $abooks;
            }
        }

        // If the discovery URI points to an addressbook that is not part of the discovered ones, we only use that
        // addressbook
        try {
            $directAbook = $infra->makeWebDavResource($uri, $account);
            if ($directAbook instanceof AddressbookCollection) {
                $logger->debug("Only adding non-individual addressbook $uri");
                return [ $directAbook ];
            }
        } catch (\Exception $e) {
        }

        return $abooks;
    }

    /**
     * Returns all the users addressbooks, optionally filtered.
     *
     * @param $activeOnly If true, only the active addressbooks of the user are returned.
     * @param $presetsOnly If true, only the addressbooks created from an admin preset are returned.
     * @return array<string, FullAbookRow>
     */
    private function getAddressbooks(bool $activeOnly = true, bool $presetsOnly = false): array
    {
        if (!isset($this->abooksDb)) {
            $db = Config::inst()->db();

            $this->abooksDb = [];
            /** @var FullAbookRow $abookrow */
            foreach ($db->get(['user_id' => (string) $_SESSION['user_id']], [], 'addressbooks') as $abookrow) {
                $this->abooksDb[$abookrow["id"]] = $abookrow;
            }
        }

        $result = $this->abooksDb;

        if ($activeOnly) {
            $result = array_filter($result, function (array $v): bool {
                return $v["active"] == "1";
            });
        }

        if ($presetsOnly) {
            $result = array_filter($result, function (array $v): bool {
                return !empty($v["presetname"]);
            });
        }

        return $result;
    }

    /**
     * Resyncs the given addressbook and displays a popup message about duration.
     *
     * @param Addressbook $abook The addressbook object
     */
    private function resyncAddressbook(Addressbook $abook): void
    {
        try {
            // To avoid unneccessary work followed by roll back with other time-triggered refreshes, we temporarily
            // set the last_updated time such that the next due time will be five minutes from now
            $ts_delay = time() + 300 - $abook->getRefreshTime();
            $db = Config::inst()->db();
            $db->update($abook->getId(), ["last_updated"], [(string) $ts_delay], "addressbooks");
            $duration = $abook->resync();

            $rcube = \rcube::get_instance();

            if ($duration >= 0) {
                $rcube->output->show_message(
                    $this->gettext([
                        'name' => 'cd_msg_synchronized',
                        'vars' => [
                            'name' => $abook->get_name(),
                            'duration' => $duration,
                        ]
                    ])
                );
            } else {
                /** @psalm-var null|array{message:string, type:int} */
                $errorArray = $abook->get_error();
                $errmsg = $errorArray['message'] ?? '';

                $rcube->output->show_message(
                    $this->gettext([
                        'name' => 'cd_msg_syncfailed',
                        'vars' => [
                            'name' => $abook->get_name(),
                            'errormsg' => $errmsg,
                        ]
                    ]),
                    'error'
                );
            }
        } catch (\Exception $e) {
            $logger = Config::inst()->logger();
            $logger->error("Failed to sync addressbook: " . $e->getMessage());
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
