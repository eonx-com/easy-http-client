<?php

declare(strict_types=1);

namespace EonX\EasyHttpClient\Implementations\Symfony;

use Carbon\Carbon;
use EonX\EasyEventDispatcher\Interfaces\EventDispatcherInterface;
use EonX\EasyHttpClient\Data\RequestData;
use EonX\EasyHttpClient\Data\ResponseData;
use EonX\EasyHttpClient\Events\HttpRequestSentEvent;
use EonX\EasyHttpClient\Interfaces\HttpOptionsInterface;
use EonX\EasyHttpClient\Interfaces\RequestDataInterface;
use EonX\EasyHttpClient\Interfaces\RequestDataModifierInterface;
use EonX\EasyUtils\CollectorHelper;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class WithEventsHttpClient implements HttpClientInterface
{
    /**
     * @var \Symfony\Contracts\HttpClient\HttpClientInterface
     */
    private $decorated;

    /**
     * @var \EonX\EasyEventDispatcher\Interfaces\EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var \EonX\EasyHttpClient\Interfaces\RequestDataModifierInterface[]
     */
    private $modifiers;

    /**
     * @var null|bool
     */
    private $modifiersEnabled;

    /**
     * @var string[]
     */
    private $modifiersWhitelist;

    /**
     * @param null|iterable<\EonX\EasyHttpClient\Interfaces\RequestDataModifierInterface> $modifiers
     * @param null|string[] $modifiersWhitelist
     */
    public function __construct(
        EventDispatcherInterface $eventDispatcher,
        ?HttpClientInterface $decorated = null,
        ?iterable $modifiers = null,
        ?bool $modifiersEnabled = null,
        ?array $modifiersWhitelist = null
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->decorated = $decorated ?? HttpClient::create();
        $this->modifiers = CollectorHelper::filterByClassAsArray($modifiers ?? [], RequestDataModifierInterface::class);
        $this->modifiersEnabled = $modifiersEnabled;
        $this->modifiersWhitelist = $modifiersWhitelist ?? [];
    }

    /**
     * @param null|mixed[] $options
     *
     * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     * @throws \Throwable
     */
    public function request(string $method, string $url, ?array $options = null): ResponseInterface
    {
        $options = $options ?? [];
        $extra = $options[HttpOptionsInterface::REQUEST_DATA_EXTRA] ?? null;
        $modifiers = \array_merge($this->modifiers, $options[HttpOptionsInterface::REQUEST_DATA_MODIFIERS] ?? []);
        $modifiersEnabled = $this->modifiersEnabled ??
            $options[HttpOptionsInterface::REQUEST_DATA_MODIFIERS_ENABLED] ?? true;
        $modifiersWhitelist = \array_merge(
            $this->modifiersWhitelist,
            $options[HttpOptionsInterface::REQUEST_DATA_MODIFIERS_WHITELIST] ?? []
        );
        unset(
            $options[HttpOptionsInterface::REQUEST_DATA_EXTRA],
            $options[HttpOptionsInterface::REQUEST_DATA_MODIFIERS],
            $options[HttpOptionsInterface::REQUEST_DATA_MODIFIERS_ENABLED],
            $options[HttpOptionsInterface::REQUEST_DATA_MODIFIERS_WHITELIST]
        );

        $requestData = new RequestData($method, $options, Carbon::now('UTC'), $url);

        if ($modifiersEnabled) {
            $requestData = $this->modifyRequestData($requestData, $modifiersWhitelist, $modifiers);
        }

        $responseData = null;
        $throwable = null;
        $throwableThrownAt = null;

        try {
            $response = $this->decorated->request($method, $url, $options);

            $responseData = new ResponseData(
                $response->getContent(false),
                $response->getHeaders(false),
                Carbon::now('UTC'),
                $response->getStatusCode()
            );

            return $response;
        } catch (\Throwable $throwable) {
            $throwableThrownAt = Carbon::now('UTC');

            throw $throwable;
        } finally {
            $this->eventDispatcher->dispatch(new HttpRequestSentEvent(
                $requestData,
                $responseData,
                $throwable,
                $throwableThrownAt,
                $extra
            ));
        }
    }

    /**
     * @param \Symfony\Contracts\HttpClient\ResponseInterface|iterable<(int|string),\Symfony\Contracts\HttpClient\ResponseInterface> $responses
     */
    public function stream($responses, float $timeout = null): ResponseStreamInterface
    {
        return $this->decorated->stream($responses, $timeout);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): self
    {
        $clone = clone $this;
        $clone->decorated = $this->decorated->withOptions($options);

        return $clone;
    }

    /**
     * @param string[] $modifiersWhitelist
     * @param \EonX\EasyHttpClient\Interfaces\RequestDataModifierInterface[] $modifiers
     */
    private function modifyRequestData(
        RequestDataInterface $data,
        array $modifiersWhitelist,
        array $modifiers
    ): RequestDataInterface {
        // No explicitly allowed modifiers, execute all of them
        if (\count($modifiersWhitelist) < 1) {
            foreach ($modifiers as $modifier) {
                $data = $modifier->modifyRequestData($data);
            }

            return $data;
        }

        // Execute only allowed modifiers
        foreach ($modifiers as $modifier) {
            foreach ($modifiersWhitelist as $allowedModifier) {
                if (\is_a($modifier, $allowedModifier, true)) {
                    $data = $modifier->modifyRequestData($data);
                }
            }
        }

        return $data;
    }
}
