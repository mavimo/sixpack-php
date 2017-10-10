<?php

declare(strict_types=1);

namespace SeatGeek\Sixpack\Response;

abstract class Base
{
    /**
     * @var array
     */
    protected $response = [];

    /**
     * @var array
     */
    protected $meta = [];

    public function __construct(string $jsonResponse, array $meta)
    {
        $this->response = json_decode($jsonResponse, true);
        $this->meta = $meta;
    }

    public function getSuccess(): bool
    {
        return ($this->meta['http_code'] === 200);
    }

    public function getStatus(): int
    {
        return (int) $this->meta['http_code'];
    }

    public function getCalledUrl(): string
    {
        return $this->meta['url'];
    }

    public function getClientId(): string
    {
        return $this->response['client_id'];
    }
}
