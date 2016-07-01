<?php

/*
 * This file is part of the FOSHttpCache package.
 *
 * (c) FriendsOfSymfony <http://friendsofsymfony.github.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FOS\HttpCache\Test;

use FOS\HttpCache\SymfonyCache\CacheInvalidationInterface;
use FOS\HttpCache\SymfonyCache\EventDispatchingHttpCache;
use FOS\HttpCache\SymfonyCache\CacheEvent;
use FOS\HttpCache\SymfonyCache\Events;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * This test ensures that the EventDispatchingHttpCache trait is correctly used.
 */
abstract class EventDispatchingHttpCacheTestCase extends \PHPUnit_Framework_TestCase
{
    /**
     * Specify the CacheInvalidationInterface HttpCache class to test.
     *
     * @return string Fully qualified class name of the AppCache
     */
    abstract protected function getCacheClass();

    /**
     * Create a partial mock of the HttpCache to only test some methods.
     *
     * @param array $mockedMethods List of methods to mock.
     *
     * @return CacheInvalidationInterface|EventDispatchingHttpCache|\PHPUnit_Framework_MockObject_MockObject
     */
    protected function getHttpCachePartialMock(array $mockedMethods = null)
    {
        $mock = $this
            ->getMockBuilder($this->getCacheClass())
            ->setMethods($mockedMethods)
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $this->assertInstanceOf('FOS\HttpCache\SymfonyCache\CacheInvalidationInterface', $mock);

        // Force setting options property since we can't use original constructor.
        $options = [
            'debug' => false,
            'default_ttl' => 0,
            'private_headers' => ['Authorization', 'Cookie'],
            'allow_reload' => false,
            'allow_revalidate' => false,
            'stale_while_revalidate' => 2,
            'stale_if_error' => 60,
        ];

        $refHttpCache = new \ReflectionClass('Symfony\Component\HttpKernel\HttpCache\HttpCache');
        // Workaround for Symfony 2.3 where $options property is not defined.
        if (!$refHttpCache->hasProperty('options')) {
            $mock->options = $options;
        } else {
            $refOptions = $refHttpCache->getProperty('options');
            $refOptions->setAccessible(true);
            $refOptions->setValue($mock, $options);
        }

        return $mock;
    }

    /**
     * Set the store property on a HttpCache to a StoreInterface expecting one write with request and response.
     *
     * @param CacheInvalidationInterface $httpCache
     * @param Request                    $request
     * @param Response                   $response
     */
    protected function setStoreMock(CacheInvalidationInterface $httpCache, Request $request, Response $response)
    {
        $store = $this->getMock('Symfony\Component\HttpKernel\HttpCache\StoreInterface');
        $store
            ->expects($this->once())
            ->method('write')
            ->with($request, $response)
        ;
        $refHttpCache = new \ReflectionClass('Symfony\Component\HttpKernel\HttpCache\HttpCache');
        $refStore = $refHttpCache->getProperty('store');
        $refStore->setAccessible(true);
        $refStore->setValue($httpCache, $store);
    }

    /**
     * Assert that preHandle and postHandle are called.
     */
    public function testHandleCalled()
    {
        $catch = true;
        $request = Request::create('/foo', 'GET');
        $response = new Response();

        $httpCache = $this->getHttpCachePartialMock(['lookup']);
        $subscriber = new TestSubscriber($this, $httpCache, $request);
        $httpCache->addSubscriber($subscriber);
        $httpCache
            ->expects($this->any())
            ->method('lookup')
            ->with($request)
            ->will($this->returnValue($response))
        ;

        $this->assertSame($response, $httpCache->handle($request, HttpKernelInterface::MASTER_REQUEST, $catch));
        $this->assertEquals(1, $subscriber->preHandleCalls);
        $this->assertEquals(1, $subscriber->postHandleCalls);
    }

    /**
     * Assert that when preHandle returns a response, that response is used and the normal kernel flow stopped.
     *
     * @depends testHandleCalled
     */
    public function testPreHandleReturnEarly()
    {
        $catch = true;
        $request = Request::create('/foo', 'GET');
        $response = new Response();

        $httpCache = $this->getHttpCachePartialMock(['lookup']);
        $subscriber = new TestSubscriber($this, $httpCache, $request);
        $subscriber->preHandleResponse = $response;
        $httpCache->addSubscriber($subscriber);
        $httpCache
            ->expects($this->never())
            ->method('lookup')
        ;

        $this->assertSame($response, $httpCache->handle($request, HttpKernelInterface::MASTER_REQUEST, $catch));
        $this->assertEquals(1, $subscriber->preHandleCalls);
        $this->assertEquals(1, $subscriber->postHandleCalls);
    }

