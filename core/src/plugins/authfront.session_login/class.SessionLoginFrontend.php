<?php
/*
 * Copyright 2007-2013 Charles du Jeu - Abstrium SAS <team (at) pyd.io>
 * This file is part of Pydio.
 *
 * Pydio is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Pydio is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Pydio.  If not, see <http://www.gnu.org/licenses/>.
 *
 * The latest code can be found at <http://pyd.io/>.
 */
use Pydio\Core\Services\AuthService;
use Pydio\Authfront\Core\AbstractAuthFrontend;
use Pydio\Core\Services\ConfService;
use Pydio\Core\Utils\Utils;
use Pydio\Core\Controller\XMLWriter;
use Pydio\Core\Utils\CaptchaProvider;
use Pydio\Core\Controller\HTMLWriter;

defined('AJXP_EXEC') or die( 'Access not allowed');


class SessionLoginFrontend extends AbstractAuthFrontend {

    function isEnabled(){
        if(Utils::detectApplicationFirstRun()) return false;
        return parent::isEnabled();
    }

    function tryToLogUser(&$httpVars, $isLast = false){

        if(!isSet($httpVars["get_action"]) || $httpVars["get_action"] != "login"){
            return false;
        }
        $rememberLogin = "";
        $rememberPass = "";
        $secureToken = "";
        $loggedUser = null;
        if (AuthService::suspectBruteForceLogin() && (!isSet($httpVars["captcha_code"]) || !CaptchaProvider::checkCaptchaResult($httpVars["captcha_code"]))) {
            $loggingResult = -4;
        } else {
            $userId = (isSet($httpVars["userid"])?Utils::sanitize($httpVars["userid"], AJXP_SANITIZE_EMAILCHARS):null);
            $userPass = (isSet($httpVars["password"])?trim($httpVars["password"]):null);
            $rememberMe = ((isSet($httpVars["remember_me"]) && $httpVars["remember_me"] == "true")?true:false);
            $cookieLogin = (isSet($httpVars["cookie_login"])?true:false);
            $loggingResult = AuthService::logUser($userId, $userPass, false, $cookieLogin, $httpVars["login_seed"]);
            if ($rememberMe && $loggingResult == 1) {
                $rememberLogin = "notify";
                $rememberPass = "notify";
            }
            if ($loggingResult == 1) {
                session_regenerate_id(true);
                $secureToken = AuthService::generateSecureToken();
            }
            if ($loggingResult < 1 && AuthService::suspectBruteForceLogin()) {
                $loggingResult = -4; // Force captcha reload
            }
        }
        $loggedUser = AuthService::getLoggedUser();
        if ($loggedUser != null) {
            $force = $loggedUser->mergedRole->filterParameterValue("core.conf", "DEFAULT_START_REPOSITORY", AJXP_REPO_SCOPE_ALL, -1);
            $passId = -1;
            if (isSet($httpVars["tmp_repository_id"])) {
                $passId = $httpVars["tmp_repository_id"];
            } else if ($force != "" && $loggedUser->canSwitchTo($force) && !isSet($httpVars["tmp_repository_id"]) && !isSet($_SESSION["PENDING_REPOSITORY_ID"])) {
                $passId = $force;
            }
            $res = ConfService::switchUserToActiveRepository($loggedUser, $passId);
            if (!$res) {
                AuthService::disconnect();
                $loggingResult = -3;
            }
        }

        if ($loggedUser != null && (AuthService::hasRememberCookie() || (isSet($rememberMe) && $rememberMe ==true))) {
            AuthService::refreshRememberCookie($loggedUser);
        }
        XMLWriter::header();
        XMLWriter::loggingResult($loggingResult, $rememberLogin, $rememberPass, $secureToken);
        XMLWriter::close();

        if($loggingResult > 0 || $isLast){
            exit();
        }

    }

    public function switchAction($action, $httpVars, $fileVars)
    {
        switch ($action) {

            case "logout" :

                AuthService::disconnect();
                $loggingResult = 2;
                session_destroy();
                XMLWriter::header();
                XMLWriter::loggingResult($loggingResult, null, null, null);
                XMLWriter::close();

                break;

            case "get_seed" :
                $seed = AuthService::generateSeed();
                if (AuthService::suspectBruteForceLogin()) {
                    HTMLWriter::charsetHeader('application/json');
                    print json_encode(array("seed" => $seed, "captcha" => true));
                } else {
                    HTMLWriter::charsetHeader("text/plain");
                    print $seed;
                }
                break;

            case "get_captcha":
                CaptchaProvider::sendCaptcha();
                //exit(0) ;
                break;

            case "back":
                XMLWriter::header("url");
                echo AuthService::getLogoutAddress(false);
                XMLWriter::close("url");
                //exit(1);

                break;

            default;
                break;
        }
        return "";
    }



} 