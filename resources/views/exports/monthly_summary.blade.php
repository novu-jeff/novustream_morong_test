<table>
    <thead>
        <tr>
            <th>Rate Classification</th>
            <th>Zone</th>
            <th>No. Of Billed</th>
            <th>Cons.</th>
            <th>Basic Amount</th>
            <th>SC Discount</th>
            <th>Net Amount Billed</th>
        </tr>
    </thead>
    <tbody>
        @foreach($summary as $classification => $zones)
            @foreach($zones as $zoneData)
                <tr>
                    <td>{{ $classification }}</td>
                    <td>{{ $zoneData['zone'] }}</td>
                    <td>{{ $zoneData['no_of_billed'] }}</td>
                    <td>{{ $zoneData['cons'] }}</td>
                    <td>{{ number_format($zoneData['basic_amount'], 2) }}</td>
                    <td>{{ number_format($zoneData['sc_discount'], 2) }}</td>
                    <td>{{ number_format($zoneData['net_amount_billed'], 2) }}</td>
                </tr>
            @endforeach
        @endforeach
    </tbody>
</table>
