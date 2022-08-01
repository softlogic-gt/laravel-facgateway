<?php
namespace SoftlogicGT\FacGateway\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use SoftlogicGT\FacGateway\Mail\CCReceipt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class SendReceipt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $receiptData = null;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($receiptData)
    {
        $this->receiptData = $receiptData;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Mail::to($this->receiptData['email'])
            ->send(new CCReceipt($this->receiptData));
    }
}
