<?php
namespace SoftlogicGT\FacGateway;

use Throwable;
use Carbon\Carbon;
use GuzzleHttp\Client;
use LVR\CreditCard\CardCvc;
use Illuminate\Http\Request;
use LVR\CreditCard\CardNumber;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use SoftlogicGT\FacGateway\Jobs\SendReceipt;
use Illuminate\Validation\ValidationException;

class FacGateway
{
    protected $approvedInstallments = [3, 6, 10, 12, 18, 24];
    protected $receipt              = [
        'email'   => null,
        'subject' => 'Comprobante de pago',
        'name'    => '',
    ];
    protected $redirect = null;

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
        if (isset($config['redirect'])) {
            $this->redirect = $config['redirect'];
        }
    }

    public function sale($creditCard, $expirationMonth, $expirationYear, $cvv2, $amount, $externalId)
    {
        return $this->common(true, $creditCard, $expirationMonth, $expirationYear, $cvv2, $amount, $externalId, 'spi/Sale');
    }

    public function token($creditCard, $expirationMonth, $expirationYear, $cvv2, $externalId)
    {
        $rules = [
            'creditCard'      => ['required', new CardNumber],
            'cvv2'            => ['required', new CardCvc($creditCard)],
            'expirationMonth' => 'required|integer|between:1,12',
            'expirationYear'  => 'required|integer|between:1,99',
        ];

        $data = compact(
            "creditCard", "expirationMonth", "expirationYear",
            "cvv2", "externalId"
        );

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            \Log::error($validator->errors());
            throw new ValidationException($validator);
        }

        return $this->common(true, $creditCard, $expirationMonth, $expirationYear, $cvv2, 0, $externalId, 'spi/RiskMgmt');
    }

    public function salewithtoken($token, $expirationMonth, $expirationYear, $cvv2, $amount, $externalId)
    {
        $rules = [
            'token'           => 'required',
            'expirationMonth' => 'required|integer|between:1,12',
            'expirationYear'  => 'required|integer|between:1,99',
            'cvv2'            => 'required',
            'amount'          => 'required|numeric',
            'externalId'      => 'required',
        ];

        $data = compact(
            "token", "expirationMonth", "expirationYear", "cvv2", "amount", "externalId"
        );

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            \Log::error($validator->errors());
            throw new ValidationException($validator);
        }

        return $this->common(false, $token, $expirationMonth, $expirationYear, $cvv2, $amount, $externalId, 'spi/Sale');
    }

    public function payment($spiToken)
    {
        try {
            $client   = new Client();
            $response = $client->post($this->getURL() . 'spi/Payment', [
                'headers' => [
                    'PowerTranz-PowerTranzId'       => config('laravel-facgateway.id'),
                    'PowerTranz-PowerTranzPassword' => config('laravel-facgateway.password'),
                ],
                'json'    => $spiToken,
            ]);
            $json = json_decode((string) $response->getBody());

            return $json;

        } catch (Throwable $th) {
            Log::error($th->getMessage());
            $code = $th->getCode() ? $th->getCode() : 400;
            abort($code, "No fue posible realizar la transacción" . $th->getMessage());
        }
    }

    public function alive()
    {
        $client   = new Client();
        $response = $client->get($this->getURL() . 'Alive');
        $json     = json_decode((string) $response->getBody());

        return $json;
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
        return $this->common(true, $creditCard, $expirationMonth, $expirationYear, $cvv2, $amount, $externalId, '0400');
    }

    protected function common($isCC, $creditCardOrToken, $expirationMonth, $expirationYear, $cvv2, $amount, $externalId, $messageType, $additionalData = '')
    {
        if (!config('laravel-facgateway.id') || !config('laravel-facgateway.password')) {
            abort(422, 'Debes configurar el FAC ID y Password en tu ambiente');
        }

        $data = compact(
            "creditCardOrToken", "expirationMonth", "expirationYear", "cvv2", "amount",
            "externalId", "messageType", "additionalData"
        );

        if ($messageType == 'spi/RiskMgmt') {
            $three = [
                "ChallengeIndicator"      => "04",
                "AuthenticationIndicator" => "04",
                "MessageCategory"         => "02",
            ];
        } else {
            $three = [
                "ChallengeWindowSize" => 4,
                "ChallengeIndicator"  => "01",
            ];
        }

        $month = str_pad($expirationMonth, 2, "0", STR_PAD_LEFT);
        $year  = str_pad($expirationYear, 2, "0", STR_PAD_LEFT);

        if ($isCC) {
            $source = [
                "CardPan"        => $creditCardOrToken,
                "CardCvv"        => $cvv2,
                "CardExpiration" => $year . $month,
                "CardholderName" => $this->receipt['name'],
            ];

        } else {
            $source = [
                "Token"          => $creditCardOrToken,
                "CardCvv"        => $cvv2,
                "CardExpiration" => $year . $month,
                "CardholderName" => $this->receipt['name'],
            ];
        }

        try {
            $redirectUri = $this->redirect ? $this->redirect : config('laravel-facgateway.redirect');
            $params      = [
                "TransacctionIdentifier" => $externalId,
                "TotalAmount"            => $amount,
                "CurrencyCode"           => "320",
                "ThreeDSecure"           => true,
                "Tokenize"               => true,
                "Source"                 => $source,
                "OrderIdentifier"        => $externalId,
                "AddressMatch"           => false,
                "EmailAddress"           => $this->receipt['email'],
                "ExtendedData"           => [
                    "ThreeDSecure"        => $three,
                    "MerchantResponseUrl" => $redirectUri,
                ],
            ];

            Log::warning('Sending params');
            Log::warning(json_encode($params));

            $client   = new Client();
            $response = $client->post($this->getURL() . $messageType, [
                'headers' => [
                    'PowerTranz-PowerTranzId'       => config('laravel-facgateway.id'),
                    'PowerTranz-PowerTranzPassword' => config('laravel-facgateway.password'),
                ],
                'json'    => $params,
            ]);
            $json = json_decode((string) $response->getBody());
        } catch (Throwable $th) {
            Log::error($th->getMessage());
            $code = $th->getCode() ? $th->getCode() : 400;
            abort($code, "No fue posible realizar la transacción, intente de nuevo");
        }

        if (property_exists($json, 'Errors')) {
            abort(400, $json->Errors[0]->Message);
        }

        return $json->RedirectData;
    }

    // public function void($auditNumber, $total, $lastDigits = "####")
    // {
    //     $data = compact("auditNumber", "total");

    //     $rules = [
    //         'auditNumber' => 'required',
    //         'total'       => 'required|numeric',
    //     ];

    //     $validator = Validator::make($data, $rules);

    //     if ($validator->fails()) {
    //         throw new ValidationException($validator);
    //     }

    //     $timeout = $this->getTimeout();
    //     try {
    //         ini_set("default_socket_timeout", $timeout);
    //         $soapClient = new SoapClient($this->getURL(), [
    //             "trace"              => 1,
    //             'connection_timeout' => $timeout,
    //         ]);
    //         $params = [
    //             'AuthorizationRequest' => [
    //                 'posEntryMode'     => '012',
    //                 'paymentgwIP'      => request()->ip(),
    //                 'shopperIP'        => request()->ip(),
    //                 'merchantServerIP' => request()->ip(),
    //                 'merchantUser'     => config('laravel-facgateway.user'),
    //                 'merchantPasswd'   => config('laravel-facgateway.password'),
    //                 'merchant'         => config('laravel-facgateway.affilliation'),
    //                 'terminalId'       => config('laravel-facgateway.terminal'),
    //                 'auditNumber'      => $auditNumber,
    //                 'messageType'      => '0202',
    //             ],
    //         ];
    //         $res = $soapClient->AuthorizationRequest($params);
    //     } catch (Throwable $th) {
    //         Log::error($th);
    //         abort(500, "No fue posible realizar la reversión, intente de nuevo");
    //     }
    //     $code  = $res->response->responseCode;
    //     $total = (int) (round($total, 2) * -100);
    //     //If succesful response, return full response
    //     if ($code == '00') {
    //         if ($this->receipt['email']) {
    //             $receiptData = [
    //                 'email'        => $this->receipt['email'],
    //                 'subject'      => $this->receipt['subject'],
    //                 'name'         => $this->receipt['name'],
    //                 'cc'           => '####-####-####-' . $lastDigits,
    //                 'date'         => Carbon::now(),
    //                 'amount'       => $total,
    //                 'ref_number'   => $res->response->referenceNumber,
    //                 'auth_number'  => $res->response->authorizationNumber,
    //                 'audit_number' => $res->response->auditNumber,
    //                 'merchant'     => config('laravel-facgateway.affilliation'),
    //             ];

    //             SendReceipt::dispatch($receiptData);
    //         }

    //         return $res->response;
    //     }
    //     //If error, return error from list or unknown
    //     if (array_key_exists($code, $this->codes)) {
    //         abort(400, $this->codes[$code]);
    //     }
    //     abort(400, "Error desconocido: " . $code);
    // }

    public function callback(Request $request)
    {
        $response  = json_decode($request->Response);
        $code      = $response->IsoResponseCode;
        $message   = $response->ResponseMessage;
        $cardBrand = $response->CardBrand;

        if ($code == '3D0') {
            $eci = $response->RiskManagement->ThreeDSecure->Eci;
            switch ($cardBrand) {
                case 'Visa':
                case 'American Express':
                    if (!in_array($eci, ['05', '06'])) {
                        return $this->error($response, 'Autenticación 3Ds fallida');
                    }
                    break;

                case 'MasterCard':
                    if (!in_array($eci, ['01', '02'])) {
                        return $this->error($response, 'Autenticación 3Ds fallida');
                    }
                    break;
            }
            $spiToken = $response->SpiToken;

            try {
                $client   = new Client();
                $response = $client->post($this->getURL() . 'spi/payment', [
                    'headers' => [
                        'PowerTranz-PowerTranzId'       => config('laravel-facgateway.id'),
                        'PowerTranz-PowerTranzPassword' => config('laravel-facgateway.password'),
                    ],
                    'json'    => $spiToken,
                ]);
                $json = json_decode((string) $response->getBody());
            } catch (Throwable $th) {
                return $this->error($response, "Error SPI, token incorrecto." . $th->getMessage());
            }
            if (!$json->Approved) {
                if (property_exists($json, 'Errors')) {
                    return $this->error($response, $json->Errors[0]->Message);
                } else {
                    return $this->error($response, $json->ResponseMessage);
                }
            }

            return $this->success($json);

        } else {
            return $this->error($response, "Hubo un error " . $message);
        }
    }

    protected function error($response, $error)
    {
        $ret = [
            'error'    => $error,
            'response' => (array) $response,
        ];

        return redirect()->action(config('laravel-facgateway.error_action'), $ret);
    }

    protected function success($response)
    {
        if ($this->receipt['email']) {
            try {

                $receiptData = [
                    'email'        => $this->receipt['email'],
                    'subject'      => $this->receipt['subject'],
                    'name'         => $this->receipt['name'],
                    'cc'           => $response->CardBrand,
                    'date'         => Carbon::now(),
                    'amount'       => $response->TotalAmount,
                    'ref_number'   => $response->OrderIdentifier,
                    'auth_number'  => $response->AuthorizationCode,
                    'audit_number' => $response->TransactionIdentifier,
                    'merchant'     => '',
                ];

                SendReceipt::dispatch($receiptData);
            } catch (\Throwable $th) {
                Log::error('FACGATEWAY: Error sending email ' . $th->getMessage());
            }
        }

        return redirect()->action(config('laravel-facgateway.success_action'), (array) $response);
    }

    protected function getURL()
    {
        return config('laravel-facgateway.test') ? 'https://staging.ptranz.com/api/' : 'https://gateway.ptranz.com/api/';
    }

    protected function getTimeout()
    {
        return config('laravel-facgateway.test') ? 30 : 50;
    }
}
