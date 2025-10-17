@extends('layouts.app')

@section('content')
<div class="text-center p-5">
    <h2>❌ Payment Canceled</h2>
    <p>Reference: {{ $reference }}</p>
    <p>{{ $message }}</p>
    <a href="{{ url('/admin/payments') }}" class="btn btn-warning mt-3">Try Again</a>
</div>
@endsection
