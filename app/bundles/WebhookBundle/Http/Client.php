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

use Joomla\Http\Http;
use Joomla\Http\Response;
use Mautic\CoreBundle\Helper\CoreParametersHelper;

class Client
{
    /**
     * @var CoreParametersHelper
     */
    private $coreParametersHelper;

    /**
     * @param CoreParametersHelper $coreParametersHelper
     */
    public function __construct(CoreParametersHelper $coreParametersHelper)
    {
        $this->coreParametersHelper = $coreParametersHelper;
    }

    /**
     * @param string   $url
     * @param array    $payload
     * @param int|null $timeout
     *
     * @return Response
     */
    public function post(string $url, array $payload, int $timeout = null): Response
    {
        // Set up custom headers
        $headers = [
            'Content-Type'      => 'application/json',
            'X-Origin-Base-URL' => $this->coreParametersHelper->getParameter('site_url'),
        ];

        $http = new Http();

        return $http->post($url, json_encode($payload), $headers, $timeout);
    }
}
