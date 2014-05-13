<?php

namespace GuzzleHttp\Subscriber\Cache;

use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\ResponseInterface;
use GuzzleHttp\Exception\BadResponseException;

/**
 * Default revalidation strategy
 */
class DefaultRevalidation
{
    /** @var CacheStorageInterface Cache object storing cache data */
    protected $storage;

    /** @var callable */
    protected $canCache;

    /**
     * @param CacheStorageInterface $cache    Cache storage
     * @param callable              $canCache Determines if a message can be cached
     */
    public function __construct(
        CacheStorageInterface $cache,
        callable $canCache = null
    ) {
        $this->storage = $cache;
        $this->canCache = $canCache ?: new DefaultCanCacheStrategy();
    }

    public function revalidate(
        RequestInterface $request,
        ResponseInterface $response
    ) {
        try {
            $revalidate = $this->createRevalidationRequest($request, $response);
            $validateResponse = $revalidate->send();
            if ($validateResponse->getStatusCode() == 200) {
                return $this->handle200Response($request, $validateResponse);
            } elseif ($validateResponse->getStatusCode() == 304) {
                return $this->handle304Response($request, $validateResponse, $response);
            }
        } catch (BadResponseException $e) {
            $this->handleBadResponse($e);
        }

        // Other exceptions encountered in the revalidation request are ignored
        // in hopes that sending a request to the origin server will fix it
        return false;
    }

    public function shouldRevalidate(RequestInterface $request, ResponseInterface $response)
    {
        if ($request->getMethod() != RequestInterface::GET) {
            return false;
        }

        $reqCache = $request->getHeader('Cache-Control');
        $resCache = $response->getHeader('Cache-Control');

        $revalidate = $request->getHeader('Pragma') == 'no-cache' ||
            ($reqCache && ($reqCache->hasDirective('no-cache') || $reqCache->hasDirective('must-revalidate'))) ||
            ($resCache && ($resCache->hasDirective('no-cache') || $resCache->hasDirective('must-revalidate')));

        // Use the strong ETag validator if available and the response contains no Cache-Control directive
        if (!$revalidate && !$reqCache && $response->hasHeader('ETag')) {
            $revalidate = true;
        }

        return $revalidate;
    }

    /**
     * Handles a bad response when attempting to revalidate
     *
     * @param BadResponseException $e Exception encountered
     *
     * @throws BadResponseException
     */
    protected function handleBadResponse(BadResponseException $e)
    {
        // 404 errors mean the resource no longer exists, so remove from
        // cache, and prevent an additional request by throwing the exception
        if ($e->getResponse()->getStatusCode() == 404) {
            $this->storage->delete($e->getRequest());
            throw $e;
        }
    }

    /**
     * Creates a request to use for revalidation
     *
     * @param RequestInterface  $request  Request
     * @param ResponseInterface $response Response to revalidate
     *
     * @return RequestInterface returns a revalidation request
     */
    protected function createRevalidationRequest(
        RequestInterface $request,
        ResponseInterface $response
    ) {
        $revalidate = clone $request;
        $revalidate->removeHeader('Pragma')
            ->removeHeader('Cache-Control')
            ->setHeader('If-Modified-Since', $response->getLastModified() ?: $response->getDate());

        if ($response->getEtag()) {
            $revalidate->setHeader('If-None-Match', $response->getEtag());
        }

        // Remove any cache plugins that might be on the request to prevent infinite recursive revalidations
        $emitter = $revalidate->getEmitter();
        foreach ($emitter->getListeners() as $eventName => $listeners) {
            foreach ($listeners as $listener) {
                if (is_array($listener) && $listener[0] instanceof CachePlugin) {
                    $emitter->removeListener($eventName, $listener);
                }
            }
        }

        return $revalidate;
    }

    /**
     * Handles a 200 response response from revalidating. The server does not support validation, so use this response.
     *
     * @param RequestInterface  $request          Request that was sent
     * @param ResponseInterface $validateResponse Response received
     *
     * @return bool Returns true if valid, false if invalid
     */
    protected function handle200Response(
        RequestInterface $request,
        ResponseInterface $validateResponse
    ) {
        $request->setResponse($validateResponse);
        if ($this->canCache->canCacheResponse($validateResponse)) {
            $this->storage->cache($request, $validateResponse);
        }

        return false;
    }

    /**
     * Handle a 304 response and ensure that it is still valid
     *
     * @param RequestInterface  $request          Request that was sent
     * @param ResponseInterface $validateResponse Response received
     * @param ResponseInterface $response         Original cached response
     *
     * @return bool Returns true if valid, false if invalid
     */
    protected function handle304Response(
        RequestInterface $request,
        ResponseInterface $validateResponse,
        ResponseInterface $response
    ) {
        static $replaceHeaders = ['Date', 'Expires', 'Cache-Control',
            'ETag', 'Last-Modified'];

        // Make sure that this response has the same ETag
        if ($validateResponse->getHeader('ETag') !== $response->getHeader('ETag')) {
            return false;
        }

        // Replace cached headers with any of these headers from the
        // origin server that might be more up to date
        $modified = false;
        foreach ($replaceHeaders as $name) {
            if ($validateResponse->hasHeader($name)) {
                $modified = true;
                $response->setHeader($name, $validateResponse->getHeader($name));
            }
        }

        // Store the updated response in cache
        if ($modified && $this->canCache->canCacheResponse($response)) {
            $this->storage->cache($request, $response);
        }

        return true;
    }
}
