<?php

#
# Copyright (C) 2017 Nethesis S.r.l.
# http://www.nethesis.it - nethserver@nethesis.it
#
# This script is part of NethServer.
#
# NethServer is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License,
# or any later version.
#
# NethServer is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with NethServer.  If not, see COPYING.
#

function gateway_get_configuration($name){
    try{
        $dbh = FreePBX::Database();
        /*Check if config exists*/
        $sql = "SELECT `id`,`model_id`,`ipv4`,`ipv4_new`,`gateway`,`ipv4_green`,`netmask_green`,`mac` FROM `gateway_config` WHERE `name` = ?";
        $sth = FreePBX::Database()->prepare($sql);
        $sth->execute(array($name));
        $config = $sth->fetch(\PDO::FETCH_ASSOC);
        if ($config === false){
            /*Configuration doesn't exist*/
            error_log("Configuration not found");
            exit(1);
        }
        $sql = "SELECT `model`,`manufacturer` FROM `gateway_models` WHERE `id` = ?";
        $sth = FreePBX::Database()->prepare($sql);
        $sth->execute(array($config['model_id']));
        $res = $sth->fetch(\PDO::FETCH_ASSOC);
        $config['model'] = $res['model'];
        $config['manufacturer'] = $res['manufacturer'];
        $sql = "SELECT a.trunk,a.trunknumber AS trunknumber, secret, `protocol` FROM `gateway_config_isdn` AS a JOIN trunks AS b ON a.trunk=b.trunkid WHERE `config_id` = ?";
        $sth = FreePBX::Database()->prepare($sql);
        $sth->execute(array($config['id']));
        while ($row = $sth->fetch(\PDO::FETCH_ASSOC)){
            $config['trunks_isdn'][] = $row;
        }

        $sql = "SELECT a.trunk as trunk,a.trunknumber AS trunknumber, secret  FROM `gateway_config_pri` AS a JOIN trunks AS b ON a.trunk=b.trunkid WHERE `config_id` = ?";
        $sth = FreePBX::Database()->prepare($sql);
        $sth->execute(array($config['id']));
        while ($row = $sth->fetch(\PDO::FETCH_ASSOC)){
            $config['trunks_pri'][] = $row;
        }

        $sql = "SELECT a.trunk as trunk,a.trunknumber AS trunknumber,number, secret FROM `gateway_config_fxo` AS a JOIN trunks AS b ON a.trunk=b.trunkid WHERE `config_id` = ?";
        $sth = FreePBX::Database()->prepare($sql);
        $sth->execute(array($config['id']));
        while ($row = $sth->fetch(\PDO::FETCH_ASSOC)){
            $config['trunks_fxo'][] = $row;
        }
        $sql = "SELECT `physical_extension`,`secret` FROM `gateway_config_fxs` WHERE `config_id` = ?";
        $sth = FreePBX::Database()->prepare($sql);
        $sth->execute(array($config['id']));
        while ($row = $sth->fetch(\PDO::FETCH_ASSOC)){
            $config['trunks_fxs'][] = $row;
        }

        return $config;
    } catch (Exception $e){
        error_log($e->getMessage());
        exit(1);
    }
}

