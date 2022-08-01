<?php
namespace SoftlogicGT\FacGateway\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CCReceipt extends Mailable
{
    use Queueable, SerializesModels;

    public $receiptData;
    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($receiptData)
    {
        $this->receiptData = $receiptData;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this
            ->subject($this->receiptData['subject'])
            ->view('laravel-facgateway::receipt');
    }
}
