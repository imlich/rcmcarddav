<?php

/*
 * RCMCardDAV - CardDAV plugin for Roundcube webmail
 *
 * Copyright (C) 2011-2021 Benjamin Schieder <rcmcarddav@wegwerf.anderdonau.de>,
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

namespace MStilkerich\CardDavAddressbook4Roundcube\Frontend;

use MStilkerich\CardDavClient\{Account, AddressbookCollection};
use MStilkerich\CardDavAddressbook4Roundcube\{Addressbook, Config};
use MStilkerich\CardDavAddressbook4Roundcube\Db\AbstractDatabase;

/**
 * @psalm-type ConfigurablePresetAttr = 'name'|'url'|'username'|'password'|'active'|'refresh_time'|'rediscover_time'
 * @psalm-type Preset = array{
 *     name: string,
 *     url: string,
 *     username: string,
 *     password: string,
 *     active: bool,
 *     use_categories: bool,
 *     readonly: bool,
 *     rediscover_time: int,
 *     refresh_time: int,
 *     fixed: list<ConfigurablePresetAttr>,
 *     require_always: list<string>,
 *     hide: bool
 * }
 * @psalm-type AccountSettings = array{
 *     name?: string,
 *     username?: string,
 *     password?: string,
 *     url?: string,
 *     rediscover_time?: int,
 *     active?: bool,
 *     presetname?: string
 * }
 * @psalm-type AbookSettings = array{
 *     name?: string,
 *     url?: string,
 *     account_id?: string,
 *     active?: bool,
 *     refresh_time?: int,
 *     active?: bool,
 *     use_categories?: bool,
 *     sync_token?: string,
 *     discovered?: bool
 * }
 * @psalm-import-type FullAccountRow from AbstractDatabase
 * @psalm-import-type FullAbookRow from AbstractDatabase
 */
class AddressbookManager
{
    /**
     * @var AccountSettings Template for account settings from the settings page.
     *      The default values in this template also serve do determine the type (bool, int, string).
     */
    public const ACCOUNT_TEMPLATE = [
        // standard account settings
        'name' => '',
        'username' => '',
        'password' => '',
        'url' => '',
        'active' => true,
        'rediscover_time' => 86400,
    ];

    /**
     * @var AbookSettings Template for addressbook settings from the settings page.
     *      The default values in this template also serve do determine the type (bool, int, string).
     */
    public const ABOOK_TEMPLATE = [
        // standard addressbook settings
        'name' => '',
        'url' => '',
        'active' => true,
        'use_categories' => true,
        'refresh_time' => 3600,
        'discovered' => true,
    ];

    /**
     * @var Preset Template for a preset; has the standard account/addressbook settings plus some extra properties.
     *      The default values in this template also serve do determine the type (bool, int, string, array).
     */
    public const PRESET_TEMPLATE = self::ACCOUNT_TEMPLATE + self::ABOOK_TEMPLATE + [
        // extra settings for presets
        'readonly' => false,
        'hide' => false,
        'fixed' => [],
        'require_always' => [],
    ];

    /** @var ?array<string, FullAccountRow> $accountsDb
     *    Cache of the user's account DB entries. Associative array mapping account IDs to DB rows.
     */
    private $accountsDb = null;

    /** @var ?array<string, FullAbookRow> $abooksDb
     *    Cache of the user's addressbook DB entries. Associative array mapping addressbook IDs to DB rows.
     */
    private $abooksDb = null;

    /**
     * Retrieves an addressbook by its database ID.
     *
     * @param string $abookId ID of the addressbook
     * @return Addressbook The addressbook object.
     * @throws \Exception If no addressbook with the given ID exists for this user.
     */
    public function getAddressbook(string $abookId): Addressbook
    {
        $infra = Config::inst();
        $admPrefs = $infra->admPrefs();

        $config = $this->getAddressbookConfig($abookId);
        $account = $this->getAccountConfig($config["account_id"]);
        $presetname = $account["presetname"] ?? ""; // empty string is not a valid preset name

        $readonly = !empty($admPrefs->presets[$presetname]["readonly"] ?? '0');
        $requiredProps = $admPrefs->presets[$presetname]["require_always"] ?? [];

        $config['username'] = Utils::replacePlaceholdersUsername($account["username"]);
        $config['password'] = Utils::replacePlaceholdersPassword(Utils::decryptPassword($account["password"]));

        $abook = new Addressbook($abookId, $config, $readonly, $requiredProps);
        return $abook;
    }

    /**
     * Retrieves an account configuration (database row) by its database ID.
     *
     * @param string $accountId ID of the account
     * @return FullAccountRow The addressbook config.
     * @throws \Exception If no account with the given ID exists for this user.
     */
    public function getAccountConfig(string $accountId): array
    {
        $accounts = $this->getAccounts(false);

        // check that this addressbook ID actually refers to one of the user's addressbooks
        if (!isset($accounts[$accountId])) {
            throw new \Exception("No carddav account with ID $accountId");
        }

        return $accounts[$accountId];
    }

