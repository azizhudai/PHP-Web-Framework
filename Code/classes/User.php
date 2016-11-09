<?php

namespace Sonar;

require_once( "Config.php" );
require_once( "Cookie.php" );
require_once( "Error.php" );

class User extends __Error
{
    private $_db,
    		$_data,
    		$_sessionName,
    		$_cookieName,
    		$_isLoggedIn,
            $_usersTable,
            $_usersResetPasswordTableName,
            $_usersSessionsTableName,
            $_socialConfig,
            $_hybridAuth;

    public function __construct( $user = null )
	{
        if ( Config::Get( "mysql/enabled" ) )
        {
            $this->_db = DB::GetInstance( );

            $this->_sessionName = Config::Get( "session/sessionName" );
            $this->_cookieName = Config::Get( "remember/cookieName" );

            $this->_usersTable = Config::Get( "mysql/usersTableName" );
            $this->_usersResetPasswordTableName = Config::Get( "mysql/usersResetPasswordTableName" );
            $this->_usersSessionsTableName = Config::Get( "mysql/usersSessionsTableName" );
            
            $this->_socialConfig = array(
                "base_url" => "http://".Config::Get( "website/domainName" ).substr( Config::Get( "website/root" ), 0, -6 )."libs/hybridauth/hybridauth/index.php",
                "providers" => array (
                    "Google" => array (
                        "enabled" => Config::Get( "social/isEnabled/google" ),
                        "keys"    => array ( "id" => Config::Get( "social/keys/google/id" ), "secret" => Config::Get( "social/keys/google/secret" ) ),
                    ),
                    "Facebook" => array (
                        "enabled" => Config::Get( "social/isEnabled/facebook" ),
                        "keys" => array ( "id" => Config::Get( "social/keys/facebook/id" ), "secret" => Config::Get( "social/keys/facebook/secret" ) )
                    )
                )
            );
            

            $this->_hybridAuth = new \Hybrid_Auth( $this->_socialConfig );
            
            foreach ( Config::Get( "social/isEnabled" ) as $provider => $value )
            {
                // check if social login for a particular provider is enabled
                if ( $value )
                {
                    // loop through the providors from _socialConfig
                    if ( $this->_hybridAuth->isConnectedWith( $provider ) )
                    {
                        $adapter = $this->_hybridAuth->authenticate( $provider );

                        try
                        {
                            $user_profile = $adapter->getUserProfile( );

                            $this->SocialLogin( $user_profile->identifier, $user_profile->email, $provider );
                        }
                        catch( Exception $e )
                        {
                            $this->_hybridAuth->logoutAllProviders( );
                        }
                    }
                }
            }

            if ( !$user )
            {
                if ( Session::Exists( $this->_sessionName ) )
                {
                    $user = Session::Get( $this->_sessionName );

                    if ( $this->Find( $user ) )
                    {
                        $this->_isLoggedIn = true;
                    }
                    else
                    {
                        // process logout
                        $this->Logout( );
                    }
                }
            }
            else
            {
                $this->Find( $user );
            }
        }
	}

	public function Update( $fields = array( ), $id = null )
	{
		if ( !$id && $this->IsLoggedIn( ) )
		{
			$id = $this->Data( )->id;
		}

		if ( !$this->_db->Update( $this->_usersTable, $id, $fields ) )
		{
			return false;
		}
        else
        {
            return true;
        }
	}

    public function Create( $fields = array( ) )
    {
        if ( !$this->_db->Insert( $this->_usersTable, $fields ) )
        {
            throw new Exception( "There was a problem creating an account." );
        }
    }

    public function Find( $user = null )
    {
    	if ( $user )
    	{
            if ( is_numeric( $user ) )
            {
                $field = "id";
            }
            else if ( filter_var( $user, FILTER_VALIDATE_EMAIL ) )
            {
                $field = "email_address";
            }
            else
            {
                $field = "username";
            }

    		$data = $this->_db->Get( $this->_usersTable, array( $field, "=", $user ) );

    		if ( $data->count( ) )
    		{
    			$this->_data = $data->First( );

    			return true;
    		}
    	}

    	return false;
    }

    public function FindUsingEmail( $email = null )
    {
        if ( $email )
    	{
    		$data = $this->_db->Get( $this->_usersTable, array( "email_address", "=", $email ) );

    		if ( $data->count( ) )
    		{
    			$this->_data = $data->First( );

    			return true;
    		}
    	}

    	return false;
    }

    public function VerifyPassword( $passwordToVerify )
    {
        return password_verify( $passwordToVerify, $this->Data( )->password );
    }

    public function Login( $username = null, $password = null, $remember = false )
    {
    	if ( !$username && !$password && $this->Exists( ) )
    	{
    		Session::Put( $this->_sessionName, $this->Data( )->id );
    	}
    	else
    	{
    		$user = $this->Find( $username );

	    	if ( $user )
	    	{
	    		if ( password_verify( $password, $this->Data( )->password ) )
	    		{
                    if ( $this->IsActivated( $username ) )
                    {
                        return $this->LoginWithOutChecks( $remember );
                    }
                    else
                    {
                        $this->AddError( "Your account needs to be activated, please check your email for an activation email." );
                    }
	    		}

                $this->AddError( "Password is incorrect." );
	    	}
            else
            {
                $this->AddError( "User does not exist." );
            }
    	}

    	return false;
    }
    
