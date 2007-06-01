<?php

/*
+---------------------------------------------------------------------------+
| Openads v2.3                                                              |
| ============                                                              |
|                                                                           |
| Copyright (c) 2003-2007 Openads Limited                                   |
| For contact details, see: http://www.openads.org/                         |
|                                                                           |
| This program is free software; you can redistribute it and/or modify      |
| it under the terms of the GNU General Public License as published by      |
| the Free Software Foundation; either version 2 of the License, or         |
| (at your option) any later version.                                       |
|                                                                           |
| This program is distributed in the hope that it will be useful,           |
| but WITHOUT ANY WARRANTY; without even the implied warranty of            |
| MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the             |
| GNU General Public License for more details.                              |
|                                                                           |
| You should have received a copy of the GNU General Public License         |
| along with this program; if not, write to the Free Software               |
| Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA |
+---------------------------------------------------------------------------+
$Id$
*/

$file = '/lib/max/Delivery/remotehost.php';
###START_STRIP_DELIVERY
if(isset($GLOBALS['_MAX']['FILES'][$file])) {
    return;
}
###END_STRIP_DELIVERY
$GLOBALS['_MAX']['FILES'][$file] = true;

/**
 * @package    MaxDelivery
 * @subpackage remotehost
 * @author     Andrew Hill <andrew@m3.net>
 *
 * A file to contain delivery engine functions related to obtaining data
 * about the remote viewer.
 */

/**
 * A function to convert the $_SERVER['REMOTE_ADDR'] global variable
 * from the current value to the real remote viewer's value, should
 * that viewer be coming via an HTTP proxy.
 *
 * Only performs this conversion if the option to do so is set in the
 * configuration file.
 */
function MAX_remotehostProxyLookup()
{
    $conf = $GLOBALS['_MAX']['CONF'];
    // Should proxy lookup conversion be performed?
    if ($conf['logging']['proxyLookup']) {
        // Determine if the viewer has come via an HTTP proxy
        $proxy = false;
        if (!empty($_SERVER['HTTP_VIA'])) {
            $proxy = true;
        } elseif (!empty($_SERVER['REMOTE_HOST'])) {
            $aProxyHosts = array(
                'proxy',
                'cache',
                'inktomi'
            );
            foreach ($aProxyHosts as $proxyName) {
                if (strpos($proxyName, $_SERVER['REMOTE_HOST']) !== false) {
                    $proxy = true;
                    break;
                }
            }
        }
        // Has the viewer come via an HTTP proxy?
        if ($proxy) {
            // Try to find the "real" IP address the viewer has come from
            $aHeaders = array(
                'HTTP_FORWARDED',
                'HTTP_FORWARDED_FOR',
                'HTTP_X_FORWARDED',
                'HTTP_X_FORWARDED_FOR',
                'HTTP_CLIENT_IP'
            );
            foreach ($aHeaders as $header) {
                if (!empty($_SERVER[$header])) {
                    $ip = $_SERVER[$header];
                    break;
                }
            }
            if (!empty($ip)) {
                // The "remote IP" may be a list, ensure that
                // only the last item is used in that case
                $ip = explode(',', $ip);
                $ip = trim($ip[count($ip) - 1]);
                // If the found address is not unknown or a private network address
                if (($ip != 'unknown') && (!MAX_remotehostPrivateAddress($ip))) {
                    // Set the "real" remote IP address, and unset
                    // the remote host (as it will be wrong for the
                    // newly found IP address) and HTTP_VIA header
                    // (so that we don't accidently do this twice)
                    $_SERVER['REMOTE_ADDR'] = $ip;
                    $_SERVER['REMOTE_HOST'] = '';
                    $_SERVER['HTTP_VIA']    = '';
                }
            }
        }
    }
}

