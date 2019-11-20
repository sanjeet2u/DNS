<?php

// This program is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.

// Security check
if (!defined('G_DNSTOOL_ENTRY_POINT'))
    die("Not a valid entry point");

require_once("debug.php");
require_once("logging.php");
require_once("caching_memcache.php");
require_once("caching_memcached.php");

function Initialize()
{
    OpenSyslog();
    InitializeCaching();
    RefreshSession();
}

function ResourceCleanup()
{
    CloseSyslog();
}

function InitializeCaching()
{
    global $g_caching_engine, $g_caching_engine_instance;
    switch ($g_caching_engine)
    {
        case NULL:
            $g_caching_engine_instance = new PHPDNS_CachingEngine();
            break;
        case 'memcache':
            $g_caching_engine_instance = new PHPDNS_CachingEngine_Memcache();
            break;
        case 'memcached':
            $g_caching_engine_instance = new PHPDNS_CachingEngine_Memcached();
            break;
        default:
            die('Invalid caching engine: ' . $g_caching_engine);
    }
    Debug('Caching engine: ' . $g_caching_engine_instance->GetEngineName());
    $g_caching_engine_instance->Initialize();
}

function DisplayWarning($text)
{
    if (G_DNSTOOL_ENTRY_POINT === "api.php")
        return;
    global $g_warning_container;
    $warning_box = new BS_Alert('<b>WARNING:</b> ' . htmlspecialchars($text), 'warning');
    $warning_box->EscapeHTML = false;
    $g_warning_container->AppendObject($warning_box);
}

function IsValidRecordType($type)
{
    global $g_editable;
    return in_array($type, $g_editable);
}

function IsEditable($domain)
{
    global $g_domains;
    if (!array_key_exists($domain, $g_domains))
        die("No such domain: $domain");

    $domain_info = $g_domains[$domain];

    if (array_key_exists('read_only', $domain_info) && $domain_info['read_only'] === true)
        return false;

    return true;
}

//! Return true if application supports and require user to login, no matter if current user
//! is logged in or not. Don't confuse with login.php's RequireLogin() which returns false
//! even when login is enabled in case user is already logged in
function LoginRequired()
{
    global $g_auth;
    if ($g_auth === NULL || $g_auth !== 'ldap')
        return false;
    return true;
}

function IsAuthorized($domain, $privilege)
{
    global $g_auth_roles, $g_auth_default_role, $g_auth_roles_map;

    if ($g_auth_roles === NULL || !LoginRequired())
        return true;

    $roles = [ $g_auth_default_role ];
    $user = $_SESSION['user'];
    if ($user === NULL || $user === '')
        Error('Invalid username in session');

    if (array_key_exists($user, $g_auth_roles_map))
        $roles = $g_auth_roles_map[$user];

    if (in_array('root', $roles))
        return true;

    foreach ($roles as $role)
    {
        if (!array_key_exists($role, $g_auth_roles))
            continue;
        $role_info = $g_auth_roles[$role];
        if (!array_key_exists($domain, $role_info))
            continue;
        $permissions = $role_info[$domain];
        if ($privilege == 'rw' && $permissions == 'rw')
            return true;
        if ($privilege == 'r' && ($permissions == 'rw' || $permissions == 'r'))
            return true;
    }

    return false;
}

function IsAuthorizedToRead($domain)
{
    return IsAuthorized($domain, 'r');
}

function IsAuthorizedToWrite($domain)
{
    return IsAuthorized($domain, 'rw');
}

function GetCurrentUserName()
{
    global $g_auth, $g_api_token_mask;
    if ($g_api_token_mask && isset($_SESSION["logged_in"]) && $_SESSION["logged_in"] === true && isset($_SESSION["token"]) && $_SESSION["token"] === true)
    {
        $trimmed_name = $_SESSION["user"];
        if (psf_string_contains($trimmed_name, '_'))
            $trimmed_name = substr($trimmed_name, 0, strrpos($trimmed_name, '_'));
        return $trimmed_name;
    }
    if ($g_auth === "ldap" && isset($_SESSION["user"]))
        return $_SESSION["user"];
    if (!isset($_SERVER['REMOTE_USER']))
        return "unknown user";
    return $_SERVER['REMOTE_USER'];
}

function GetZoneForFQDN($fqdn)
{
    global $g_domains;
    do
    {
        if (!array_key_exists($fqdn, $g_domains))
        {
            $fqdn= substr($fqdn, strpos($fqdn, '.') + 1);
            continue;
        }
        return $fqdn;
    } while (psf_string_contains($fqdn, '.'));
    return NULL;
}

function HasPTRZones()
{
    global $g_domains;
    foreach ($g_domains as $key => $info)
    {
        if (psf_string_endsWith($key, ".in-addr.arpa"))
            return true;
    }
    return false;
}
