@extends('layouts.app')

@section('content')
<div class="text-center p-5">
    <h2>✅ Payment Successful</h2>
    <p>Reference: {{ $reference }}</p>
    <p>{{ $message }}</p>
    <a href="{{ url('/') }}" class="btn btn-primary mt-3">Return to Home</a>
</div>
@endsection
