# Single file SSO client for [Discourse](https://github.com/discourse/discourse) in PHP

Check discussion and ask for help on Discourse Meta:

* [MediaWiki](https://meta.discourse.org/t/using-discourse-sso-with-mediawiki/69218)
* [MantisBT 1.2](https://meta.discourse.org/t/using-discourse-sso-with-mantis-bug-tracker/69236)

Related projects:

* [Fork of this project with PostgreSQL support](https://github.com/hhyyrylainen/singlefile-discourse-sso-php).
* [MantisDiscourseSSO](https://github.com/ArseniyShestakov/MantisDiscourseSSO) plugin repository

# MediaWiki setup:

## install [Auth_remoteuser](https://www.mediawiki.org/wiki/Extension:Auth_remoteuser)
can clone directly into extension folder

```
git clone https://github.com/wikimedia/mediawiki-extensions-Auth_remoteuser.git /path/to/mediawiki/extensions/Auth_remoteuser
```

## clone this repo and copy `discourse-sso.php` to mediawiki dir

```
cp singlefile-discourse-sso-php/discourse-sso.php /path/to/mediawiki/
```

## add your info to top of `discourse-sso.php` file
- need to find database username, password and schema (will be in `LocalSettings.php`)
- need url of your discourse ( https://your_discourse.domain ) and the secret set from discourse admin panel
```
nano /path/to/mediawiki/discourse-sso.php
```

To create database table and test it visit https://your.wiki.domain/discourse-sso.php

You can check databse table contents from command line:

`mysql -u wikiuser -pPASSWORD wikidb -e "SELECT * FROM sso_login;"`

## add following code to end of `LocalSettings.php`

- `nano /path/to/mediawiki/LocalSettings.php` :

```
// Forbid account creation by users
$wgGroupPermissions['*']['createaccount'] = false;
// Allow extensions to manage users
$wgGroupPermissions['*']['autocreateaccount'] = true;

// Discourse authentification
require_once( "$IP/discourse-sso.php" );
$DISCOURSE_SSO = new DiscourseSSOClient();
$SSO_STATUS = $DISCOURSE_SSO->getAuthentication();

if($SSO_STATUS && $SSO_STATUS['logged'] && !empty($SSO_STATUS['data']['username']))
{
        $wgAuthRemoteuserUserName = $SSO_STATUS['data']['username'];
        $wgAuthRemoteuserUserPrefs = [
                'email' => $SSO_STATUS['data']['email']
        ];
//        $wgAuthRemoteuserUserPrefsForced = [
//                'email' => $SSO_STATUS['data']['email']
//        ];

        if(!empty($SSO_STATUS['data']['name']))
        {
                $wgAuthRemoteuserUserPrefs['realname'] = $SSO_STATUS['data']['name'];
//                $wgAuthRemoteuserUserPrefsForced['realname'] = $SSO_STATUS['data']['name'];
        }
        wfLoadExtension( 'Auth_remoteuser' );
        # Logout for authentication
        define('SSO_LOGOUT_TOKEN', hash('sha512', $SSO_STATUS["nonce"]));
        $wgAuthRemoteuserUserUrls = [
            'logout' => function( $metadata ) 
            {
                return '/discourse-sso.php?logout=' . SSO_LOGOUT_TOKEN;
            }
        ];
}

```

If you uncomment lines with force email / name will be changed not just for newly automatically-created users, but also for existing wiki users.

## change login to link to discourse

- `nano /path/to/wikimedia/includes/skins/SkinTemplate.php`
- find `$login_url = \[` in file with ctrl+w
- directly below this replace href entry to look like `'href' => '/discourse-sso.php',`