    private function LoginWithOutChecks( $remember = true )
    {
        Session::Put( $this->_sessionName, $this->Data( )->id );

        if ( $remember )
        {
            $hash = Hash::Unique( );
            $hashCheck = $this->_db->Get( $this->_usersSessionsTableName, array( "user_id", "=", $this->Data( )->id ) );

            if ( !$hashCheck->count( ) )
            {
                $this->_db->Insert( $this->_usersSessionsTableName, array(
                    "user_id" => $this->Data( )->id,
                    "hash" => $hash
                ) );
            }
            else
            {
                $hash = $hashCheck->First( )->hash;
            }

            Cookie::Put( $this->_cookieName, $hash, Config::Get( "remember/cookieExpiry" ) );
        }

        if ( empty( $this->_errors ) )
        {
            $this->_passed = true;
        }

        return true;
    }

    public function VerifyActivationCode( $user, $code )
    {
        if ( is_numeric( $user ) )
        {
            $field = "id";
        }
        else if ( filter_var( $user, FILTER_VALIDATE_EMAIL ) )
        {
            $field = "email_address";
        }
        else
        {
            $field = "username";
        }

        $data = $this->_db->Get( $this->_usersTable, array( $field, "=", $user ) );

        if ( $data->First( )->salt === $code )
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    public function VerifyResetCode( $user, $code )
    {
        $data = $this->_db->Get( $this->_usersResetPasswordTableName, array( "username", "=", $user ) );

        if ( $data->count( ) )
        {
            if ( $data->First( )->salt === $code )
            {
                return true;
            }
            else
            {
                return false;
            }
        }
    }

    public function IsActivated( $user = null )
    {
        if ( $user )
    	{
    		if ( is_numeric( $user ) )
            {
                $field = "id";
            }
            else if ( filter_var( $user, FILTER_VALIDATE_EMAIL ) )
            {
                $field = "email_address";
            }
            else
            {
                $field = "username";
            }

    		$data = $this->_db->Get( $this->_usersTable, array( $field, "=", $user ) );

    		if ( $data->count( ) )
    		{
    			if ( $data->First( )->activated )
                {
                    return true;
                }
    		}
    	}

    	return false;
    }

    public function ActivateUser( $user )
    {
        if ( is_numeric( $user ) )
        {
            $field = "id";
        }
        else if ( filter_var( $user, FILTER_VALIDATE_EMAIL ) )
        {
            $field = "email_address";
        }
        else
        {
            $field = "username";
        }

        $data = $this->_db->Get( $this->_usersTable, array( $field, "=", $user ) );
        $id = $data->First( )->id;

        $result = $this->_db->Update( $this->_usersTable, $id, array( "activated" => "1" ) );

        if ( $result )
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    public function Exists( )
    {
    	return ( !empty( $this->_data ) ) ? true : false;
    }

    public function Logout( )
    {
        $this->_hybridAuth->logoutAllProviders( );

        $this->_db->Delete( "users_sessions", array( "user_id", "=", $this->Data( )->id ) );

        Session::Delete( $this->_sessionName );
        Cookie::Delete( $this->_cookieName );
    }

    public function Data( )
    {
    	return $this->_data;
    }

    public function IsLoggedIn( )
    {
        if ( $this->_isLoggedIn )
        {
            if ( $this->IsOnlySociallyLoggedIn( ) )
            {
                Redirect::To( "home/adduserdetails" );
            }
        }
        
    	return $this->_isLoggedIn;
    }
    
    public function IsOnlySociallyLoggedIn( )
    {
        if ( $this->_isLoggedIn )
        {
            if ( empty( $this->_data->username ) || empty( $this->_data->password ) )
            {
                return true;
            }
        }
        
        return false;
    }

    public function CheckPasswordSaltExists( $username )
    {
        $data = $this->_db->Get( $this->_usersResetPasswordTableName, array( "username", "=", $username ) );

        if ( count( $data ) )
        {
            return $data->First( );
        }
        else
        {
            return false;
        }
    }

    public function CreatePasswordResetSalt( $username, $salt )
    {
        $this->_db->Delete( $this->_usersResetPasswordTableName, array( "username", "=", $username ) );

        $fields = array(
            "username" => $username,
            "salt" => $salt,
            "starttime" => time( )
        );

        if ( !$this->_db->Insert( $this->_usersResetPasswordTableName, $fields ) )
        {
            return false;
        }
        else
        {
            return true;
        }
    }

    public function ClearPasswordResetTable( $username )
    {
        $this->_db->Delete( $this->_usersResetPasswordTableName, array( "username", "=", $username ) );
    }

    public function SocialLogin( $id, $emailAddress, $serviceName )
    {
        $serviceName = strtolower( $serviceName );
        
        // check if user exists in social database (if not add)
        $socialResult = $this->_db->Get( Config::Get( "social/tableNames/".$serviceName ), array( "email_address", "=", $emailAddress ) );
        
        if ( !$socialResult->count( ) )
        {
            $this->_db->Insert( Config::Get( "social/tableNames/".$serviceName ), array(
                "auth_id" => $id,
                "email_address" => $emailAddress,
                "joined" => time( )
            ) );
        }
        
        // check if user exists in regular database (if not add)
        if ( !$this->Find( $emailAddress ) )
        {
            $salt = Hash::Salt( 128 );

            $this->Create( array(
                "username" => '',
                "password" => '',
                "email_address" => $emailAddress,
                "salt" => $salt,
                "joined" => time( ),
                "activated" => 1
            ) );
        }
        
        // log user in (if username and password do not exist then show user submission detail page)
        $this->Find( $emailAddress );
        $this->LoginWithOutChecks( true );
        
        Redirect::To( "home/index" );
    }
    
    public function HybridAuth( )
    {
        return $this->_hybridAuth;
    }
}