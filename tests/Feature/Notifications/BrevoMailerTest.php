<?php

use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

it('sends mail through the Brevo API when MAIL_MAILER=brevo', function () {
    $sent = null;
    $this->app->instance(HttpClientInterface::class, new MockHttpClient(function (string $method, string $url, array $options) use (&$sent) {
        $sent = ['method' => $method, 'url' => $url, 'headers' => $options['normalized_headers'], 'body' => json_decode($options['body'], true)];

        return new MockResponse('{"messageId":"<1@brevo>"}', ['http_code' => 201]);
    }));
    config([
        'mail.default' => 'brevo',
        'services.brevo.key' => 'test-brevo-key',
        'mail.from' => ['address' => 'dormlink@example.com', 'name' => 'DormLink'],
    ]);

    Mail::raw('Your bill is ready.', fn ($m) => $m->to('boarder@example.com')->subject('Bill issued'));

    expect($sent['method'])->toBe('POST')
        ->and($sent['url'])->toBe('https://api.brevo.com/v3/smtp/email')
        ->and($sent['headers']['api-key'][0])->toBe('api-key: test-brevo-key')
        ->and($sent['body']['sender'])->toEqual(['name' => 'DormLink', 'email' => 'dormlink@example.com'])
        ->and($sent['body']['to'][0]['email'])->toBe('boarder@example.com')
        ->and($sent['body']['subject'])->toBe('Bill issued');
});