/**
 * A function to perform a reverse lookup of the hostname from the IP address,
 * and store the result in the $_SERVER['REMOTE_HOST'] global variable.
 *
 * Only performs the reverse lookup if the option is set in the configuration,
 * and if the host name is not already present. If the the host name is not
 * present and the option to perform the lookup is not set, then the host name
 * is set to the remote IP address instead.
 */
function MAX_remotehostReverseLookup()
{
    // Is the remote host name already set?
    if (empty($_SERVER['REMOTE_HOST'])) {
        // Should reverse lookups be performed?
        if ($GLOBALS['_MAX']['CONF']['logging']['reverseLookup']) {
            $_SERVER['REMOTE_HOST'] = @gethostbyaddr($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_HOST'] = $_SERVER['REMOTE_ADDR'];
        }
    }
}

/**
 * A function to set the viewer's useragent information in the
 * $GLOBALS['_MAX']['CLIENT'] global variable, if the option to use
 * phpSniff to extract useragent information is set in the
 * configuration file.
 */
function MAX_remotehostSetClientInfo()
{
    if ($GLOBALS['_MAX']['CONF']['logging']['sniff'] && isset($_SERVER['HTTP_USER_AGENT'])) {
        include MAX_PATH . '/lib/phpSniff/phpSniff.class.php';
        $client = new phpSniff($_SERVER['HTTP_USER_AGENT']);
        $GLOBALS['_MAX']['CLIENT'] = $client->_browser_info;
    }
}

/**
 * A function to set the viewer's geotargeting information in the
 * $GLOBALS['_MAX']['CLIENT_GEO'] global variable, if a plugin for
 * geotargeting information is configured.
 * 
 * @todo This is a workaround to avoid having to include the entire plugin architecure
 *       just to be able to load the config information. The plugin system should be
 *       refactored to allow the Delivery Engine to load the information independently
 */
function MAX_remotehostSetGeoInfo()
{
    if (!function_exists('parseDeliveryIniFile')) {
        require_once MAX_PATH . '/init-delivery-parse.php';
    }
    $pluginTypeConfig = parseDeliveryIniFile(MAX_PATH . '/var/plugins/config/geotargeting', 'plugin');
    $type = (!empty($pluginTypeConfig['geotargeting']['type'])) ? $pluginTypeConfig['geotargeting']['type'] : null;
    if (!is_null($type) && $type != 'none') {
        $functionName = 'MAX_Geo_'.$type.'_getInfo';
        if (function_exists($functionName)) {
            return;
        }
        $pluginConfig = parseDeliveryIniFile(MAX_PATH . '/var/plugins/config/geotargeting/' . $type, 'plugin');
        $GLOBALS['_MAX']['CONF']['geotargeting'] = array_merge($pluginTypeConfig['geotargeting'], $pluginConfig['geotargeting']);
        // There may have been a copy of $conf set in the global scope, this should also be updated
        if (isset($GLOBALS['conf'])) {
            $GLOBALS['conf']['geotargeting'] = $GLOBALS['_MAX']['CONF']['geotargeting'];
        }
        @include(MAX_PATH . '/plugins/geotargeting/' . $type . '/' . $type . '.delivery.php');
        if (function_exists($functionName)) {
            $GLOBALS['_MAX']['CLIENT_GEO'] = $functionName();
        }
    }
}

/**
 * A function to determine if a given IP address is in a private network or
 * not.
 *
 * @param string $ip The IP address to check.
 * @return boolean Returns true if the IP address is in a private network,
 *                 false otherwise.
 */
function MAX_remotehostPrivateAddress($ip)
{
    require_once 'Net/IPv4.php';
    // Define the private address networks, see
    // http://rfc.net/rfc1918.html
    $aPrivateNetworks = array(
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '127.0.0.0/24'
    );
    foreach ($aPrivateNetworks as $privateNetwork) {
        if (Net_IPv4::ipInNetwork($ip, $privateNetwork)) {
            return true;
        }
    }
    return false;
}

?>