    /**
     * Retrieves an addressbook configuration (database row) by its database ID.
     *
     * @param string $abookId ID of the addressbook
     * @return FullAbookRow The addressbook config.
     * @throws \Exception If no addressbook with the given ID exists for this user.
     */
    public function getAddressbookConfig(string $abookId): array
    {
        $abooks = $this->getAddressbooks(false);

        // check that this addressbook ID actually refers to one of the user's addressbooks
        if (!isset($abooks[$abookId])) {
            throw new \Exception("No carddav addressbook with ID $abookId");
        }

        return $abooks[$abookId];
    }

    /**
     * Updates some settings of an account in the database.
     *
     * If the given account ID does not refer to an account of the logged in user, nothing is changed.
     *
     * @param string $accountId ID of the account
     * @param AccountSettings $pa Array with the settings to update
     */
    public function updateAccount(string $accountId, array $pa): void
    {
        // encrypt the password before storing it
        if (isset($pa['password'])) {
            $pa['password'] = Utils::encryptPassword($pa['password']);
        }

        // optional fields
        $qf = [];
        $qv = [];

        foreach (array_keys(self::ACCOUNT_TEMPLATE) as $f) {
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

        $userId = (string) $_SESSION['user_id'];
        if (!empty($qf) && !empty($userId)) {
            $db = Config::inst()->db();
            $db->update(['id' => $accountId, 'user_id' => $userId], $qf, $qv, "accounts");
            $this->accountsDb = null;
        }
    }

    /**
     * Updates some settings of an addressbook in the database.
     *
     * If the given addresbook ID does not refer to an addressbook of the logged in user, nothing is changed.
     *
     * @param string $abookId ID of the addressbook
     * @param AbookSettings $pa Array with the settings to update
     */
    public function updateAddressbook(string $abookId, array $pa): void
    {
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

        $accountIds = array_column($this->getAccounts(false), 'id');
        if (!empty($qf) && !empty($accountIds)) {
            $db = Config::inst()->db();
            $db->update(['id' => $abookId, 'account_id' => $accountIds], $qf, $qv, "addressbooks");
            $this->abooksDb = null;
        }
    }

    /**
     * Deletes the given account from the database.
     * @param string $accountId ID of the account
     */
    public function deleteAccount(string $accountId): void
    {
        $infra = Config::inst();
        $logger = $infra->logger();
        $db = $infra->db();

        try {
            $db->startTransaction(false);

            // getAccountConfig() throws an exception if the ID is invalid / no account of the current user
            $this->getAccountConfig($accountId);

            $abookIds = array_column(
                array_filter(
                    $this->getAddressbooks(false),
                    function (array $v) use ($accountId): bool {
                        return $v["account_id"] == $accountId;
                    }
                ),
                'id'
            );

            // we explicitly delete all data belonging to the account, since
            // cascaded deletes are not supported by all database backends
            foreach ($abookIds as $abookId) {
                $this->deleteAddressbook($abookId);
            }

            $db->delete($accountId, 'accounts');

            $db->endTransaction();
        } catch (\Exception $e) {
            $logger->error("Could not delete account " . $e->getMessage());
            $db->rollbackTransaction();
            throw $e;
        } finally {
            $this->abooksDb = null;
        }
    }

    /**
     * Deletes the given addressbook from the database.
     * @param string $abookId ID of the addressbook
     * @param bool   $skipTransaction If true, perform the operations without starting a transaction. Useful if the
     *                                operation is called as part of an enclosing transaction.
     */
    public function deleteAddressbook(string $abookId, bool $skipTransaction = false): void
    {
        $infra = Config::inst();
        $logger = $infra->logger();
        $db = $infra->db();

        try {
            if (!$skipTransaction) {
                $db->startTransaction(false);
            }

            // we explicitly delete all data belonging to the addressbook, since
            // cascaded deletes are not supported by all database backends
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

            $db->delete($abookId, 'addressbooks');

            if (!$skipTransaction) {
                $db->endTransaction();
            }
        } catch (\Exception $e) {
            $logger->error("Could not delete addressbook: " . $e->getMessage());
            if (!$skipTransaction) {
                $db->rollbackTransaction();
            }
            throw $e;
        } finally {
            $this->abooksDb = null;
        }
    }

    /**
     * Inserts a new account into the database.
     * @param AccountSettings $pa Array with the settings for the new account
     * @return string Database ID of the newly created account
     */
    public function insertAccount(array $pa): string
    {
        $db = Config::inst()->db();

        // check parameters
        if (isset($pa['password'])) {
            $pa['password'] = Utils::encryptPassword($pa['password']);
        }
        $pa['user_id'] = (string) $_SESSION['user_id'];

        // required fields
        $qf = ['name','username','password','url','user_id'];
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
        $qfo = ['active','presetname','rediscover_time'];
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

        $accountId = $db->insert("accounts", $qf, [$qv]);
        $this->accountsDb = null;
        return $accountId;
    }

    /**
     * Inserts a new addressbook into the database.
     * @param AbookSettings $pa Array with the settings for the new addressbook
     * @return string Database ID of the newly created addressbook
     */
    public function insertAddressbook(array $pa): string
    {
        $db = Config::inst()->db();

        // required fields
        $qf = ['name','url','account_id'];
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
        $qfo = ['active','use_categories','refresh_time','sync_token','discovered'];
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

        $abookId = $db->insert("addressbooks", $qf, [$qv]);
        $this->abooksDb = null;
        return $abookId;
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
    public function determineAddressbooksToAdd(Account $account): array
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
            if (Utils::compareUrlPaths($uri, $abook->getUri())) {
                return $abooks;
            }
        }

        // If the discovery URI points to an addressbook that is not part of the discovered ones, we only use that
        // addressbook
        try {
            // FIXME replace this with an option for admin/user to manually add additional addressbooks to an account
            $directAbook = $infra->makeWebDavResource($uri, $account);
            if ($directAbook instanceof AddressbookCollection) {
                $logger->debug("Only adding non-individual addressbook $uri");
                return [ $directAbook ];
            }
        } catch (\Exception $e) {
            // it is expected that we might have an error here if the URI given for discovery does not refer to an
            // accessible WebDAV resource
        }

        return $abooks;
    }

