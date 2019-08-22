<?php

/*
 * @copyright   2016 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\EmailBundle\Tests\Helper;

use Mautic\CoreBundle\Factory\MauticFactory;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Helper\FromEmailHelper;
use Mautic\EmailBundle\Helper\MailHelper;
use Mautic\EmailBundle\MonitoredEmail\Mailbox;
use Mautic\EmailBundle\Swiftmailer\Exception\BatchQueueMaxException;
use Mautic\EmailBundle\Tests\Helper\Transport\BatchTransport;
use Mautic\EmailBundle\Tests\Helper\Transport\BcInterfaceTokenTransport;
use Mautic\EmailBundle\Tests\Helper\Transport\SmtpTransport;
use Mautic\LeadBundle\Entity\LeadRepository;
use Mautic\LeadBundle\Model\LeadModel;
use Monolog\Logger;

class MailHelperTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var FromEmailHelper|\PHPUnit_Framework_MockObject_MockObject
     */
    private $fromEmailHelper;

    /**
     * @var MauticFactory|\PHPUnit_Framework_MockObject_MockObject
     */
    private $mockFactory;

    /**
     * @var array
     */
    protected $contacts = [
        [
            'id'        => 1,
            'email'     => 'contact1@somewhere.com',
            'firstname' => 'Contact',
            'lastname'  => '1',
            'owner_id'  => 1,
        ],
        [
            'id'        => 2,
            'email'     => 'contact2@somewhere.com',
            'firstname' => 'Contact',
            'lastname'  => '2',
            'owner_id'  => 0,
        ],
        [
            'id'        => 3,
            'email'     => 'contact3@somewhere.com',
            'firstname' => 'Contact',
            'lastname'  => '3',
            'owner_id'  => 2,
        ],
        [
            'id'        => 4,
            'email'     => 'contact4@somewhere.com',
            'firstname' => 'Contact',
            'lastname'  => '4',
            'owner_id'  => 1,
        ],
    ];

    public function setUp()
    {
        defined('MAUTIC_ENV') or define('MAUTIC_ENV', 'test');

        $this->fromEmailHelper = $this->createMock(FromEmailHelper::class);

        $this->mockFactory     = $this->createMock(MauticFactory::class);
        $this->mockFactory->method('get')
            ->with('mautic.helper.from_email_helper')
            ->willReturn($this->fromEmailHelper);
    }

    /**
     * @expectedException \Mautic\EmailBundle\Swiftmailer\Exception\BatchQueueMaxException
     */
    public function testQueueModeThrowsExceptionWhenBatchLimitHit()
    {
        $mockFactory = $this->mockFactory;
        $mockFactory->method('getParameter')
            ->will(
                $this->returnValueMap(
                    [
                        ['mailer_return_path', false, null],
                        ['mailer_spool_type', false, 'memory'],
                    ]
                )
            );
        $mockFactory->method('get')
            ->with('mautic.helper.from_email_helper')
            ->willReturn($this->createMock(FromEmailHelper::class));

        $swiftMailer = new \Swift_Mailer(new BatchTransport());

        $mailer = new MailHelper($mockFactory, $swiftMailer, ['nobody@nowhere.com' => 'No Body']);

        // Enable queue mode
        $mailer->enableQueue();
        $mailer->addTo('somebody@somewhere.com');
        $mailer->addTo('somebodyelse@somewhere.com');
        $mailer->addTo('somebodyelse2@somewhere.com');
        $mailer->addTo('somebodyelse3@somewhere.com');
        $mailer->addTo('somebodyelse4@somewhere.com');
    }

    public function testQueueModeDisabledDoesNotThrowsExceptionWhenBatchLimitHit()
    {
        $mockFactory = $this->mockFactory;
        $mockFactory->method('getParameter')
            ->will(
                $this->returnValueMap(
                    [
                        ['mailer_return_path', false, null],
                        ['mailer_spool_type', false, 'memory'],
                    ]
                )
            );
        $mockFactory->method('get')
            ->with('mautic.helper.from_email_helper')
            ->willReturn($this->createMock(FromEmailHelper::class));

        $swiftMailer = new \Swift_Mailer(new BatchTransport());

        $mailer = new MailHelper($mockFactory, $swiftMailer, ['nobody@nowhere.com' => 'No Body']);

        // Enable queue mode
        try {
            $mailer->addTo('somebody@somewhere.com');
            $mailer->addTo('somebodyelse@somewhere.com');
        } catch (BatchQueueMaxException $exception) {
            $this->fail('BatchQueueMaxException thrown');
        }

        // Otherwise success
        $this->assertTrue(true);
    }

    public function testQueuedEmailFromOverride()
    {
        $mockFactory = $this->getMockFactory(false);
        $mockFactory->method('get')
            ->with('mautic.helper.from_email_helper')
            ->willReturn($this->createMock(FromEmailHelper::class));

        $this->fromEmailHelper->expects($this->exactly(8))
            ->method('getFromAddressArrayConsideringOwner')
            ->willReturnOnConsecutiveCalls(
                ['override@nowhere.com' => null],
                ['override@nowhere.com' => null],
                ['override@nowhere.com' => null],
                ['override@nowhere.com' => null],
                ['nobody@nowhere.com'   => null],
                ['nobody@nowhere.com'   => null],
                ['nobody@nowhere.com'   => null],
                ['nobody@nowhere.com'   => null]
            );

        $transport   = new BatchTransport();
        $swiftMailer = new \Swift_Mailer($transport);

        $mailer = new MailHelper($mockFactory, $swiftMailer, ['nobody@nowhere.com' => 'No Body']);
        $mailer->enableQueue();

        $email = new Email();
        $email->setFromAddress('override@nowhere.com');
        $email->setFromName('Test');

        $mailer->setEmail($email);

        foreach ($this->contacts as $contact) {
            $mailer->addTo($contact['email']);
            $mailer->setLead($contact);
            $mailer->queue();
        }

        $mailer->flushQueue();
        $from = $mailer->message->getFrom();

        $this->assertTrue(array_key_exists('override@nowhere.com', $from));
        $this->assertTrue(count($from) === 1);

        $mailer->reset();
        foreach ($this->contacts as $contact) {
            $mailer->addTo($contact['email']);
            $mailer->setLead($contact);
            $mailer->queue();
        }
        $mailer->flushQueue();
        $from = $mailer->message->getFrom();

        $this->assertTrue(array_key_exists('nobody@nowhere.com', $from));
        $this->assertTrue(count($from) === 1);
    }

    public function testBatchMode()
    {
        $mockFactory = $this->getMockFactory(false);
        $mockFactory->method('get')
            ->with('mautic.helper.from_email_helper')
            ->willReturn($this->createMock(FromEmailHelper::class));

        $transport   = new BatchTransport(true);
        $swiftMailer = new \Swift_Mailer($transport);

        $from   = ['nobody@nowhere.com' => 'No Body'];
        $mailer = new MailHelper($mockFactory, $swiftMailer, $from);
        $mailer->enableQueue();
        $this->fromEmailHelper->expects($this->exactly(2))
            ->method('getFromAddressArrayConsideringOwner')
            ->willReturn($from);

        $email = new Email();
        $email->setSubject('Hello');
        $mailer->setEmail($email);

        $mailer->addTo($this->contacts[0]['email']);
        $mailer->setLead($this->contacts[0]);
        $mailer->queue();
        $mailer->flushQueue();
        $errors = $mailer->getErrors();
        $this->assertEmpty($errors['failures'], var_export($errors, true));

        $mailer->reset(false);
        $mailer->setEmail($email);
        $mailer->addTo($this->contacts[1]['email']);
        $mailer->setLead($this->contacts[1]);
        $mailer->queue();
        $mailer->flushQueue();
        $errors = $mailer->getErrors();
        $this->assertEmpty($errors['failures'], var_export($errors, true));
    }

    public function testQueuedOwnerAsMailer()
    {
        $mockFactory = $this->getMockFactory();

        $transport   = new BatchTransport();
        $swiftMailer = new \Swift_Mailer($transport);

        $mailer = new MailHelper($mockFactory, $swiftMailer, ['nobody@nowhere.com' => 'No Body']);
        $mailer->enableQueue();
        $this->fromEmailHelper->expects($this->exactly(4))
            ->method('getFromAddressArrayConsideringOwner')
            ->willReturnOnConsecutiveCalls(
                ['owner1@owner.com' => 'owner 1'],
                ['nobody@nowhere.com' => 'No Body'],
                ['owner2@owner.com'   => 'owner 2'],
                ['owner1@owner.com'   => 'owner 1']
            );
        $this->fromEmailHelper
            ->method('getSignature')
            ->willReturnOnConsecutiveCalls(
                'owner 1',
                '',
                'owner 2',
                'owner 1'
            );

        $mailer->setSubject('Hello');

        foreach ($this->contacts as $contact) {
            $mailer->addTo($contact['email']);
            $mailer->setLead($contact);
            $mailer->queue();
        }

        $mailer->flushQueue([]);

        $this->assertEmpty($mailer->getErrors()['failures']);

        $fromAddresses = $transport->getFromAddresses();
        $metadatas     = $transport->getMetadatas();

        $this->assertEquals(3, count($fromAddresses));
        $this->assertEquals(3, count($metadatas));
        $this->assertEquals(['owner1@owner.com', 'nobody@nowhere.com', 'owner2@owner.com'], $fromAddresses);

        foreach ($metadatas as $key => $metadata) {
            $this->assertTrue(isset($metadata[$this->contacts[$key]['email']]));

            if (0 === $key) {
                // Should have two contacts
                $this->assertEquals(2, count($metadata));
                $this->assertTrue(isset($metadata['contact4@somewhere.com']));
            } else {
                $this->assertEquals(1, count($metadata));
            }

            // Check that signatures are valid
            if (1 === $key) {
                // signature should be empty
                $this->assertEquals('', $metadata['contact2@somewhere.com']['tokens']['{signature}']);
            } else {
                $this->assertEquals($metadata[$this->contacts[$key]['email']]['tokens']['{signature}'], 'owner '.$this->contacts[$key]['owner_id']);

                if (0 === $key) {
                    // Ensure the last contact has the correct signature
                    $this->assertEquals($metadata['contact4@somewhere.com']['tokens']['{signature}'], 'owner '.$this->contacts[$key]['owner_id']);
                }
            }
        }

        // Validate that the message object only has the contacts for the last "from" group to ensure we aren't sending duplicates
        $this->assertEquals(['contact3@somewhere.com' => null], $mailer->message->getTo());
    }

    public function testBatchIsEnabledWithBcTokenInterface()
    {
        $mockFactory = $this->getMockFactory();

        $transport   = new BcInterfaceTokenTransport();
        $swiftMailer = new \Swift_Mailer($transport);

        $mailer = new MailHelper($mockFactory, $swiftMailer, ['nobody@nowhere.com' => 'No Body']);
        $this->fromEmailHelper->expects($this->exactly(4))
            ->method('getFromAddressArrayConsideringOwner')
            ->willReturnOnConsecutiveCalls(
                ['owner1@owner.com' => null],
                ['nobody@nowhere.com' => null],
                ['owner2@owner.com'   => null],
                ['nobody@nowhere.com' => null]
            );

        $mailer->enableQueue();

        $mailer->setSubject('Hello');

        foreach ($this->contacts as $contact) {
            $mailer->addTo($contact['email']);
            $mailer->setLead($contact);
            $mailer->queue();
        }

        $mailer->flushQueue([]);

        $this->assertEmpty($mailer->getErrors()['failures']);

        $fromAddresses = $transport->getFromAddresses();
        $metadatas     = $transport->getMetadatas();

        $this->assertEquals(3, count($fromAddresses));
        $this->assertEquals(3, count($metadatas));
    }

    public function testGlobalFromThatAllFromAddressesAreTheSame()
    {
        $mockFactory = $this->getMockFactory();

        $transport   = new BatchTransport();
        $swiftMailer = new \Swift_Mailer($transport);

        $mailer = new MailHelper($mockFactory, $swiftMailer, ['nobody@nowhere.com' => 'No Body']);
        $mailer->enableQueue();
        $this->fromEmailHelper->expects($this->exactly(4))
            ->method('getFromAddressArrayConsideringOwner')
            ->willReturn(['nobody@nowhere.com' => 'No Body']);

        $mailer->setSubject('Hello');
        $mailer->setFrom('override@owner.com');

        foreach ($this->contacts as $contact) {
            $mailer->addTo($contact['email']);
            $mailer->setLead($contact);
            $mailer->queue();
        }

        $mailer->flushQueue();

        $this->assertEmpty($mailer->getErrors()['failures']);

        $fromAddresses = $transport->getFromAddresses();

        $this->assertEquals(['override@owner.com'], array_unique($fromAddresses));
    }

    public function testStandardOwnerAsMailer()
    {
        $mockFactory = $this->getMockFactory();

        $transport   = new SmtpTransport();
        $swiftMailer = new \Swift_Mailer($transport);

        $mailer = new MailHelper($mockFactory, $swiftMailer, ['nobody@nowhere.com' => 'No Body']);
        $mailer->setBody('{signature}');
        $this->fromEmailHelper->expects($this->exactly(4))
            ->method('getFromAddressArrayConsideringOwner')
            ->willReturnOnConsecutiveCalls(
                ['owner1@owner.com' => 'owner 1'],
                ['nobody@nowhere.com' => 'No Body'],
                ['owner2@owner.com'   => 'owner 2'],
                ['owner1@owner.com'   => 'owner 1']
            );
        $this->fromEmailHelper
            ->method('getSignature')
            ->willReturnOnConsecutiveCalls(
                'owner 1',
                '',
                'owner 2',
                'owner 1'
            );

        foreach ($this->contacts as $key => $contact) {
            $mailer->addTo($contact['email']);
            $mailer->setLead($contact);
            $mailer->send();

            $body = $mailer->message->getBody();
            $from = key($mailer->message->getFrom());

            if ($contact['owner_id']) {
                $this->assertEquals('owner'.$contact['owner_id'].'@owner.com', $from);
                $this->assertEquals('owner '.$contact['owner_id'], $body);
            } else {
                $this->assertEquals('nobody@nowhere.com', $from);
                $this->assertEquals('', $body);
            }
        }
    }

    public function testValidateValidEmails()
    {
        $helper    = $this->mockEmptyMailHelper();
        $addresses = [
            'john@doe.com',
            'john@doe.email',
            'john.doe@email.com',
            'john+doe@email.com',
            'john@doe.whatevertldtheycomewithinthefuture',
        ];

        foreach ($addresses as $address) {
            // will throw Swift_RfcComplianceException if it will find the address invalid
            $helper::validateEmail($address);
        }
    }

    public function testValidateEmailWithoutTld()
    {
        $helper = $this->mockEmptyMailHelper();
        $this->expectException(\Swift_RfcComplianceException::class);
        $helper::validateEmail('john@doe');
    }

    public function testValidateEmailWithSpaceInIt()
    {
        $helper = $this->mockEmptyMailHelper();
        $this->expectException(\Swift_RfcComplianceException::class);
        $helper::validateEmail('jo hn@doe.email');
    }

    public function testValidateEmailWithCaretInIt()
    {
        $helper = $this->mockEmptyMailHelper();
        $this->expectException(\Swift_RfcComplianceException::class);
        $helper::validateEmail('jo^hn@doe.email');
    }

    public function testValidateEmailWithApostropheInIt()
    {
        $helper = $this->mockEmptyMailHelper();
        $this->expectException(\Swift_RfcComplianceException::class);
        $helper::validateEmail('jo\'hn@doe.email');
    }

    public function testValidateEmailWithSemicolonInIt()
    {
        $helper = $this->mockEmptyMailHelper();
        $this->expectException(\Swift_RfcComplianceException::class);
        $helper::validateEmail('jo;hn@doe.email');
    }

    public function testValidateEmailWithAmpersandInIt()
    {
        $helper = $this->mockEmptyMailHelper();
        $this->expectException(\Swift_RfcComplianceException::class);
        $helper::validateEmail('jo&hn@doe.email');
    }

    public function testValidateEmailWithStarInIt()
    {
        $helper = $this->mockEmptyMailHelper();
        $this->expectException(\Swift_RfcComplianceException::class);
        $helper::validateEmail('jo*hn@doe.email');
    }

    public function testValidateEmailWithPercentInIt()
    {
        $helper = $this->mockEmptyMailHelper();
        $this->expectException(\Swift_RfcComplianceException::class);
        $helper::validateEmail('jo%hn@doe.email');
    }

    public function testQueueModeIsReset()
    {
        $contacts   = $this->contacts;
        $contacts[] = [
            'id'        => 5,
            'email'     => 'contact5@somewhere.com',
            'firstname' => 'Contact',
            'lastname'  => '5',
            'owner_id'  => 1,
        ];

        $helper = $this->mockEmptyMailHelper(false);

        $helper->enableQueue();

        $helper->addTo($contacts[0]['email']);
        $helper->addTo($contacts[1]['email']);
        $helper->addTo($contacts[2]['email']);
        $helper->addTo($contacts[3]['email']);

        $exceptionCaught = true;
        try {
            $helper->addTo($contacts[4]['email']);
            $exceptionCaught = false;
        } catch (BatchQueueMaxException $exception) {
        }

        if (!$exceptionCaught) {
            $this->fail('BatchQueueMaxException should have been thrown');
        }

        // Reset which should now reset qeue mode so that each to address is accepted
        $helper->reset();

        try {
            foreach ($contacts as $contact) {
                $helper->addTo($contact['email']);
            }
        } catch (BatchQueueMaxException $exception) {
            $this->fail('Queue mode was not reset');
        }

        $to = $helper->message->getTo();

        $this->assertEquals(
            [
                'contact1@somewhere.com' => null,
                'contact2@somewhere.com' => null,
                'contact3@somewhere.com' => null,
                'contact4@somewhere.com' => null,
                'contact5@somewhere.com' => null,
            ],
            $to
        );
    }

    public function testGlobalHeadersAreSet()
    {
        $parameterMap = [
            ['mailer_custom_headers', [], ['X-Mautic-Test' => 'test', 'X-Mautic-Test2' => 'test']],
        ];
        $mockFactory = $this->getMockFactory(true, $parameterMap);

        $transport   = new SmtpTransport();
        $swiftMailer = new \Swift_Mailer($transport);

        $mailer = new MailHelper($mockFactory, $swiftMailer, ['nobody@nowhere.com' => 'No Body']);
        $mailer->setBody('{signature}');
        $mailer->addTo($this->contacts[0]['email']);
        $mailer->send();

        $customHeadersFounds = [];

        /** @var \Swift_Mime_Headers_ParameterizedHeader[] $headers */
        $headers = $mailer->message->getHeaders()->getAll();
        foreach ($headers as $header) {
            if (strpos($header->getFieldName(), 'X-Mautic-Test') !== false) {
                $customHeadersFounds[] = $header->getFieldName();

                $this->assertEquals('test', $header->getValue());
            }
        }

        $this->assertCount(2, $customHeadersFounds);
    }

    public function testGlobalHeadersAreIgnoredIfEmailEntityIsSet()
    {
        $parameterMap = [
            ['mailer_custom_headers', [], ['X-Mautic-Test' => 'test', 'X-Mautic-Test2' => 'test']],
        ];
        $mockFactory = $this->getMockFactory(true, $parameterMap);

        $transport   = new SmtpTransport();
        $swiftMailer = new \Swift_Mailer($transport);

        $mailer = new MailHelper($mockFactory, $swiftMailer, ['nobody@nowhere.com' => 'No Body']);
        $mailer->addTo($this->contacts[0]['email']);

        $email = new Email();
        $email->setSubject('Test');
        $email->setCustomHtml('{signature}');
        $mailer->setEmail($email);
        $mailer->send();

        /** @var \Swift_Mime_Headers_ParameterizedHeader[] $headers */
        $headers = $mailer->message->getHeaders()->getAll();
        foreach ($headers as $header) {
            if (strpos($header->getFieldName(), 'X-Mautic-Test') !== false) {
                $this->fail('System headers were not supposed to be set');
            }
        }
    }

    public function testEmailHeadersAreSet()
    {
        $parameterMap = [
            ['mailer_custom_headers', [], ['X-Mautic-Test' => 'test', 'X-Mautic-Test2' => 'test']],
        ];
        $mockFactory = $this->getMockFactory(true, $parameterMap);

        $transport   = new SmtpTransport();
        $swiftMailer = new \Swift_Mailer($transport);

        $mailer = new MailHelper($mockFactory, $swiftMailer, ['nobody@nowhere.com' => 'No Body']);
        $mailer->addTo($this->contacts[0]['email']);

        $email = new Email();
        $email->setSubject('Test');
        $email->setCustomHtml('{signature}');
        $email->setHeaders(['X-Mautic-Test3' => 'test2', 'X-Mautic-Test4' => 'test2']);
        $mailer->setEmail($email);
        $mailer->send();

        $customHeadersFounds = [];

        /** @var \Swift_Mime_Headers_ParameterizedHeader[] $headers */
        $headers = $mailer->message->getHeaders()->getAll();
        foreach ($headers as $header) {
            if (strpos($header->getFieldName(), 'X-Mautic-Test') !== false) {
                $customHeadersFounds[] = $header->getFieldName();

                $this->assertEquals('test2', $header->getValue());
            }
        }

        $this->assertCount(2, $customHeadersFounds);
    }

    protected function mockEmptyMailHelper($useSmtp = true)
    {
        $mockFactory = $this->getMockFactory();
        $transport   = ($useSmtp) ? new SmtpTransport() : new BatchTransport();
        $swiftMailer = new \Swift_Mailer($transport);

        return new MailHelper($mockFactory, $swiftMailer);
    }

    protected function getMockFactory($mailIsOwner = true, $parameterMap = [])
    {
        $mockLeadRepository = $this->getMockBuilder(LeadRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $mockLeadRepository->method('getLeadOwner')
            ->will(
                $this->returnValueMap(
                    [
                        [1, ['id' => 1, 'email' => 'owner1@owner.com', 'first_name' => '', 'last_name' => '', 'signature' => 'owner 1']],
                        [2, ['id' => 2, 'email' => 'owner2@owner.com', 'first_name' => '', 'last_name' => '', 'signature' => 'owner 2']],
                        [3, ['id' => 3, 'email' => 'owner3@owner.com', 'first_name' => 'John', 'last_name' => 'S&#39;mith', 'signature' => 'owner 2']],
                    ]
                )
            );

        $mockLeadModel = $this->getMockBuilder(LeadModel::class)
            ->disableOriginalConstructor()
            ->getMock();

        $mockLeadModel->method('getRepository')
            ->willReturn($mockLeadRepository);

        $mockFactory = $this->mockFactory;

        $parameterMap = array_merge(
            [
                ['mailer_return_path', false, null],
                ['mailer_spool_type', false, 'memory'],
                ['mailer_is_owner', false, $mailIsOwner],
            ],
            $parameterMap
        );

        $mockFactory->method('getParameter')
            ->will(
                $this->returnValueMap($parameterMap)
            );
        $mockFactory->method('getModel')
            ->will(
                $this->returnValueMap(
                    [
                        ['lead', $mockLeadModel],
                    ]
                )
            );

        $mockLogger = $this->getMockBuilder(Logger::class)
            ->disableOriginalConstructor()
            ->getMock();
        $mockFactory->method('getLogger')
            ->willReturn($mockLogger);

        $mockMailboxHelper = $this->getMockBuilder(Mailbox::class)
            ->disableOriginalConstructor()
            ->getMock();
        $mockMailboxHelper->method('isConfigured')
            ->willReturn(false);

        $mockFactory->method('getHelper')
            ->will(
                $this->returnValueMap(
                    [
                        ['mailbox', $mockMailboxHelper],
                    ]
                )
            );

        return $mockFactory;
    }

    public function testArrayOfAddressesAreRemappedIntoEmailToNameKeyValuePair()
    {
        $mockFactory = $this->mockFactory;
        $mockFactory->method('getParameter')
            ->will(
                $this->returnValueMap(
                    [
                        ['mailer_return_path', false, null],
                        ['mailer_spool_type', false, 'memory'],
                    ]
                )
            );

        $swiftMailer = new \Swift_Mailer(new SmtpTransport());

        $mailer = new MailHelper($mockFactory, $swiftMailer, ['nobody@nowhere.com' => 'No Body']);

        $mailer->setTo(['sombody@somewhere.com', 'sombodyelse@somewhere.com'], 'test');

        $this->assertEquals(
            [
                'sombody@somewhere.com'     => 'test',
                'sombodyelse@somewhere.com' => 'test',
            ],
            $mailer->message->getTo()
        );
    }
}
