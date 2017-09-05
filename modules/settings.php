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
#
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

require_once(__DIR__. '/../lib/SystemTasks.php');

/*
* POST /settings/language {"lang":"it"}
*/
$app->post('/settings/language', function (Request $request, Response $response, $args) {
    try {
        $data = $request->getParsedBody();
        $lang = $data['lang'];
        $st = new SystemTasks();
        if ($lang == 'en') {
            $task = $st->startTask("/usr/bin/sudo /usr/libexec/nethserver/pkgaction --install asterisk-sounds-extra-en-ulaw");
        } else {
            $task = $st->startTask("/usr/bin/sudo /usr/libexec/nethserver/pkgaction --install nethvoice-lang-$lang");
        }
        return $response->withJson(['result' => $task], 200);
    } catch (Exception $e) {
        error_log($e->getMessage());
        return $response->withStatus(500);
    }
});

/*
* POST /settings/defaultlanguage {"lang":"it"}
*/
$app->post('/settings/defaultlanguage', function (Request $request, Response $response, $args) {
    try {
        $data = $request->getParsedBody();
        $lang = $data['lang'];
        FreePBX::create()->Soundlang->setLanguage($lang);
        system('/var/www/html/freepbx/rest/lib/retrieveHelper.sh > /dev/null &');
    } catch (Exception $e) {
        error_log($e->getMessage());
        return $response->withStatus(500);
    }
});
