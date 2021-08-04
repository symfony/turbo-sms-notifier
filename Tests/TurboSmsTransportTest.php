<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Notifier\Bridge\TurboSms\Tests;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Notifier\Bridge\TurboSms\TurboSmsTransport;
use Symfony\Component\Notifier\Exception\LengthException;
use Symfony\Component\Notifier\Exception\TransportException;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Message\MessageInterface;
use Symfony\Component\Notifier\Message\SentMessage;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\Test\TransportTestCase;
use Symfony\Component\Notifier\Transport\TransportInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class TurboSmsTransportTest extends TransportTestCase
{
    /**
     * @return TurboSmsTransport
     */
    public function createTransport(HttpClientInterface $client = null): TransportInterface
    {
        return new TurboSmsTransport('authToken', 'sender', $client ?? $this->createMock(HttpClientInterface::class));
    }

    public function toStringProvider(): iterable
    {
        yield ['turbosms://api.turbosms.ua?from=sender', $this->createTransport()];
    }

    public function supportedMessagesProvider(): iterable
    {
        yield [new SmsMessage('380931234567', 'Hello!')];
    }

    public function unsupportedMessagesProvider(): iterable
    {
        yield [new ChatMessage('Hello!')];
        yield [$this->createMock(MessageInterface::class)];
    }

    public function testSuccessfulSend()
    {
        $response = $this->createMock(ResponseInterface::class);
        $response
            ->expects(self::exactly(2))
            ->method('getStatusCode')
            ->willReturn(200)
        ;
        $response
            ->expects(self::once())
            ->method('getContent')
            ->willReturn(json_encode([
                'response_code' => 0,
                'response_status' => 'OK',
                'response_result' => [
                    [
                        'phone' => '380931234567',
                        'response_code' => 0,
                        'message_id' => 'f83f8868-5e46-c6cf-e4fb-615e5a293754',
                        'response_status' => 'OK',
                    ],
                ],
            ]))
        ;

        $client = new MockHttpClient(static function () use ($response): ResponseInterface {
            return $response;
        });

        $message = new SmsMessage('380931234567', 'Тест/Test');

        $transport = $this->createTransport($client);
        $sentMessage = $transport->send($message);

        self::assertInstanceOf(SentMessage::class, $sentMessage);
        self::assertSame('f83f8868-5e46-c6cf-e4fb-615e5a293754', $sentMessage->getMessageId());
    }

    public function testFailedSend()
    {
        $response = $this->createMock(ResponseInterface::class);
        $response
            ->expects(self::exactly(2))
            ->method('getStatusCode')
            ->willReturn(400)
        ;
        $response
            ->expects(self::once())
            ->method('getContent')
            ->willReturn(json_encode([
                'response_code' => 103,
                'response_status' => 'REQUIRED_TOKEN',
                'response_result' => null,
            ]))
        ;

        $client = new MockHttpClient(static function () use ($response): ResponseInterface {
            return $response;
        });

        $message = new SmsMessage('380931234567', 'Тест/Test');

        $transport = $this->createTransport($client);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Unable to send SMS with TurboSMS: Error code 103 with message "REQUIRED_TOKEN".');

        $transport->send($message);
    }

    public function testInvalidFrom()
    {
        $this->expectException(LengthException::class);
        $this->expectExceptionMessage('The sender length of a TurboSMS message must not exceed 20 characters.');

        $message = new SmsMessage('380931234567', 'Hello!');
        $transport = new TurboSmsTransport('authToken', 'abcdefghijklmnopqrstu', $this->createMock(HttpClientInterface::class));

        $transport->send($message);
    }

    public function testInvalidSubjectWithLatinSymbols()
    {
        $message = new SmsMessage('380931234567', str_repeat('z', 1522));
        $transport = new TurboSmsTransport('authToken', 'sender', $this->createMock(HttpClientInterface::class));

        $this->expectException(LengthException::class);
        $this->expectExceptionMessage('The subject length for "latin" symbols of a TurboSMS message must not exceed 1521 characters.');

        $transport->send($message);
    }

    public function testInvalidSubjectWithCyrillicSymbols()
    {
        $message = new SmsMessage('380931234567', str_repeat('z', 661).'Й');
        $transport = new TurboSmsTransport('authToken', 'sender', $this->createMock(HttpClientInterface::class));

        $this->expectException(LengthException::class);
        $this->expectExceptionMessage('The subject length for "cyrillic" symbols of a TurboSMS message must not exceed 661 characters.');

        $transport->send($message);
    }
}
