<?php

namespace App\Service;

use App\Entity\HttpApi;
use App\Entity\HttpApiCallLog;
use App\Entity\Subscriber;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class HttpApiExecutorService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $em
    ) {
    }

    public function interpolatePlaceholders(string $text, ?Subscriber $subscriber): string
    {
        if (!$subscriber) {
            $text = str_replace([
                '{{subscriber.id}}',
                '{{subscriber.name}}',
                '{{subscriber.phone_number}}',
                '{{subscriber.phoneNumber}}',
                '{{subscriber.psid}}',
                '{{subscriber.channel}}',
                '{{subscriber.status}}'
            ], '', $text);
            return preg_replace('/\{\{subscriber\.(custom_attributes|customAttributes)\.[a-zA-Z0-9_-]+\}\}/i', '', $text);
        }

        $replacements = [
            '{{subscriber.id}}' => $subscriber->getId(),
            '{{subscriber.name}}' => $subscriber->getName() ?? '',
            '{{subscriber.phone_number}}' => $subscriber->getPhoneNumber() ?? '',
            '{{subscriber.phoneNumber}}' => $subscriber->getPhoneNumber() ?? '',
            '{{subscriber.psid}}' => $subscriber->getPsid() ?? '',
            '{{subscriber.channel}}' => $subscriber->getChannel() ?? '',
            '{{subscriber.status}}' => $subscriber->getStatus() ?? '',
        ];

        $text = str_replace(array_keys($replacements), array_values($replacements), $text);

        $text = preg_replace_callback('/\{\{subscriber\.(custom_attributes|customAttributes)\.([a-zA-Z0-9_-]+)\}\}/i', function($matches) use ($subscriber) {
            $key = $matches[2];
            $attrs = $subscriber->getCustomAttributes() ?? [];
            return $attrs[$key] ?? '';
        }, $text);

        return $text;
    }

    public function execute(HttpApi $httpApi, Subscriber $subscriber): array
    {
        $endpointUrl = $httpApi->getEndpointUrl();
        $method = $httpApi->getMethod() ?? 'GET';
        $bodyType = $httpApi->getBodyType() ?? 'DEFAULT';
        $bodyData = $httpApi->getBodyData();

        $headers = $httpApi->getHeaders() ?? [];
        $options = $httpApi->getOptions() ?? [];
        $cookies = $httpApi->getCookies() ?? [];

        // 1. Interpolate placeholders in URL
        $interpolatedUrl = $this->interpolatePlaceholders($endpointUrl, $subscriber);

        // 2. Build headers, options, cookies, body
        $requestHeaders = [];
        foreach ($headers as $h) {
            if (!empty($h['key'])) {
                $requestHeaders[trim($h['key'])] = $this->interpolatePlaceholders($h['value'] ?? '', $subscriber);
            }
        }

        $queryParams = [];
        foreach ($options as $o) {
            if (!empty($o['key'])) {
                $queryParams[trim($o['key'])] = $this->interpolatePlaceholders($o['value'] ?? '', $subscriber);
            }
        }

        $cookieStrings = [];
        foreach ($cookies as $c) {
            if (!empty($c['key'])) {
                $cookieStrings[] = trim($c['key']) . '=' . urlencode($this->interpolatePlaceholders($c['value'] ?? '', $subscriber));
            }
        }
        if (!empty($cookieStrings)) {
            $requestHeaders['Cookie'] = implode('; ', $cookieStrings);
        }

        $body = null;
        $formParams = [];

        if ($bodyType === 'JSON' || $bodyType === 'BINARY') {
            $body = $this->interpolatePlaceholders($bodyData ?? '', $subscriber);
            if ($bodyType === 'JSON' && !isset($requestHeaders['Content-Type']) && !isset($requestHeaders['content-type'])) {
                $requestHeaders['Content-Type'] = 'application/json';
            }
        } elseif ($bodyType === 'FORM-DATA' || $bodyType === 'X-WWW-FORM-URLENCODED') {
            $bodyPairs = json_decode($bodyData ?? '[]', true);
            if (is_array($bodyPairs)) {
                foreach ($bodyPairs as $p) {
                    if (!empty($p['key'])) {
                        $formParams[trim($p['key'])] = $this->interpolatePlaceholders($p['value'] ?? '', $subscriber);
                    }
                }
            }
        }

        try {
            $requestOptions = [
                'headers' => $requestHeaders,
                'query' => $queryParams,
                'timeout' => 10,
            ];

            if ($body !== null) {
                $requestOptions['body'] = $body;
            } elseif (!empty($formParams)) {
                $requestOptions['body'] = $formParams;
            }

            $response = $this->httpClient->request($method, $interpolatedUrl, $requestOptions);
            $statusCode = $response->getStatusCode();
            $respHeaders = $response->getHeaders(false);
            $respBody = $response->getContent(false);

            // Log details
            $callLog = new HttpApiCallLog();
            $callLog->setHttpApi($httpApi);
            $callLog->setMethod($method);
            $callLog->setUrl($interpolatedUrl);
            $callLog->setRequestPayload([
                'headers' => $requestHeaders,
                'query' => $queryParams,
                'body' => $body ?? $formParams ?? null
            ]);
            $callLog->setResponseStatus($statusCode);
            $callLog->setResponseHeaders($respHeaders);
            $callLog->setResponseBody($respBody);
            $callLog->setSubscriber($subscriber);
            $this->em->persist($callLog);

            $httpApi->setVerified(true);
            $httpApi->incrementTotalCall();
            if ($statusCode >= 200 && $statusCode < 300) {
                $httpApi->incrementTotalSuccess();
            } else {
                $httpApi->incrementTotalError();
            }
            $this->em->flush();

            return [
                'success' => true,
                'statusCode' => $statusCode,
                'responseHeaders' => $respHeaders,
                'responseBody' => $respBody,
                'interpolatedUrl' => $interpolatedUrl,
            ];

        } catch (\Exception $e) {
            $callLog = new HttpApiCallLog();
            $callLog->setHttpApi($httpApi);
            $callLog->setMethod($method);
            $callLog->setUrl($interpolatedUrl);
            $callLog->setRequestPayload([
                'headers' => $requestHeaders,
                'query' => $queryParams,
                'body' => $body ?? $formParams ?? null
            ]);
            $callLog->setError($e->getMessage());
            $callLog->setSubscriber($subscriber);
            $this->em->persist($callLog);

            $httpApi->incrementTotalCall();
            $httpApi->incrementTotalError();
            $this->em->flush();

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'interpolatedUrl' => $interpolatedUrl,
            ];
        }
    }
}