    /**
     * Assert that postHandle can update the response.
     *
     * @depends testHandleCalled
     */
    public function testPostHandleReturn()
    {
        $catch = true;
        $request = Request::create('/foo', 'GET');
        $regularResponse = new Response();
        $postResponse = new Response();

        $httpCache = $this->getHttpCachePartialMock(['lookup']);
        $subscriber = new TestSubscriber($this, $httpCache, $request);
        $subscriber->postHandleResponse = $postResponse;
        $httpCache->addSubscriber($subscriber);
        $httpCache
            ->expects($this->any())
            ->method('lookup')
            ->with($request)
            ->will($this->returnValue($regularResponse))
        ;

        $this->assertSame($postResponse, $httpCache->handle($request, HttpKernelInterface::MASTER_REQUEST, $catch));
        $this->assertEquals(1, $subscriber->preHandleCalls);
        $this->assertEquals(1, $subscriber->postHandleCalls);
    }

    /**
     * Assert that postHandle is called and the response can be updated even when preHandle returned a response.
     *
     * @depends testHandleCalled
     */
    public function testPostHandleAfterPreHandle()
    {
        $catch = true;
        $request = Request::create('/foo', 'GET');
        $preResponse = new Response();
        $postResponse = new Response();

        $httpCache = $this->getHttpCachePartialMock(['lookup']);
        $subscriber = new TestSubscriber($this, $httpCache, $request);
        $subscriber->preHandleResponse = $preResponse;
        $subscriber->postHandleResponse = $postResponse;
        $httpCache->addSubscriber($subscriber);
        $httpCache
            ->expects($this->never())
            ->method('lookup')
        ;

        $this->assertSame($postResponse, $httpCache->handle($request, HttpKernelInterface::MASTER_REQUEST, $catch));
        $this->assertEquals(1, $subscriber->preHandleCalls);
        $this->assertEquals(1, $subscriber->postHandleCalls);
    }

    /**
     * Assert that preStore is called.
     */
    public function testPreStoreCalled()
    {
        $request = Request::create('/foo', 'GET');
        $response = new Response();

        $httpCache = $this->getHttpCachePartialMock();
        $subscriber = new TestSubscriber($this, $httpCache, $request);
        $httpCache->addSubscriber($subscriber);

        $this->setStoreMock($httpCache, $request, $response);

        $refHttpCache = new \ReflectionObject($httpCache);
        $method = $refHttpCache->getMethod('store');
        $method->setAccessible(true);
        $method->invokeArgs($httpCache, [$request, $response]);
        $this->assertEquals(1, $subscriber->preStoreCalls);
    }

    /**
     * Assert that preStore response is used when provided.
     */
    public function testPreStoreResponse()
    {
        $request = Request::create('/foo', 'GET');
        $regularResponse = new Response();
        $preStoreResponse = new Response();

        $httpCache = $this->getHttpCachePartialMock();
        $subscriber = new TestSubscriber($this, $httpCache, $request);
        $subscriber->preStoreResponse = $preStoreResponse;
        $httpCache->addSubscriber($subscriber);

        $this->setStoreMock($httpCache, $request, $preStoreResponse);

        $refHttpCache = new \ReflectionObject($httpCache);
        $method = $refHttpCache->getMethod('store');
        $method->setAccessible(true);
        $method->invokeArgs($httpCache, [$request, $regularResponse]);
        $this->assertEquals(1, $subscriber->preStoreCalls);
    }

    /**
     * Assert that preInvalidate is called.
     */
    public function testPreInvalidateCalled()
    {
        $catch = true;
        $request = Request::create('/foo', 'GET');
        $response = new Response('', 500);

        $httpCache = $this->getHttpCachePartialMock(['pass']);
        $subscriber = new TestSubscriber($this, $httpCache, $request);
        $httpCache->addSubscriber($subscriber);
        $httpCache
            ->expects($this->any())
            ->method('pass')
            ->with($request)
            ->will($this->returnValue($response))
        ;
        $refHttpCache = new \ReflectionObject($httpCache);
        $method = $refHttpCache->getMethod('invalidate');
        $method->setAccessible(true);

        $this->assertSame($response, $method->invokeArgs($httpCache, [$request, $catch]));
        $this->assertEquals(1, $subscriber->preInvalidateCalls);
    }

