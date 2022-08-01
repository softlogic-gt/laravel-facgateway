<?php
namespace SoftlogicGT\FacGateway;

use Throwable;
use SoapClient;
use Carbon\Carbon;
use LVR\CreditCard\CardCvc;
use LVR\CreditCard\CardNumber;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use SoftlogicGT\FacGateway\Jobs\SendReceipt;
use SoftlogicGT\FacGateway\Jobs\SendReversal;
use Illuminate\Validation\ValidationException;

class FacGateway
{
    protected $approvedInstallments = [3, 6, 10, 12, 18, 24];
    protected $receipt              = [
        'email'   => null,
        'subject' => 'Comprobante de pago',
        'name'    => '',
    ];

    protected $codes = [
        "00" => "Aprobada",
        "01" => "Refiérase al Emisor",
        "02" => "Refiérase al Emisor",
        "05" => "Transacción No Aceptada",
        "12" => "Transacción Inválida",
        "13" => "Monto Inválido",
        "19" => "Transacción no realizada, intente de nuevo 31 Tarjeta no soportada por switch",
        "35" => "Transacción ya ha sido ANULADA",
        "36" => "Transacción a ANULAR no EXISTE",
        "37" => "Transacción de ANULACION REVERSADA",
        "38" => "Transacción a ANULAR con Error",
        "41" => "Tarjeta Extraviada",
        "43" => "Tarjeta Robada",
        "51" => "No tiene fondos disponibles",
        "57" => "Transacción no permitida",
        "58" => "Transacción no permitida en la terminal",
        "65" => "Límite de actividad excedido",
        "80" => "Fecha de Expiración inválida",
        "89" => "Terminal inválida",
        "91" => "Emisor no disponible",
        "94" => "Transacción duplicada",
        "96" => "Error del sistema, intente más tarde",
    ];

    public function __construct(array $config = [])
    {
        if (isset($config['receipt'])) {
            if (isset($config['receipt']['email'])) {
                $this->receipt['email'] = $config['receipt']['email'];
            }

            if (isset($config['receipt']['subject'])) {
                $this->receipt['subject'] = $config['receipt']['subject'];
            }

            if (isset($config['receipt']['name'])) {
                $this->receipt['name'] = $config['receipt']['name'];
            }
        }
    }

    public function sale($creditCard, $expirationMonth, $expirationYear, $cvv2, $amount, $externalId)
    {
        return $this->common($creditCard, $expirationMonth, $expirationYear, $cvv2, $amount, $externalId, '0200');
    }

    public function installments($creditCard, $expirationMonth, $expirationYear, $cvv2, $amount, $externalId, $installments)
    {
        $data = compact("creditCard", "expirationMonth", "expirationYear", "cvv2", "amount", "externalId", "installments");

        $rules = [
            'installments' => ['required', Rule::in($this->approvedInstallments)],
        ];

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $additionalData = 'VC' . str_pad($installments, 2, "0", STR_PAD_LEFT);

        return $this->common($creditCard, $expirationMonth, $expirationYear, $cvv2, $amount, $externalId, '0200', $additionalData);
    }

    public function points($creditCard, $expirationMonth, $expirationYear, $cvv2, $amount, $externalId)
    {
        return $this->common($creditCard, $expirationMonth, $expirationYear, $cvv2, $amount, $externalId, '0200', 'LU');
    }

    public function reversal($creditCard, $expirationMonth, $expirationYear, $cvv2, $amount, $externalId)
    {
        return $this->common($creditCard, $expirationMonth, $expirationYear, $cvv2, $amount, $externalId, '0400');
    }

