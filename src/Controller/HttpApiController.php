<?php

namespace App\Controller;

use App\Entity\HttpApi;
use App\Entity\HttpApiCallLog;
use App\Entity\Subscriber;
use App\Service\HttpApiExecutorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/settings/http-api')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class HttpApiController extends AbstractController
{
    #[Route('', name: 'app_http_api_index', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $status = $request->query->get('status', 'all');
        $verified = $request->query->get('verified', 'all');
        $search = trim($request->query->get('search', ''));

        $qb = $em->getRepository(HttpApi::class)->createQueryBuilder('h');

        if ($status !== 'all') {
            $qb->andWhere('h.status = :status')
               ->setParameter('status', $status);
        }

        if ($verified !== 'all') {
            $qb->andWhere('h.verified = :verified')
               ->setParameter('verified', $verified === 'verified');
        }

        if ($search !== '') {
            $qb->andWhere('h.name LIKE :search OR h.endpointUrl LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        $qb->orderBy('h.id', 'DESC');
        $httpApis = $qb->getQuery()->getResult();

        return $this->render('http_api/index.html.twig', [
            'httpApis' => $httpApis,
            'status' => $status,
            'verified' => $verified,
            'search' => $search,
        ]);
    }

    #[Route('/save', name: 'app_http_api_save', methods: ['POST'])]
    public function save(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $id = $request->request->get('id');
        $name = trim($request->request->get('name', ''));
        $endpointUrl = trim($request->request->get('endpointUrl', ''));
        $method = trim($request->request->get('method', 'GET'));
        $channel = trim($request->request->get('channel', 'global'));
        $testSubscriberId = trim($request->request->get('testSubscriberId', ''));
        $status = trim($request->request->get('status', 'active'));
        $bodyType = trim($request->request->get('bodyType', 'DEFAULT'));
        $bodyData = $request->request->get('bodyData');

        $headers = json_decode($request->request->get('headers', '[]'), true);
        $options = json_decode($request->request->get('options', '[]'), true);
        $cookies = json_decode($request->request->get('cookies', '[]'), true);

        if (empty($name) || empty($endpointUrl)) {
            return new JsonResponse(['success' => false, 'error' => 'API Name and Endpoint URL are required.'], 400);
        }

        if ($id) {
            $httpApi = $em->getRepository(HttpApi::class)->find($id);
            if (!$httpApi) {
                return new JsonResponse(['success' => false, 'error' => 'HTTP API connection not found.'], 404);
            }
        } else {
            $httpApi = new HttpApi();
        }

        $httpApi->setName($name);
        $httpApi->setEndpointUrl($endpointUrl);
        $httpApi->setMethod($method);
        $httpApi->setChannel($channel);
        $httpApi->setTestSubscriberId($testSubscriberId !== '' ? $testSubscriberId : null);
        $httpApi->setStatus($status);
        $httpApi->setBodyType($bodyType);
        $httpApi->setBodyData($bodyData);
        $httpApi->setHeaders($headers);
        $httpApi->setOptions($options);
        $httpApi->setCookies($cookies);

        $em->persist($httpApi);
        $em->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'HTTP API saved successfully.',
            'id' => $httpApi->getId()
        ]);
    }

    #[Route('/delete/{id}', name: 'app_http_api_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, EntityManagerInterface $em): JsonResponse
    {
        $httpApi = $em->getRepository(HttpApi::class)->find($id);
        if (!$httpApi) {
            return new JsonResponse(['success' => false, 'error' => 'HTTP API connection not found.'], 404);
        }

        $em->remove($httpApi);
        $em->flush();

        return new JsonResponse(['success' => true, 'message' => 'HTTP API deleted successfully.']);
    }

    #[Route('/verify', name: 'app_http_api_verify', methods: ['POST'])]
    public function verify(Request $request, HttpClientInterface $httpClient, EntityManagerInterface $em, HttpApiExecutorService $apiExecutor): JsonResponse
    {
        $id = $request->request->get('id');
        $endpointUrl = trim($request->request->get('endpointUrl', ''));
        $method = trim($request->request->get('method', 'GET'));
        $testSubscriberId = trim($request->request->get('testSubscriberId', ''));
        $bodyType = trim($request->request->get('bodyType', 'DEFAULT'));
        $bodyData = $request->request->get('bodyData');

        $headers = json_decode($request->request->get('headers', '[]'), true);
        $options = json_decode($request->request->get('options', '[]'), true);
        $cookies = json_decode($request->request->get('cookies', '[]'), true);

        if (empty($endpointUrl)) {
            return new JsonResponse(['success' => false, 'error' => 'Endpoint URL is required for verification.'], 400);
        }

        // Fetch httpApi entity if exists, to log stats
        $httpApi = null;
        if ($id) {
            $httpApi = $em->getRepository(HttpApi::class)->find($id);
        }

        // 1. Resolve test subscriber for placeholders
        $subscriber = null;
        if ($testSubscriberId !== '') {
            $subscriber = $em->getRepository(Subscriber::class)->find($testSubscriberId);
            if (!$subscriber) {
                $subscriber = $em->getRepository(Subscriber::class)->findOneBy(['phoneNumber' => $testSubscriberId]);
            }
            if (!$subscriber) {
                $subscriber = $em->getRepository(Subscriber::class)->findOneBy(['psid' => $testSubscriberId]);
            }
        }
        if (!$subscriber) {
            $subscriber = $em->getRepository(Subscriber::class)->findOneBy([]);
        }

        // 2. Interpolate placeholders in URL
        $interpolatedUrl = $apiExecutor->interpolatePlaceholders($endpointUrl, $subscriber);

        // 3. Build headers, options, cookies, body
        $requestHeaders = [];
        foreach ($headers as $h) {
            if (!empty($h['key'])) {
                $requestHeaders[trim($h['key'])] = $apiExecutor->interpolatePlaceholders($h['value'] ?? '', $subscriber);
            }
        }

        $queryParams = [];
        foreach ($options as $o) {
            if (!empty($o['key'])) {
                $queryParams[trim($o['key'])] = $apiExecutor->interpolatePlaceholders($o['value'] ?? '', $subscriber);
            }
        }

        $cookieStrings = [];
        foreach ($cookies as $c) {
            if (!empty($c['key'])) {
                $cookieStrings[] = trim($c['key']) . '=' . urlencode($apiExecutor->interpolatePlaceholders($c['value'] ?? '', $subscriber));
            }
        }
        if (!empty($cookieStrings)) {
            $requestHeaders['Cookie'] = implode('; ', $cookieStrings);
        }

        $body = null;
        $formParams = [];

        if ($bodyType === 'JSON' || $bodyType === 'BINARY') {
            $body = $apiExecutor->interpolatePlaceholders($bodyData ?? '', $subscriber);
            if ($bodyType === 'JSON' && !isset($requestHeaders['Content-Type']) && !isset($requestHeaders['content-type'])) {
                $requestHeaders['Content-Type'] = 'application/json';
            }
        } elseif ($bodyType === 'FORM-DATA' || $bodyType === 'X-WWW-FORM-URLENCODED') {
            $bodyPairs = json_decode($bodyData ?? '[]', true);
            if (is_array($bodyPairs)) {
                foreach ($bodyPairs as $p) {
                    if (!empty($p['key'])) {
                        $formParams[trim($p['key'])] = $apiExecutor->interpolatePlaceholders($p['value'] ?? '', $subscriber);
                    }
                }
            }
        }

        // 4. Fire HttpClient call
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

            $response = $httpClient->request($method, $interpolatedUrl, $requestOptions);
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
            $em->persist($callLog);

            if ($httpApi) {
                $httpApi->setVerified(true);
                $httpApi->incrementTotalCall();
                if ($statusCode >= 200 && $statusCode < 300) {
                    $httpApi->incrementTotalSuccess();
                } else {
                    $httpApi->incrementTotalError();
                }
            }
            $em->flush();

            return new JsonResponse([
                'success' => true,
                'statusCode' => $statusCode,
                'responseHeaders' => $respHeaders,
                'responseBody' => $respBody,
                'interpolatedUrl' => $interpolatedUrl,
            ]);

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
            $em->persist($callLog);

            if ($httpApi) {
                $httpApi->incrementTotalCall();
                $httpApi->incrementTotalError();
            }
            $em->flush();

            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
                'interpolatedUrl' => $interpolatedUrl,
            ], 500);
        }
    }

    #[Route('/logs', name: 'app_http_api_logs', methods: ['GET'])]
    public function logs(EntityManagerInterface $em): JsonResponse
    {
        $logs = $em->getRepository(HttpApiCallLog::class)->findBy([], ['id' => 'DESC'], 50);
        $data = [];
        foreach ($logs as $log) {
            $data[] = [
                'id' => $log->getId(),
                'apiName' => $log->getHttpApi() ? $log->getHttpApi()->getName() : 'Verification Simulator',
                'method' => $log->getMethod(),
                'url' => $log->getUrl(),
                'status' => $log->getResponseStatus(),
                'error' => $log->getError(),
                'subscriber' => $log->getSubscriber() ? $log->getSubscriber()->getName() : 'None',
                'createdAt' => $log->getCreatedAt()->format('Y-m-d H:i:s'),
                'payload' => $log->getRequestPayload(),
                'responseHeaders' => $log->getResponseHeaders(),
                'responseBody' => $log->getResponseBody()
            ];
        }
        return new JsonResponse(['success' => true, 'logs' => $data]);
    }

    #[Route('/import', name: 'app_http_api_import', methods: ['POST'])]
    public function import(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $json = $request->request->get('configJson', '');
        $data = json_decode($json, true);

        if (!$data || empty($data['name']) || empty($data['endpointUrl'])) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid configuration JSON.'], 400);
        }

        $httpApi = new HttpApi();
        $httpApi->setName($data['name']);
        $httpApi->setEndpointUrl($data['endpointUrl']);
        $httpApi->setMethod($data['method'] ?? 'GET');
        $httpApi->setChannel($data['channel'] ?? 'global');
        $httpApi->setTestSubscriberId($data['testSubscriberId'] ?? null);
        $httpApi->setStatus($data['status'] ?? 'active');
        $httpApi->setBodyType($data['bodyType'] ?? 'DEFAULT');
        $httpApi->setBodyData($data['bodyData'] ?? null);
        $httpApi->setHeaders($data['headers'] ?? []);
        $httpApi->setOptions($data['options'] ?? []);
        $httpApi->setCookies($data['cookies'] ?? []);

        $em->persist($httpApi);
        $em->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Configuration imported successfully.',
            'id' => $httpApi->getId()
        ]);
    }

    #[Route('/export/{id}', name: 'app_http_api_export', methods: ['GET'])]
    public function export(int $id, EntityManagerInterface $em): JsonResponse
    {
        $httpApi = $em->getRepository(HttpApi::class)->find($id);
        if (!$httpApi) {
            return new JsonResponse(['success' => false, 'error' => 'HTTP API connection not found.'], 404);
        }

        $data = [
            'name' => $httpApi->getName(),
            'endpointUrl' => $httpApi->getEndpointUrl(),
            'method' => $httpApi->getMethod(),
            'channel' => $httpApi->getChannel(),
            'testSubscriberId' => $httpApi->getTestSubscriberId(),
            'status' => $httpApi->getStatus(),
            'bodyType' => $httpApi->getBodyType(),
            'bodyData' => $httpApi->getBodyData(),
            'headers' => $httpApi->getHeaders(),
            'options' => $httpApi->getOptions(),
            'cookies' => $httpApi->getCookies(),
        ];

        return new JsonResponse(['success' => true, 'config' => $data]);
    }
}