    /**
     * Assert that when preInvalidate returns a response, that response is used and the normal kernel flow stopped.
     *
     * @depends testPreInvalidateCalled
     */
    public function testPreInvalidateReturnEarly()
    {
        $catch = true;
        $request = Request::create('/foo', 'GET');
        $response = new Response('', 400);

        $httpCache = $this->getHttpCachePartialMock(['pass']);
        $subscriber = new TestSubscriber($this, $httpCache, $request);
        $subscriber->preInvalidateResponse = $response;
        $httpCache->addSubscriber($subscriber);
        $httpCache
            ->expects($this->never())
            ->method('pass')
        ;
        $refHttpCache = new \ReflectionObject($httpCache);
        $method = $refHttpCache->getMethod('invalidate');
        $method->setAccessible(true);

        $this->assertSame($response, $method->invokeArgs($httpCache, [$request, $catch]));
        $this->assertEquals(1, $subscriber->preInvalidateCalls);
    }
}

class TestSubscriber implements EventSubscriberInterface
{
    /**
     * @var int Count how many times preHandle has been called.
     */
    public $preHandleCalls = 0;

    /**
     * @var int Count how many times postHandle has been called.
     */
    public $postHandleCalls = 0;

    /**
     * @var int Count how many times preStore has been called.
     */
    public $preStoreCalls = 0;

    /**
     * @var int Count how many times preInvalidate has been called.
     */
    public $preInvalidateCalls = 0;

    /**
     * @var Response A response to set during the preHandle
     */
    public $preHandleResponse;

    /**
     * @var Response A response to set during the postHandle
     */
    public $postHandleResponse;

    /**
     * @var Response A response to set during the preStore
     */
    public $preStoreResponse;

    /**
     * @var Response A response to set during the preInvalidate
     */
    public $preInvalidateResponse;

    /**
     * @var EventDispatchingHttpCacheTestCase To do assertions
     */
    private $test;

    /**
     * @var CacheInvalidationInterface The kernel to ensure the event carries the correct kernel
     */
    private $kernel;

    /**
     * @var Request The request to ensure the event carries the correct request
     */
    private $request;

    public function __construct(
        EventDispatchingHttpCacheTestCase $test,
        CacheInvalidationInterface $kernel,
        Request $request
    ) {
        $this->test = $test;
        $this->kernel = $kernel;
        $this->request = $request;
    }

    public static function getSubscribedEvents()
    {
        return [
            Events::PRE_HANDLE => 'preHandle',
            Events::POST_HANDLE => 'postHandle',
            Events::PRE_STORE => 'preStore',
            Events::PRE_INVALIDATE => 'preInvalidate',
        ];
    }

    public function preHandle(CacheEvent $event)
    {
        $this->test->assertSame($this->kernel, $event->getKernel());
        $this->test->assertSame($this->request, $event->getRequest());
        if ($this->preHandleResponse) {
            $event->setResponse($this->preHandleResponse);
        }
        ++$this->preHandleCalls;
    }

    public function postHandle(CacheEvent $event)
    {
        $this->test->assertSame($this->kernel, $event->getKernel());
        $this->test->assertSame($this->request, $event->getRequest());
        if ($this->postHandleResponse) {
            $event->setResponse($this->postHandleResponse);
        }
        ++$this->postHandleCalls;
    }

    public function preStore(CacheEvent $event)
    {
        $this->test->assertSame($this->kernel, $event->getKernel());
        $this->test->assertSame($this->request, $event->getRequest());
        if ($this->preStoreResponse) {
            $event->setResponse($this->preStoreResponse);
        }
        ++$this->preStoreCalls;
    }

    public function preInvalidate(CacheEvent $event)
    {
        $this->test->assertSame($this->kernel, $event->getKernel());
        $this->test->assertSame($this->request, $event->getRequest());
        if ($this->preInvalidateResponse) {
            $event->setResponse($this->preInvalidateResponse);
        }
        ++$this->preInvalidateCalls;
    }
}