    protected function common($creditCard, $expirationMonth, $expirationYear, $cvv2, $amount, $externalId, $messageType, $additionalData = '')
    {
        $data = compact("creditCard", "expirationMonth", "expirationYear", "cvv2", "amount", "externalId", "messageType", "additionalData");

        $rules = [
            'creditCard'      => ['required', new CardNumber],
            'cvv2'            => ['required', new CardCvc($creditCard)],
            'expirationMonth' => 'required|numeric|lte:12|gte:1',
            'expirationYear'  => 'required|numeric|lte:99|gte:1',
            'amount'          => 'required|numeric',
            'externalId'      => 'required',
            'messageType'     => 'required',
        ];

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $month      = str_pad($expirationMonth, 2, "0", STR_PAD_LEFT);
        $year       = str_pad($expirationYear, 2, "0", STR_PAD_LEFT);
        $total      = (int) (round($amount, 2) * 100);
        $externalId = str_pad(substr($externalId, -6, 6), 6, "0", STR_PAD_LEFT);

        $timeout = $this->getTimeout();
        try {
            ini_set("default_socket_timeout", $timeout);
            $soapClient = new SoapClient($this->getURL(), [
                "trace"              => 1,
                'connection_timeout' => $timeout,
            ]);
            $params = [
                'AuthorizationRequest' => [
                    'posEntryMode'     => '012',
                    'pan'              => $creditCard,
                    'expdate'          => $year . $month,
                    'amount'           => $total,
                    'cvv2'             => $cvv2,
                    'paymentgwIP'      => request()->ip(),
                    'shopperIP'        => request()->ip(),
                    'merchantServerIP' => request()->ip(),
                    'merchantUser'     => config('laravel-epayserver.user'),
                    'merchantPasswd'   => config('laravel-epayserver.password'),
                    'merchant'         => config('laravel-epayserver.affilliation'),
                    'terminalId'       => config('laravel-epayserver.terminal'),
                    'messageType'      => $messageType,
                    'auditNumber'      => $externalId,
                    'additionalData'   => $additionalData,
                ],
            ];
            $res = $soapClient->AuthorizationRequest($params);
        } catch (Throwable $th) {
            Log::error($th->getMessage());
            if ($messageType != '0400') {
                SendReversal::dispatch($data);
            }
            abort(500, "No fue posible realizar la transacción, intente de nuevo");
        }
        $code = $res->response->responseCode;
        //If succesful response, return full response
        if ($code == '00') {
            if ($this->receipt['email']) {
                $receiptData = [
                    'email'        => $this->receipt['email'],
                    'subject'      => $this->receipt['subject'],
                    'name'         => $this->receipt['name'],
                    'cc'           => '####-####-####-' . substr($creditCard, -4, 4),
                    'date'         => Carbon::now(),
                    'amount'       => $messageType != '0400' ? $total : -$total,
                    'ref_number'   => $res->response->referenceNumber,
                    'auth_number'  => $res->response->authorizationNumber,
                    'audit_number' => $res->response->auditNumber,
                    'merchant'     => config('laravel-epayserver.affilliation'),
                ];

                SendReceipt::dispatch($receiptData);
            }

            return $res->response;
        }
        //If error, return error from list or unknown
        if (array_key_exists($code, $this->codes)) {
            abort(400, $this->codes[$code]);
        }
        abort(400, "Error desconocido: " . $code);
    }

    public function void($auditNumber, $total, $lastDigits = "####")
    {
        $data = compact("auditNumber", "total");

        $rules = [
            'auditNumber' => 'required',
            'total'       => 'required|numeric',
        ];

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $timeout = $this->getTimeout();
        try {
            ini_set("default_socket_timeout", $timeout);
            $soapClient = new SoapClient($this->getURL(), [
                "trace"              => 1,
                'connection_timeout' => $timeout,
            ]);
            $params = [
                'AuthorizationRequest' => [
                    'posEntryMode'     => '012',
                    'paymentgwIP'      => request()->ip(),
                    'shopperIP'        => request()->ip(),
                    'merchantServerIP' => request()->ip(),
                    'merchantUser'     => config('laravel-epayserver.user'),
                    'merchantPasswd'   => config('laravel-epayserver.password'),
                    'merchant'         => config('laravel-epayserver.affilliation'),
                    'terminalId'       => config('laravel-epayserver.terminal'),
                    'auditNumber'      => $auditNumber,
                    'messageType'      => '0202',
                ],
            ];
            $res = $soapClient->AuthorizationRequest($params);
        } catch (Throwable $th) {
            Log::error($th);
            abort(500, "No fue posible realizar la reversión, intente de nuevo");
        }
        $code  = $res->response->responseCode;
        $total = (int) (round($total, 2) * -100);
        //If succesful response, return full response
        if ($code == '00') {
            if ($this->receipt['email']) {
                $receiptData = [
                    'email'        => $this->receipt['email'],
                    'subject'      => $this->receipt['subject'],
                    'name'         => $this->receipt['name'],
                    'cc'           => '####-####-####-' . $lastDigits,
                    'date'         => Carbon::now(),
                    'amount'       => $total,
                    'ref_number'   => $res->response->referenceNumber,
                    'auth_number'  => $res->response->authorizationNumber,
                    'audit_number' => $res->response->auditNumber,
                    'merchant'     => config('laravel-epayserver.affilliation'),
                ];

                SendReceipt::dispatch($receiptData);
            }

            return $res->response;
        }
        //If error, return error from list or unknown
        if (array_key_exists($code, $this->codes)) {
            abort(400, $this->codes[$code]);
        }
        abort(400, "Error desconocido: " . $code);
    }

    protected function getURL()
    {
        return config('laravel-epayserver.test') ? 'https://epaytestvisanet.com.gt/?wsdl' : 'https://epayvisanet.com.gt/?wsdl';
    }

    protected function getTimeout()
    {
        return config('laravel-epayserver.test') ? 30 : 50;
    }
}
