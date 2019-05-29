<?php

/*
 * @copyright   2019 Mautic Contributors. All rights reserved
 * @author      Mautic, Inc.
 *
 * @link        https://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\EmailBundle\Tests\Controller\Api;

use FOS\RestBundle\Util\Codes;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\Entity\Stat;
use Mautic\EmailBundle\Entity\StatRepository;

class EmailApiControllerFunctionalTest extends MauticMysqlTestCase
{
    public function testReplyActionIfNotFound()
    {
        $trackingHash = 'tracking_hash_123';

        // Create new email reply.
        $this->client->request('POST', "/api/emails/reply/{$trackingHash}");
        $response     = $this->client->getResponse();
        $responseData = json_decode($response->getContent(), true);

        $this->assertSame(Codes::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertSame('Email Stat with tracking hash tracking_hash_123 was not found', $responseData['errors'][0]['message']);
    }

    public function testReplyAction()
    {
        $trackingHash = 'tracking_hash_123';

        /** @var StatRepository $statRepository */
        $statRepository = $this->container->get('mautic.email.repository.stat');

        // Create a test email stat.
        $stat = new Stat();
        $stat->setTrackingHash($trackingHash);
        $stat->setEmailAddress('john@doe.email');
        $stat->setDateSent(new \DateTime());

        $statRepository->saveEntity($stat);

        // Create new email reply.
        $this->client->request('POST', "/api/emails/reply/{$trackingHash}");
        $response = $this->client->getResponse();

        $this->assertSame(Codes::HTTP_CREATED, $response->getStatusCode());
        $this->assertSame(['success' => true], json_decode($response->getContent(), true));

        // Get the email reply that was just created from the stat API.
        $statReplyQuery = ['where' => [['col' => 'stat_id', 'expr' => 'eq', 'val' => $stat->getId()]]];
        $this->client->request('GET', '/api/stats/email_stat_replies', $statReplyQuery);
        $fetchedReplyData = json_decode($this->client->getResponse()->getContent(), true);

        // Check that the email reply was created correctly.
        $this->assertSame('1', $fetchedReplyData['total']);
        $this->assertSame($stat->getId(), $fetchedReplyData['stats'][0]['stat_id']);
        $this->assertRegExp('/api-[a-z0-9]*/', $fetchedReplyData['stats'][0]['message_id']);

        // Get the email stat that was just updated from the stat API.
        $statQuery = ['where' => [['col' => 'id', 'expr' => 'eq', 'val' => $stat->getId()]]];
        $this->client->request('GET', '/api/stats/email_stats', $statQuery);
        $fetchedStatData = json_decode($this->client->getResponse()->getContent(), true);

        // Check that the email stat was updated correctly/
        $this->assertSame('1', $fetchedStatData['total']);
        $this->assertSame($stat->getId(), $fetchedStatData['stats'][0]['id']);
        $this->assertSame('1', $fetchedStatData['stats'][0]['is_read']);
        $this->assertRegExp('/\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}/', $fetchedStatData['stats'][0]['date_read']);
    }
}
