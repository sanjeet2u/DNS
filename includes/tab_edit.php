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

require_once("common_ui.php");
require_once("modify.php");
require_once("zones.php");

class TabEdit
{
    //! This function checks if there is a request to edit any record in POST data and if yes, it processes it
    public static function Process($form)
    {
        global $g_domains;
        if (!isset($_POST["submit"]))
            return;
        
        $zone = $_POST["zone"];

        if (!Zones::IsEditable($zone))
            Error("Domain $zone is not writeable");

        if (!IsAuthorizedToWrite($zone))
            Error("You are not authorized to edit $zone");

        if (!CheckEmpty($form, $zone, "Zone"))
            return;
        $record = $_POST["record"];
        if ($record === NULL)
            $record = "";
        $ttl = $_POST["ttl"];
        if (!CheckEmpty($form, $ttl, "ttl"))
            return;
        $value = $_POST["value"];
        if (!CheckEmpty($form, $value, "Value"))
            return;
        $type = $_POST["type"];
        if (!CheckEmpty($form, $type, "Type"))
            return;

        if (!IsValidRecordType($type))
            Error("Type $type is not a valid DNS record type");

        if (!is_numeric($ttl))
            Error('TTL must be a number');

        $input = "server " . $g_domains[$zone]['update_server'] . "\n";
        $comment = NULL;
        if (isset($_POST["comment"]))
            $comment = $_POST["comment"];

        if ($_POST['submit'] == 'Create')
        {
            $input .= ProcessInsertFromPOST($zone, $record, $value, $type, $ttl);
            $input .= "send\nquit\n";
            $result = ProcessNSUpdateForDomain($input, $zone);
            if (strlen($result) > 0)
                Debug("result: " . $result);
            WriteToAuditFile('create', $record . "." . $zone . " " . $ttl . " " . $type . " " . $value, $comment);
            $form->AppendObject(new BS_Alert("Successfully inserted record " . $record . "." . $zone));
        } else if ($_POST["submit"] == "Edit")
        {
            if (!isset($_POST["old"]))
                Error("Missing old record necessary for update");
            // First delete the existing record
            $input .= "update delete " . $_POST["old"] . "\n";
            $input .= ProcessInsertFromPOST($zone, $record, $value, $type, $ttl);
            $input .= "send\nquit\n";
            $result = ProcessNSUpdateForDomain($input, $zone);
            if (strlen($result) > 0)
                Debug("result: " . $result);
            WriteToAuditFile('replace_delete', $_POST["old"], $comment);
            WriteToAuditFile('replace_create', $record . "." . $zone . " " . $ttl . " " . $type . " " . $value, $comment);
            $form->AppendObject(new BS_Alert("Successfully replaced " . $_POST["old"] . " with " . $record . "." . $zone . " " .
                                            $ttl . " " . $type . " " . $value));
        } else
        {
            Error("Unknown modify mode");
        }

        // Create PTR if wanted
        if (isset($_POST['ptr']) && $_POST['ptr'] === "true")
        {
            Debug('PTR record was requested, checking zone name');
            if ($type !== "A")
            {
                DisplayWarning('PTR record was not created: PTR record can be only created when you are inserting A record, you created ' . $type . ' record instead');
                return;
            }
            $ip_parts = explode('.', $value);
            if (count($ip_parts) != 4)
            {
                DisplayWarning('PTR record was not created: record '. $value .' is not a valid IPv4 quad');
                return;
            }
            $arpa = $ip_parts[3] . '.' . $ip_parts[2] . '.' . $ip_parts[1] . '.' . $ip_parts[0] . '.in-addr.arpa';
            $arpa_zone = Zones::GetZoneForFQDN($arpa);
            if ($arpa_zone === NULL)
            {
                DisplayWarning('PTR record was not created: there is no PTR zone for record '. $value);
                return;
            }
            if (!Zones::IsEditable($arpa_zone))
            {
                DisplayWarning('PTR record was not created: zone ' . $arpa_zone . ' is read only');
                return;
            }
            if (!IsAuthorizedToWrite($arpa_zone))
            {
                DisplayWarning("PTR record was not created: you don't have write access to zone " . $arpa_zone);
                return;
            }
            Debug('Found PTR useable zone: ' . $arpa_zone);
            $arpa_value = $record . '.' . $zone . '.';

            // Let's insert this record
            $input = "server " . $g_domains[$arpa_zone]['update_server'] . "\n";
            $input .= ProcessInsertFromPOST(NULL, $arpa, $arpa_value, 'PTR', $ttl);
            $input .= "send\nquit\n";
            $result = ProcessNSUpdateForDomain($input, $arpa_zone);
            if (strlen($result) > 0)
                Debug("result: " . $result);
            WriteToAuditFile('create', $arpa . " " . $ttl . " PTR " . $arpa_value, $comment);
        }
    }

