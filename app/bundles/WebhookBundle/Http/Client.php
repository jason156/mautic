<?php

/*
* @copyright   2019 Mautic, Inc. All rights reserved
* @author      Mautic, Inc.
*
* @link        https://mautic.com
*
* @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
*/

namespace Mautic\WebhookBundle\Http;

use GuzzleHttp\Psr7\Request;
use Http\Adapter\Guzzle6\Client as GuzzleClient;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Psr\Http\Message\ResponseInterface;

class Client
{
    /**
     * @var GuzzleClient
     */
    private $httpClient;

    /**
     * @var CoreParametersHelper
     */
    private $coreParametersHelper;

    /**
     * @param GuzzleClient         $httpClient
     * @param CoreParametersHelper $coreParametersHelper
     */
    public function __construct(GuzzleClient $httpClient, CoreParametersHelper $coreParametersHelper)
    {
        $this->httpClient           = $httpClient;
        $this->coreParametersHelper = $coreParametersHelper;
    }

    /**
     * @param string   $url
     * @param array    $payload
     * @param int|null $timeout
     *
     * @return ResponseInterface
     */
    public function post($url, array $payload, $timeout = null)
    {
        // Set up custom headers
        $headers = [
            'Content-Type'      => 'application/json',
            'X-Origin-Base-URL' => $this->coreParametersHelper->getParameter('site_url'),
            'Cookie'            => 'XDEBUG_SESSION=XDEBUG_ECLIPSE',
        ];

        $request = new Request(
            'GET',
            $url,
            $headers,
            json_encode($payload)
        );

        return $this->httpClient->sendRequest($request);
    }
}