function gateway_generate_configuration_file($name){
    try{
        $config = gateway_get_configuration($name);
        # read template
        $template = "/var/www/html/freepbx/rest/lib/gateway/templates/{$config['manufacturer']}/{$config['model']}.txt";
        $handle = fopen($template, "r");
        ob_clean();
        ob_start();
        if ($handle) {
            while (!feof($handle)) {
                $buffer = fgets($handle, 4096);
                $output .= $buffer;
            }
            fclose($handle);
        } else {
            error_log("Template $template not found");
            return false;
        }
        # replace variables in template
        $output = str_replace("ASTERISKIP",$config['ipv4_green'],$output);
        $output = str_replace("GATEWAYIP",$config['ipv4_new'],$output);
        $output = str_replace("DEFGATEWAY",$config['gateway'],$output);
        $output = str_replace("NETMASK",$config['netmask_green'],$output);
        $output = str_replace("DATE",date ('d/m/Y G:i:s'),$output);

        #Generate trunks config
        if (!empty($config['trunks_fxo'])){
            $i = 1;
            $n_trunks = count($config['trunks_isdn']);
            if ($n_trunks>0) {
                $j = $n_trunks+1;
            } else {
                $j = 1;
            }
            foreach ($config['trunks_fxo'] as $trunk){
                $output = str_replace("LINENUMBER$i",$trunk['number'],$output);
                $output = str_replace("TRUNKNUMBER$j",$trunk['trunknumber'],$output);
                $output = str_replace("TRUNKSECRET$j",$trunk['secret'],$output);
                $i++;
                $j++;
            }
        }
        if (!empty($config['trunks_isdn'])){
            $i = 1;
            foreach ($config['trunks_isdn'] as $trunk) {
                $output = str_replace("TRUNKNUMBER$i",$trunk['trunknumber'],$output);
                $output = str_replace("TRUNKSECRET$i",$trunk['secret'],$output);

                if ($trunk['protocol']=="pp") {
                    if ($config['manufacturer'] == 'Sangoma') {
                        $output = str_replace("PROTOCOLTYPE$i","pp",$output);
                    } elseif ($config['manufacturer'] == 'Mediatrix') {
                        $output = str_replace("PROTOCOLTYPE$i","PointToPoint",$output);
                    } elseif ($config['manufacturer'] == 'Patton') {
                        $output = str_replace("PROTOCOLTYPE$i","protocol pp",$output);
                    }
                } elseif ($trunk['protocol']=="pmp") {
                    if ($config['manufacturer'] == 'Sangoma') {
                        $output = str_replace("PROTOCOLTYPE$i","pmp",$output);
                    } elseif ($config['manufacturer'] == 'Mediatrix') {
                        $output = str_replace("PROTOCOLTYPE$i","PointToMultiPoint",$output);
                    } elseif ($config['manufacturer'] == 'Patton' && preg_match ('/^TRI_/', $config['model'])) {
                        $output = str_replace("PROTOCOLTYPE$i","protocol pmp",$output);
                    } elseif ($config['manufacturer'] == 'Patton' && !preg_match ('/^TRI_/', $config['model'])){
                        $output = str_replace("PROTOCOLTYPE$i","",$output);
                    }
                }
                $i++;
            }
        }
        if (!empty($config['trunks_pri'])){
            $i = 1;
            foreach ($config['trunks_pri'] as $trunk){
                $output = str_replace("TRUNKNUMBER$i",$trunk['trunknumber'],$output);
                $output = str_replace("TRUNKSECRET$i",$trunk['secret'],$output);
                $i++;
            }
        }
        if (!empty($config['trunks_fxs'])){
            $i=0;
            foreach ($config['trunks_fxs'] as $trunk){
                $output = preg_replace("/FXSEXTENSION{$i}([^0-9])/",$trunk['physical_extension'].'\1',$output);
                $output = preg_replace("/FXSPASS{$i}([^0-9])/",$trunk['secret'].'\1',$output);
                $i++;
            }
        }


    } catch (Exception $e){
        error_log($e->getMessage());
        exit(1);
    }
    return $output;
}

function getPjSipDefaults() {
    return array(
        "aor_contact"=> "",
        "auth_rejection_permanent"=> "on",
        "authentication"=> "outbound",
        "client_uri"=> "",
        "codecs"=> "ulaw,alaw,gsm,g726",
        "contact_user"=> "",
        "context"=> "from-pstn",
        "continue"=> "off",
        "dialoutopts_cb"=> "sys",
        "dialoutprefix"=> "",
        "disabletrunk"=> "off",
        "dtmfmode"=> "rfc4733",
        "expiration"=> 3600,
        "extdisplay"=> "",
        "fax_detect"=> "no",
        "forbidden_retry_interval"=> 10,
        "from_domain"=> "",
        "from_user"=> "",
        "hcid"=> "on",
        "keepcid"=> "off",
        "language"=> "",
        "match"=> "",
        "max_retries"=> 10,
        "maxchans"=> "",
        "npanxx"=> "",
        "outbound_proxy"=> "",
        "outcid"=> "",
        "provider"=> "",
        "qualify_frequency"=> 60,
        "registration"=> "none",
        "retry_interval"=> 60,
        "secret"=> "",
        "sendrpid"=> "no",
        "server_uri"=> "",
        "sip_server"=> "",
        "sip_server_port"=> 5060,
        "sv_channelid"=> "",
        "sv_trunk_name"=> "",
        "sv_usercontext"=> "",
        "t38_udptl"=> "no",
        "t38_udptl_ec"=> "none",
        "t38_udptl_nat"=> "no",
        "tech"=> "pjsip",
        "transport"=> "0.0.0.0-udp",
        "trunk_name"=> "",
        "username"=> ""
    );
}