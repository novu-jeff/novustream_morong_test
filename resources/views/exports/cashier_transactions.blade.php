<table>
    <thead>
        <tr>
            <th>Reference No</th>
            <th>Account No</th>
            <th>Zone</th>
            <th>Bill Period</th>
            <th>Amount Paid</th>
            <th>Payment Date</th>
        </tr>
    </thead>
    <tbody>
        @foreach($bills as $bill)
            <tr>
                <td>{{ $bill->reference_no }}</td>
                <td>{{ $bill->reading->account_no ?? 'N/A' }}</td>
                <td>{{ $bill->reading->zone ?? 'N/A' }}</td>
                <td>
                    @if($bill->bill_period_from && $bill->bill_period_to)
                        {{ \Carbon\Carbon::parse($bill->bill_period_from)->format('M d, Y') }}
                        TO
                        {{ \Carbon\Carbon::parse($bill->bill_period_to)->format('M d, Y') }}
                    @else
                        N/A
                    @endif
                </td>
                <td>{{ number_format($bill->amount_paid, 2) }}</td>
                <td>{{ \Carbon\Carbon::parse($bill->created_at)->format('M d, Y') }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