    public static function GetInsertForm($parent, $edit_mode = false, $default_key = "", $default_ttl = NULL, $default_type = "A", $default_value = "", $default_comment = "")
    {
        global $g_audit, $g_selected_domain, $g_domains, $g_editable, $g_default_ttl;

        // In case we are returning to insert form from previous insert, make default type the one we used before
        if (isset($_POST['type']))
            $default_type = $_POST['type'];
        if (psf_string_endsWith($g_selected_domain, ".in-addr.arpa"))
            $default_type = "PTR";
        
        // Reuse some values from previous POST request
        if (isset($_POST['comment']))
            $default_comment = $_POST['comment'];

        // If ttl is not specified use default one from config file
        if ($default_ttl === NULL)
            $default_ttl = strval($g_default_ttl);
        
        $form = new Form("index.php?action=new", $parent);
        $form->Method = FormMethod::Post;
        $layout = new HtmlTable($form);
        $layout->BorderSize = 0;
        $layout->Headers = [ "Record", "Zone", "TTL", "Type", "Value" ];
        if ($g_audit)
            $layout->Headers[] = 'Comment';
        $form_items = [];
        $form_items[] = new BS_TextBox("record", $default_key, NULL, $layout);
        $dl = new ComboBox("zone", $layout);
        if ($edit_mode)
        {
            if ($g_selected_domain === NULL)
            {
                Error("No domain selected");
            }
            $dl->AddDefaultValue($g_selected_domain, "." . $g_selected_domain);
            $dl->Enabled = false;
        } else
        {
            foreach ($g_domains as $key => $info)
            {
                if (!IsAuthorizedToWrite($key))
                    continue;
                if ($g_selected_domain == $key)
                    $dl->AddDefaultValue($key, "." . $key);
                else
                    $dl->AddValue($key, '.' . $key);
            }
        }
        $form_items[] = $dl;
        $form_items[] = new BS_TextBox("ttl", $default_ttl, NULL, $layout);
        $tl = new ComboBox("type", $layout);
        $types = $g_editable;
        foreach ($types as $type)
        {
            if ($default_type == $type)
                $tl->AddDefaultValue($type);
            else
                $tl->AddValue($type);
        }
        $form_items[] = $tl;
        $form_items[] = new BS_TextBox("value", $default_value, NULL, $layout);
        if ($g_audit)
        {
            $comment = new BS_TextBox("comment", $default_comment, NULL, $layout);
            $comment->Placeholder = 'Optional comment for audit log';
            $comment->Size = 80;
            $form_items[] = $comment;
        }
        $layout->AppendRow($form_items);
        if (!$edit_mode && Zones::HasPTRZones())
            $form->AppendObject(new BS_CheckBox("ptr", "true", false, NULL, $form, "Create PTR record for this IP (works only with A records)"));
        if (isset($_GET["old"]))
        $form->AppendObject(new Hidden("old", htmlspecialchars($_GET["old"])));
        if ($edit_mode)
            $form->AppendObject(new BS_Button("submit", "Edit"));
        else
            $form->AppendObject(new BS_Button("submit", "Create"));
        return $form;
    }

    public static function GetEditForm($parent)
    {
        global $g_selected_domain;
        $k = $_GET["key"];
        $suffix = $g_selected_domain;
        if (psf_string_endsWith($k, $suffix))
            $k = substr($k, 0, strlen($k) - strlen($suffix));
        if (psf_string_endsWith($k, $suffix . "."))
            $k = substr($k, 0, strlen($k) - strlen($suffix) - 1);
        while (psf_string_endsWith($k, "."))
            $k = substr($k, 0, strlen($k) - 1);

        return self::GetInsertForm($parent, true, $k, $_GET["ttl"], $_GET["type"], $_GET["value"]);
    }
}
