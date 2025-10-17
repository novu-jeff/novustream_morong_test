@extends('layouts.app')

@section('content')
<main class="main py-5">
    <div class="container text-center">
        @if($status === 'success')
            <h1 class="text-success">Payment Successful!</h1>
            <p>Thank you for your payment. Reference No: <strong>{{ $reference_no }}</strong></p>
            <a href="{{ route('payments.index') }}" class="btn btn-primary mt-3">Go to My Bills</a>
        @elseif($status === 'failed')
            <h1 class="text-danger">Payment Failed</h1>
            <p>Reference No: <strong>{{ $reference_no }}</strong></p>
            <p>{{ $message ?? 'Please try again or contact support.' }}</p>
            <a href="{{ route('payments.index') }}" class="btn btn-outline-primary mt-3">Go Back</a>
        @else
            <h1 class="text-warning">Payment Cancelled or Unknown</h1>
            <p>Reference No: <strong>{{ $reference_no }}</strong></p>
            <a href="{{ route('payments.index') }}" class="btn btn-outline-primary mt-3">Go Back</a>
        @endif
    </div>
</main>
@endsection