    /**
     * Returns all the user's accounts, optionally filtered.
     *
     * @param $activeOnly If true, only the active accounts of the user are returned.
     * @param $presetsOnly If true, only the accounts created from an admin preset are returned.
     * @return array<string, FullAccountRow>
     */
    public function getAccounts(bool $activeOnly = true, bool $presetsOnly = false): array
    {
        $db = Config::inst()->db();

        if (!isset($this->accountsDb)) {
            $this->accountsDb = [];
            /** @var FullAccountRow $accrow */
            foreach ($db->get(['user_id' => (string) $_SESSION['user_id']], [], 'accounts') as $accrow) {
                $this->accountsDb[$accrow["id"]] = $accrow;
            }
        }

        $result = $this->accountsDb;

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
     * Returns all the user's addressbooks, optionally filtered.
     *
     * @param $activeOnly If true, only the active addressbooks of the user are returned.
     * @param $presetsOnly If true, only the addressbooks created from an admin preset are returned.
     * @return array<string, FullAbookRow>
     */
    public function getAddressbooks(bool $activeOnly = true, bool $presetsOnly = false): array
    {
        $db = Config::inst()->db();

        $allAccountIds = array_keys($this->getAccounts(false));
        $accounts = $this->getAccounts($activeOnly, $presetsOnly);

        if (!isset($this->abooksDb)) {
            $this->abooksDb = [];
            /** @var FullAbookRow $abookrow */
            foreach ($db->get(['account_id' => $allAccountIds], [], 'addressbooks') as $abookrow) {
                $this->abooksDb[$abookrow["id"]] = $abookrow;
            }
        }

        $result = array_filter($this->abooksDb, function (array $v) use ($accounts): bool {
            return isset($accounts[$v["account_id"]]);
        });

        if ($activeOnly) {
            $result = array_filter($result, function (array $v): bool {
                return $v["active"] == "1";
            });
        }

        return $result;
    }

    /**
     * Resyncs the given addressbook and displays a popup message about duration.
     *
     * @param Addressbook $abook The addressbook object
     */
    public function resyncAddressbook(Addressbook $abook): void
    {
        $infra = Config::inst();
        $rc = $infra->rc();

        try {
            // To avoid unneccessary work followed by roll back with other time-triggered refreshes, we temporarily
            // set the last_updated time such that the next due time will be five minutes from now
            $ts_delay = time() + 300 - $abook->getRefreshTime();
            $db = $infra->db();
            $db->update($abook->getId(), ["last_updated"], [(string) $ts_delay], "addressbooks");
            $duration = $abook->resync();

            $rc->showMessage(
                $rc->locText(
                    'cd_msg_synchronized',
                    [ 'name' => $abook->get_name(), 'duration' => (string) $duration ]
                ),
                'notice',
                false
            );
        } catch (\Exception $e) {
            $logger = $infra->logger();
            $logger->error("Failed to sync addressbook: {$e->getMessage()}");
            $rc->showMessage(
                $rc->locText(
                    'cd_msg_syncfailed',
                    [ 'name' => $abook->get_name(), 'errormsg' => $e->getMessage() ]
                ),
                'warning',
                false
            );
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
