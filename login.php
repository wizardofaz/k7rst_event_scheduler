<?php
require_once 'config.php';
require_once 'logging.php';
require_once 'db.php';

function login($db_conn, $op_call, $op_name, $op_pw) {

    // password and login handling

    // Login related variables:
    // $_SESSION['authenticated_users'][$call]: table of users already authenticated in this session
    // $_SESSION['logged_in_call']: call of currently logged in user
    // $_SESSION['logged_in_name']: name of currently logged in user
    // $_SESSION['login_flash']: when set true will trigger a short "logged in" message 
    // $authorized: current user is authorized 

    if (!isset($_SESSION['authenticated_users'])) {
        $_SESSION['authenticated_users'] = [];
    }

    $authorized = false;

    if(isset($_SESSION['logged_in_call'])) {
        $op_call = $_SESSION['logged_in_call'];
        $op_name = $_SESSION['logged_in_name'];
    }

    if ($op_call !== '') {
        if (isset($_SESSION['authenticated_users'][$op_call]) 
            && $_SESSION['authenticated_users'][$op_call]
            && !$op_pw) {
            // this user successfully logged in earlier in this session - keep them logged in
            log_msg(DEBUG_INFO, "PW: $op_call has previously been authenticated");
            $authorized = true;
        } else {
            unset($_SESSION['authenticated_users'][$op_call]);
            // check for db password
            $db_stored_pw = db_get_operator_password($db_conn, $op_call);

            if ($db_stored_pw === null && !$op_pw) {
                // there is no db pw && no pw was entered, this user is authorized by default (I know, unconventional...)
                log_msg(DEBUG_INFO, "PW: No password exists in db for $op_call, login without pw is ok");
                $_SESSION['authenticated_users'][$op_call] = true;
                $_SESSION['logged_in_call'] = $op_call;
                $_SESSION['logged_in_name'] = $op_name;
                $_SESSION['login_flash'] = true;
                $authorized = true;
            } elseif ($db_stored_pw === $op_pw) {
                // input matches db
                log_msg(DEBUG_INFO, "PW: db password matches input for $op_call, login ok"); 
                $_SESSION['authenticated_users'][$op_call] = true;
                $_SESSION['logged_in_call'] = $op_call;
                $_SESSION['logged_in_name'] = $op_name;
                $_SESSION['login_flash'] = true;
                $authorized = true;
            } elseif (!$db_stored_pw && $op_pw) {
                // there is an input pw but no db pw: he gets logged in now and password gets stored
                log_msg(DEBUG_INFO, "PW: no previous db password, will store one now for $op_call, login ok"); 
                db_add_password($db_conn, $op_call, $op_pw);
                $_SESSION['authenticated_users'][$op_call] = true;
                $_SESSION['logged_in_call'] = $op_call;
                $_SESSION['logged_in_name'] = $op_name;
                $_SESSION['login_flash'] = true;
                $authorized = true;
            } else {	
                log_msg(DEBUG_ERROR, "PW: db password does not match input for $op_call, login failed"); 
                unset($_SESSION['authenticated_users'][$op_call]);
                unset($_SESSION['logged_in_call']);
                unset($_SESSION['logged_in_name']);
                $authorized = false;
            }

        }
    }	
    
    return $authorized;

}