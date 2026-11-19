<?php

namespace App\Services\Payments\Mia;

use Illuminate\Http\Client\Response;
use RuntimeException;

class MiaRequestException extends RuntimeException
{
    public function __construct(string $message, public readonly ?Response $response = null)
    {
        $suffix = $response === null ? '' : sprintf(
            ' (HTTP %d%s)',
            $response->status(),
            filled($response->json('errorCode')) ? ', '.$response->json('errorCode') : '',
        );

        parent::__construct($message.$suffix);
    }

    public function bankDescription(): ?string
    {
        $description = $this->response?->json('description');

        return is_string($description) && $description !== '' ? $description : null;
    }

    /** 5xx from the bank: the operation may or may not have happened. */
    public function isServerError(): bool
    {
        return $this->response !== null && $this->response->serverError();
    }
}
