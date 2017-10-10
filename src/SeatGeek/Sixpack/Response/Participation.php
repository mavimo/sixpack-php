<?php

declare(strict_types=1);

namespace SeatGeek\Sixpack\Response;

use SeatGeek\Sixpack\Response\Base;

class Participation extends Base
{
    private $control = null;

    public function __construct(string $jsonResponse, array $meta, string $control = null)
    {
        if ($control !== null) {
            $this->control = $control;
        }

        parent::__construct($jsonResponse, $meta);
    }

    public function getExperiment(): string
    {
        return $this->response['experiment'];
    }

    public function getAlternative(): string
    {
        if (!$this->getSuccess()) {
            return $this->control;
        }

        return $this->response['alternative']['name'];
    }
}
