<table>
    <tbody>
        <tr>
            <td>Método de pago</td>
            <td>FAC Gateway</td>
        </tr>
        <tr>
            <td>Fecha transacción</td>
            <td>{{ $receiptData['date']->format('d-m-Y H:i') }}</td>
        </tr>
        <tr>
            <td>Monto de la venta</td>
            <td>Q. {{ number_format($receiptData['amount'],2) }}</td>
        </tr>
        <tr>
            <td>Nombre tarjeta</td>
            <td>{{ $receiptData['name'] }}</td>
        </tr>
        <tr>
            <td>Tarjeta</td>
            <td>{{ $receiptData['cc'] }}</td>
        </tr>
        <tr>
            <td>Número de referencia</td>
            <td>{{ $receiptData['ref_number'] }}</td>
        </tr>
        <tr>
            <td>Número de autorización</td>
            <td>{{ $receiptData['auth_number'] }}</td>
        </tr>
        <tr>
            <td>Número de auditoría</td>
            <td>{{ $receiptData['audit_number'] }}</td>
        </tr>
        <tr>
            <td colspan="2">(01) Pagado electrónicamente</td>
        </tr>
    </tbody>
</table>
