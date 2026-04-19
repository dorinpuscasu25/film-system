<?php

namespace App\Jobs;

use App\Services\AnalyticsBufferService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessVideoAnalyticsEvent implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public array $payload,
    ) {
        $this->onQueue('analytics');
    }

    public function handle(AnalyticsBufferService $buffer): void
    {
        $buffer->appendVideoEvent($this->payload);
    }
}
