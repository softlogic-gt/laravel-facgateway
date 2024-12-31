# Laravel FAC Payment Gateway

Send payment transactions to First Atlantic Commerce service.
You must have an active account for this to work.
The package automatically validates all input data, generates HTML and redirects to specified route.

## Installation

`composer require softlogic-gt/laravel-facgateway`

Set your environment variables

```
FAC_TEST=true
FAC_ID=818181818
FAC_PASSWORD=fadsfadsfdasfda1231231232fasf
FAC_REDIRECT=http://localhost/facresponse
FAC_SUCCESS_ACTION=FacController@success
FAC_ERROR_ACTION=FacController@error
```

## Usage

In the constructor, if the email is specified, a confirmation receipt is sent. The default subject is `Comprobante de pago`. For 3DS, an email address is required

### Sale

```
use SoftlogicGT\FacGateway\FacGateway;

$creditCard = '4000000000000416';
$expirationMonth = '2';
$expirationYear = '26';
$cvv2 = '123';
$amount = 1230.00;
$externalId = '557854';

$server = new FacGateway([
    'receipt' => [
        'email' => 'buyer@email.com',
        'name'  => 'Buyer name',
    ],
]);

$html = $server->sale($creditCard, $expirationMonth, $expirationYear, $cvv2, $amount, $externalId);
```

It will throw an exception if any error is received from FAC, or an HTML to render for 3DS validation

```
return response($html, 200)->header('Content-Type', 'text/html');
```

After rendering the HTML, you will get a `POST` request to `FAC_REDIRECT`, where you have to process the correct response, and you should handle that response like this:

```
    $fc = new FacGateway([
        'receipt' => [
            'name'  => 'Juan Samara',
            'email' => 'jgalindo@softlogic.com.gt',
        ],
    ]);

    return $fc->callback($request);
```

This will then call the corresponding actions specified in the `FAC_SUCCESS_ACTION` or `FAC_ERROR_ACTION`
