<?php

use ohmy\Auth2;

class PoniAuth {
    var $config;
    var $access_token;

    function __construct($config) {
        $this->config = $config;
    }

    function triggerAuth() {
        $self = $this;
        return Auth2::legs(3)
            ->set('id', $this->config->get('poni-client-id'))
            ->set('secret', $this->config->get('poni-client-secret'))
            ->set('redirect', 'https://' . $_SERVER['HTTP_HOST']
                . ROOT_PATH . 'api/auth/ext')
            ->set('scope', 'basic')

            ->authorize('https://poniverse.net/oauth/authorize')
            ->access('https://poniverse.net/oauth/access_token')

            ->finally(function($data) use ($self) {
                $self->access_token = $data['access_token'];
            });
    }
}

class PoniStaffAuthBackend extends ExternalStaffAuthenticationBackend {
    static $id = "poniverse";
    static $name = "Poniverse";

    static $sign_in_image_url = "https://support.poniverse.net/images/poni_sign_in.png";
    static $service_name = "Poniverse";

    var $config;

    function __construct($config) {
        $this->config = $config;
        $this->poni = new PoniAuth($config);
    }

    function signOn() {
        // TODO: Check session for auth token
        if (isset($_SESSION[':oauth']['email'])) {
            if (($staff = StaffSession::lookup(array('email' => $_SESSION[':oauth']['email'])))
                && $staff->getId()
            ) {
                if (!$staff instanceof StaffSession) {
                    // osTicket <= v1.9.7 or so
                    $staff = new StaffSession($user->getId());
                }
                return $staff;
            }
            else
                $_SESSION['_staff']['auth']['msg'] = 'Have your administrator create a local account';
        }
    }

    static function signOut($user) {
        parent::signOut($user);
        unset($_SESSION[':oauth']);
    }


    function triggerAuth() {
        parent::triggerAuth();
        $poni = $this->poni->triggerAuth();
        $poni->GET(
            "https://api.poniverse.net/v1/users/me?access_token="
                . $this->poni->access_token)
            ->then(function($response) {
                require_once INCLUDE_DIR . 'class.json.php';
                if ($json = JsonDataParser::decode($response->text))
                    $_SESSION[':oauth']['email'] = $json['email'];
                Http::redirect(ROOT_PATH . 'scp');
            }
        );
    }
}

class PoniClientAuthBackend extends ExternalUserAuthenticationBackend {
    static $id = "poniverse.client";
    static $name = "Poniverse";

    static $sign_in_image_url = "https://support.poniverse.net/images/poni_sign_in.png";
    static $service_name = "Poniverse";

    function __construct($config) {
        $this->config = $config;
        $this->poni = new PoniAuth($config);
    }

    function supportsInteractiveAuthentication() {
        return false;
    }

    function signOn() {
        // TODO: Check session for auth token
        if (isset($_SESSION[':oauth']['email'])) {
            if (($acct = ClientAccount::lookupByUsername($_SESSION[':oauth']['email']))
                    && $acct->getId()
                    && ($client = new ClientSession(new EndUser($acct->getUser()))))
                return $client;

            elseif (isset($_SESSION[':oauth']['profile'])) {
                // TODO: Prepare ClientCreateRequest
                $profile = $_SESSION[':oauth']['profile'];
                $info = array(
                    'email' => $_SESSION[':oauth']['email'],
                    'name' => $profile['displayName'],
                );
                return new ClientCreateRequest($this, $info['email'], $info);
            }
        }
    }

    static function signOut($user) {
        parent::signOut($user);
        unset($_SESSION[':oauth']);
    }

    function triggerAuth() {
        require_once INCLUDE_DIR . 'class.json.php';
        parent::triggerAuth();
        $poni = $this->poni->triggerAuth();
        $token = $this->poni->access_token;
        $poni->GET(
            "https://api.poniverse.net/v1/users/me?access_token="
                . $token)
            ->then(function($response) use ($poni, $token) {
                if (!($json = JsonDataParser::decode($response->text)))
                    return;
                $_SESSION[':oauth']['email'] = $json['email'];
                $_SESSION[':oauth']['profile'] = $json;
                Http::redirect(ROOT_PATH . 'login.php');
            }
        );
    }
}


