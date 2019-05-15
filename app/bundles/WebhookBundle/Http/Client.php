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
     * @var CoreParametersHelper
     */
    private $coreParametersHelper;

    /**
     * @var GuzzleClient
     */
    private $httpClient;

    /**
     * @param CoreParametersHelper $coreParametersHelper
     * @param GuzzleClient         $httpClient
     */
    public function __construct(
        CoreParametersHelper $coreParametersHelper,
        GuzzleClient $httpClient
    ) {
        $this->coreParametersHelper = $coreParametersHelper;
        $this->httpClient           = $httpClient;
    }

    /**
     * @param string $url
     * @param array  $payload
     *
     * @return ResponseInterface
     */
    public function post($url, array $payload)
    {
        // Set up custom headers
        $headers = [
            'Content-Type'      => 'application/json',
            'X-Origin-Base-URL' => $this->coreParametersHelper->getParameter('site_url'),
        ];

        $request = new Request(
            'POST',
            $url,
            $headers,
            json_encode($payload)
        );

        return $this->httpClient->sendRequest($request);
    }
}
