<?php

namespace PHP\Classes\Api;

use CarbonPHP\Error\ErrorCatcher;
use Variables;
use PHP\Errors\Exception;
use PHP\SQL\Traits\SocialPlatformLogInQueries;
use Throwable;

/**
 * Facebook Api Class
 *
 * Handle everything related to using Facebook to Log Into the site
 *
 */
class FacebookApi
{

    public const FACEBOOK_API_DOMAIN = 'https://graph.facebook.com/v11.0';
    public const FACEBOOK_OAUTH_URL = 'https://www.facebook.com/v11.0/dialog/oauth';

    private const FACEBOOK_CLIENT_ID = '';
    private const FACEBOOK_CLIENT_SECRET = '';

    /**
     *
     * @return string
     */
    public static function facebookGenerateState(): string
    {

        // makes sure state is unique everytime
        $microtime = microtime(true);
        $randomString = md5($microtime . mt_rand());

        $_SESSION['oauth']['url_facebook_state'][$microtime] = $randomString;

        return $randomString;

    }

    /**
     * Lets facebook know we are trying to authenticate a user
     * They then give us a code and state back, we'll take that and exchange that for an access token
     *
     * @param $redirectURL
     * @return string
     */
    public static function getFacebookLoginUrl($redirectURL): string
    {

        $endpoint = self::FACEBOOK_OAUTH_URL;

        $the_state = self::facebookGenerateState();

        $params = [
            'client_id' => self::FACEBOOK_CLIENT_ID,
            'redirect_uri' => $redirectURL,
            'response_type' => 'code',
            'scope' => 'public_profile email',
            'state' => $the_state
        ];

        return $endpoint . '?' . http_build_query($params);

    }

    /**
     * Tries to log the user in with Facebook, uses access code method
     *
     * @param $code
     * @return void
     */
    public static function tryAndLoginWithFacebook($code): void
    {

        if (false === empty($code)) {

            self::getFacebookAccessToken($code);

        }

    }

    /**
     * Exchanges code and state for a facebook access token
     *
     * @param $code
     * @return mixed
     */
    public static function getFacebookAccessToken($code): void
    {

        // website url https://www.company.com
        $curlRedirectToHomeUrl = 'https://www.company.com';
        $access_code = $code;
        $facebookCurlURL = self::FACEBOOK_API_DOMAIN . '/oauth/access_token';

        // data used to get access token
        $token_data = [
            "client_id" => self::FACEBOOK_CLIENT_ID,
            "client_secret" => self::FACEBOOK_CLIENT_SECRET,
            "grant_type" => "authorization_code",
            "code" => $access_code,
            "redirect_uri" => $curlRedirectToHomeUrl
        ];

        self::facebookMakeApiCall($facebookCurlURL, $token_data);

    }

    /**
     * @param $token
     */
    private static function facebookUserGetRequest($token): void
    {

        // url for getting users info
        $urlForUsersInfo = self::FACEBOOK_API_DOMAIN . '/me/';

        $curl_data = [
            'fields' => 'id,first_name,last_name,email,name',
            'access_token' => $token
        ];

        $_SESSION['facebook_user']['facebook_access_token'] = $token;

        self::facebookMakeApiCall($urlForUsersInfo, $curl_data);

    }

    /**
     * @param $facebookCurlURL
     * @param $token_data
     */
    private static function facebookMakeApiCall($facebookCurlURL, $token_data): void
    {

        try {

            $headers = ['Content-Type: application/json', 'Authorization: Bearer '];

            // starting curl request
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $facebookCurlURL);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($token_data));
            curl_setopt($curl, CURLOPT_TIMEOUT, 400);
            $response = curl_exec($curl);
            curl_close($curl);
            // end of curl request

            $facebookCurlResults = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

            if ($facebookCurlResults['access_token']) {

                $token = $facebookCurlResults['access_token'];
                self::facebookUserGetRequest($token);

            } else if ($facebookCurlResults['first_name']) {

                self::facebookAddUserInfoToDb($facebookCurlResults);

            }

        } catch (Throwable $exception) {

            ErrorCatcher::generateLog($exception, true);

            $message = 'Facebook failed to authenticate your request, please try again later.';
            Exception::printExceptionPage($message);

            exit(1);

        }

    }

    /**
     * Adds the user's info to the db
     *
     * @param $facebook_get_user_request_result
     */
    public static function facebookAddUserInfoToDb($facebook_get_user_request_result): void
    {

        // puts all of the user's info back from facebook into the session
        $session_facebook = $_SESSION['facebook_user'];
        $session_facebook['facebookUserInfo'] = $facebook_get_user_request_result;
        // user info
        $facebook_id = $session_facebook['facebookUserInfo']['id'];
        $facebook_first_name = $session_facebook['facebookUserInfo']['first_name'];
        $facebook_access_token = $session_facebook['facebook_access_token'];
        $facebook_user_email = $session_facebook['facebookUserInfo']['email'];
        $facebook_meta_key = 'facebook_access_token';

        if ([] === SocialPlatformLogInQueries::sql_rest_facebook_check_if_user_is_brand_new($facebook_user_email)) {

            // Checks to see if the user has any info in the users table
            SocialPlatformLogInQueries::sql_rest_insert_facebook_login($facebook_id, $facebook_first_name, $facebook_access_token, $facebook_user_email, $facebook_meta_key);
            $siteURL = 'https://www.company.com';

            safe_redirect(
                $siteURL .
                "?email=" . $facebook_user_email .
                "&type=registration" .
                "&from=Facebook" .
                "&result=new-user-no-facebook-info-stored"
            );

        } elseif ([] === SocialPlatformLogInQueries::sql_rest_facebook_check_if_user_has_facebook_email($facebook_user_email)) {

            // Checks to see that the user is a current user (has a WP ID) but has not yet connected account to Facebook API
            SocialPlatformLogInQueries::sql_rest_insert_facebook_login($facebook_id, $facebook_first_name, $facebook_access_token, $facebook_user_email, $facebook_meta_key);
            SocialPlatformLogInQueries::sql_rest_facebook_get_and_add_new_user_id($facebook_user_email);

            $siteURL = 'https://www.company.com';

            safe_redirect(
                $siteURL .
                "?type=login" .
                "&from=Facebook" .
                "&result=current-user-but-no-facebook-info-stored"
            );

        } else {

            $siteURL = 'https://www.company.com';

            safe_redirect(
                $siteURL .
                "?type=login" .
                "&from=Facebook" .
                "&result=current-user-with-facebook-info-stored"
            );

        }

    }

    /**
     * This func will check to see if there is a matching email in
     * the Facebook table and users table.
     * If there is a match then the users table ID is passed to the facebook table user ID slot
     * @param $facebook_user_email
     */
    public static function addUserIDToFacebookTable($facebook_user_email): void
    {

        SocialPlatformLogInQueries::sql_rest_facebook_get_and_add_new_user_id($facebook_user_email);

    }

} // end of class
