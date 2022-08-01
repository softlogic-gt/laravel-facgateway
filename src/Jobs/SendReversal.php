<?php
namespace SoftlogicGT\FacGateway\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use SoftlogicGT\FacGateway\FacGateway;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class SendReversal implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /*
    $data extracts into
    $creditCard, $expirationMonth, $expirationYear, $cvv2, $amount, $externalId, $messageType, $additionalData
     */
    protected $data = [];
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($data)
    {
        $this->data = $data;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        extract($this->data);

        $epay = new FacGateway();
        $epay->reversal($creditCard, $expirationMonth, $expirationYear, $cvv2, $amount, $externalId);
    }
}
