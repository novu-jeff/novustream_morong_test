<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Print Bill {{$reference_no}}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.5/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-SgOJa3DmI69IUzQ2PVdRZhwQ+dy64/BUtbMJw1MZ8t5HZApcHrRKUc4W0kG879m7" crossorigin="anonymous">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.5/dist/js/bootstrap.bundle.min.js" integrity="sha384-k6d4wzSIapyDyv1kpU366/PK5hCdSbCRGRCMv+eplOQJWyd1fbcAu9OCUj5zNLiq" crossorigin="anonymous"></script>
    @vite(['resources/js/app.js'])

  <style>
        /* Global centering for screen preview */
        #invoice-full {
            /* Use flexbox to center content on the screen */
            display: flex;
            flex-direction: column;
            align-items: center; /* Center horizontally */
            /* The .page-wrap will be centered when margin: auto is used in container */
            height: 100vh; /* Ensure body takes full screen height */
            margin: 0;
            padding: 0;
            background-color: #ffffffff; /* Light background for contrast */
        }

        /* Center the page content wrapper */
        .page-wrap {
            display: inline-block;
            margin: 20px auto; /* Add margin for spacing and center the block */
        }

        /* Page size: 8in x 5in */
        .page-8x5 {
            width: 8in;
            height: 5in;
            box-sizing: border-box;
            background: #fff;
            font-family: "Times New Roman", serif;
            color: #000;
            position: relative;
            /* margin: 0 auto; - Removed as .page-wrap handles centering now */
            padding: 0.1in;
            display: flex;
            border: 1px solid black;
        }

        /* print rules */
        @media print {
            html, body {
                height: 100%;
                margin: 0;
                padding: 0;
                /* Remove screen centering for print */
                display: block;
            }
            body * { visibility: hidden !important; }
            .printable, .printable * { visibility: visible !important; }
            /* Position printable content at the top-left for reliable printing */
            .printable { position: absolute; top: 0; left: 0; margin: 0; }
        }

        /* --- Custom Styling for Layout Matching the Image --- */
        .left-panel {
            width: 38%;
            border: 1px solid #000;
            margin-right: 0.1in;
            height: 100%;
            box-sizing: border-box;
            font-size: 10px;
            line-height: 1.1;
            padding: 2px 0;
            display: flex;
            flex-direction: column;
        }

        .right-panel {
            width: 62%;
            height: 100%;
            box-sizing: border-box;
            padding: 0;
            font-size: 10px;
        }

        /* Left Table Styling */
        .left-table {
            border-collapse: collapse;
            width: 100%;
            flex-grow: 1;
            margin-top: 2px;
        }
        .left-table th, .left-table td {
            padding: 1px 4px;
            vertical-align: top;
            height: calc((3.2in - 1px) / 9);
            border-right: 1px solid #000;
            box-sizing: border-box;
        }
        .left-table thead th {
            border-bottom: 1px solid #000;
            height: 0.3in;
        }
        .left-table td {
            border-bottom: 1px solid #000;
        }
        /* Remove outer borders */
        .left-table th:first-child, .left-table td:first-child { border-left: none; }
        .left-table th:last-child, .left-table td:last-child { border-right: none; }
        .left-table tbody tr:last-child td { border-bottom: none; }

        /* Left Panel Bottom Sections */
        .total-sales-box {
            border-top: 1px solid #000;
            padding: 2px 4px;
        }
        .form-of-payment-box {
            border-top: 1px solid #000;
            padding: 4px 4px 0 4px;
            margin-top: 4px;
        }
        .payment-option {
            display: flex;
            margin-top: 2px;
            font-size: 9px;
        }
        .check-no {
            margin-top: 4px;
            font-size: 9px;
        }

        /* Right Panel Specifics */
        .wd-header-container {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 2px;
        }
        .wd-logo {
            width: 0.4in;
            height: auto;
            margin-right: 4px;
        }
        .wd-text {
            flex-grow: 1;
            text-align: right;
            line-height: 1;
        }
        .wd-text div {
            font-size: 10px;
            font-weight: 700;
        }
        .wd-text .tel-info {
            font-size: 8px;
            font-weight: 400;
        }
        .invoice-title { font-size: 14px; font-weight: 700; letter-spacing: 0.5px; text-align: left; margin-top: 4px; }
        .data-label { width: 25%; font-size: 10px; }
        .data-value {
            border-bottom: 1px solid #000;
            padding: 0 4px;
            font-weight: 500;
            font-size: 11px;
            line-height: 1.1;
        }
        .npo-details {
            font-size: 8px;
            line-height: 1.1;
            padding-top: 4px;
            margin-top: 4px;
        }
        .sig-line-data {
            font-weight: bold;
            font-size: 11px;
            border-bottom: 1px solid #000;
            padding: 0 4px;
            line-height: 1.1;
        }

    </style>
</head>

<body>
@extends('layouts.app')
@section('content')

@php
    // --- Normalize and compute values ---
    $cb = $data['current_bill'] ?? [];
    $amount = (float) ($cb['amount'] ?? 0);

    $arrears = $cb['arrears'] ?? 0;
    if (is_array($arrears)) {
        $arrears = collect($arrears)->sum();
    } elseif (is_string($arrears)) {
        $arrears = (float) $arrears;
    }

    $discount = $cb['discount'] ?? 0;
    if (is_array($discount)) {
        if (isset($discount[0]) && is_array($discount[0]) && isset($discount[0]['amount'])) {
            $discount = collect($discount)->sum('amount');
        } else {
            $discount = collect($discount)->sum();
        }
    } elseif (is_string($discount)) {
        $discount = (float) $discount;
    }

    $assumed_penalty = (float) ($cb['assumed_penalty'] ?? 0);
    $total = round($amount + $arrears + $assumed_penalty - $discount, 2);

    $pesos = intval(floor($total));
    $centavos = intval(round(($total - $pesos) * 100));
    $fmt = new \NumberFormatter("en", \NumberFormatter::SPELLOUT);
    $pesos_words = ucfirst($fmt->format($pesos));
    $amount_in_words = "{$pesos_words} Pesos & " . str_pad($centavos, 2, '0', STR_PAD_LEFT) . "/100";

    $receipt_no = $receipt_no ?? ('436' . str_pad(rand(0,9999), 4, '0', STR_PAD_LEFT));
    $cashier = auth()->user()->name ?? ($cb['collecting_officer'] ?? 'NA');
    $bill_month = !empty($cb['bill_period_from']) ? \Carbon\Carbon::parse($cb['bill_period_from'])->format('M Y') : '';
    $account_no = $cb['account_no'] ?? $cb['account_number'] ?? '011-12-011110';
    $datePaid = $cb['date_paid'] ?? \Carbon\Carbon::now()->format('Y-m-d');

    // prepare preview background (if user placed scanned image in public/images/receipt-scan.png)
    $previewBg = null;
    $previewPath = public_path('images/receipt-scan.png');
    if (file_exists($previewPath)) {
        $dataUri = base64_encode(file_get_contents($previewPath));
        $mime = mime_content_type($previewPath) ?: 'image/png';
        $previewBg = "data:{$mime};base64,{$dataUri}";
    }
@endphp

<div class="print-controls" style="display: grid; grid-template-columns: repeat(2, auto); gap: 12px; margin: 50px auto; width: fit-content;">
        @php
            $previousUrl = url()->previous();
            $currentUrl = url()->current();
            $fallbackUrl = Auth::user()->user_type == 'client' ? route('account-overview.show') : route('reading.index');
            $backUrl = ($previousUrl !== $currentUrl) ? $previousUrl : $fallbackUrl;
            $logoPath = public_path('images/client.png');
            $base64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
        @endphp

        <a href="{{$backUrl}}"
            id="goBackButton"
            style="border: 1px solid #32667e; padding: 12px 40px; text-align:center; text-transform: uppercase; display: flex; align-items: center; gap: 8px; text-decoration: none; color: #32667e; background-color: transparent; border-radius: 5px;">
            <i style="font-size: 15px;" class='bx bx-left-arrow-alt'></i> Go Back
        </a>

        @if(!$isReRead['status'])
            <button
                class="download-js"
                data-target="#bill"
                data-filename="{{$data['current_bill']['reference_no']}}"
                style="background-color: #32667e; color: white; padding: 12px 40px; text-align:center; text-transform: uppercase; display: flex; align-items: center; gap: 8px; border: none; border-radius: 5px; cursor: pointer;">
                <i style="font-size: 15px;" class='bx bxs-download'></i> Download
            </button>
            @if(!$data['current_bill']['isPaid'] == true)
                <button
                    class="reRead"
                    style="text-align: center; background-color: #32667e; color: white; padding: 12px 40px; text-align:center; text-transform: uppercase; display: flex; align-items: center; gap: 8px; border: none; border-radius: 5px; cursor: pointer;">
                    <i style="font-size: 15px;" class='bx bxs-printer'></i> Re Read
                </button>
            @endif
        @endif
    </div>
<div class="container controls">
  <div class="d-flex justify-content-center gap-3">
    <button class="btn btn-primary print-btn" data-target="#receipt-full">🧾 Print Full Receipt</button>
    <button class="btn btn-secondary print-btn" data-target="#receipt-overlay">🖋 Print Overlay Only</button>
  </div>
  <div class="mt-2 text-muted text-center small-note">Printer: set to Actual Size / 100% and No margins</div>
</div>

{{-- ===================== FULL invoice (HTML styled to look like the scan) ===================== --}}
<div id="invoice-full" class="printable page-wrap">
    <div class="page-8x5">

        {{-- LEFT PANEL: Volume, Unit Price, Amount Table --}}
        <div class="left-panel">
            <div style="text-align: left; padding: 0 4px 4px 4px; font-size: 9px;">In settlement of the following</div>

            <table class="left-table">
                <thead>
                    <tr>
                        <th style="width:33%;">Volume ($m^3$)</th>
                        <th style="width:33%;">Unit Price</th>
                        <th style="width:34%;">AMOUNT</th>
                    </tr>
                </thead>
                <tbody>
                    {{-- Act Paid Row --}}
                    <tr>
                        <td style="line-height: 2px; font-size: 11px;">Act Paid</td>
                        <td></td>
                        <td style="line-height: 2px; font-size: 11px; font-weight: bold;">
                            P {{ number_format($amount, 2) }}
                        </td>
                    </tr>
                    {{-- 8 Blank Rows (Total of 9 data rows) --}}
                    @for ($i = 0; $i < 8; $i++)
                        <tr>
                            <td></td>
                            <td></td>
                            <td></td>
                        </tr>
                    @endfor
                </tbody>
            </table>

            <div class="total-sales-box">
                <div style="font-size: 9px;">Total Sales</div>
            </div>

            <div class="form-of-payment-box">
                <div style="font-size: 9px; font-weight: bold;">FORM OF PAYMENT</div>
                <div class="payment-option">
                    <div style="width: 50%;">CASH ( )</div>
                    <div style="width: 50%;">BANK ( )</div>
                </div>
                <div class="check-no">CHECK NO. ________________</div>
            </div>
        </div>

        {{-- RIGHT PANEL: Invoice Details, Amounts, and NPO --}}
        <div class="right-panel">

            {{-- Header/Title/Date Block --}}
            <div class="wd-header-container" style="padding-top: 0.1in;">
                {{-- Placeholder for Logo (adjust positioning as needed) --}}
                {{-- If you have the actual logo path, replace 'visibility:hidden' --}}
                <img src="{{ asset('images/client.png') }}"
                alt="logo"
                class="web-logo"
                style="width: 20%; height: auto; ">

                <div class="wd-text" style="text-align: left; margin-left: 20px;">
                    <div style="font-size: 20px;">MORONG WATER DISTRICT</div>
                    <div style="font-size: 9px; font-weight: 400; line-height: 1;">275-087-677-00000</div>
                    <div class="tel-info">Zamora St., Poblacion 2108, Morong, Bataan, Philippines</div>
                </div>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 4px;">
                <div class="invoice-title">SERVICE INVOICE</div>
                <div style="font-size: 9px; text-align: right; line-height: 1;">
                    Date
                    <div class="data-value" style="width: 1.5in; display: inline-block; font-size: 10px; font-weight: bold; margin-bottom: -1px; text-align: center;">
                        {{ \Carbon\Carbon::parse($datePaid)->format('F d, Y') }}
                    </div>
                </div>
            </div>

            {{-- Recipient Details --}}
            <div style="margin-top: 4px;">
                <div style="display: flex; align-items: flex-end; margin-bottom: 2px;">
                    <div class="data-label">Received from</div>
                    <div class="data-value flex-grow-1 text-uppercase" style="font-size: 11px;">{{ $client['name'] ?? null}}</div>
                    <div style="width: 10%; text-align: right; font-size: 10px;">TIN</div>
                    <div class="data-value" style="width: 30%; font-size: 10px;">{{ $client['tin'] ?? null}}</div>
                </div>
                <div style="display: flex; align-items: flex-end; margin-bottom: 2px;">
                    <div class="data-label">Address</div>
                    <div class="data-value flex-grow-1" style="font-size: 10px;">{{ $client['address'] ?? null}}</div>
                </div>
            </div>

            {{-- Amount in Words and Figure --}}
            <div style="margin-top: 4px;">
                <div style="font-size: 10px;">of</div>
                <div style="display: flex; align-items: center; margin-top: 1px;">
                    <div class="data-value" style="width: 70%; font-style: italic; font-size: 10px; font-weight: normal; border: 1px solid #000; height: 0.3in; line-height: 0.3in;">
                        {{ $words ?? null}}
                    </div>
                    <div style="width: 30%; font-size: 10px; text-align: right; padding-left: 4px;">
                        <span style="font-size: 12px; font-weight: bold; line-height: 1;">₱ {{ number_format($total, 2) }}</span>
                    </div>
                </div>
                <div style="display: flex; justify-content: flex-end; font-size: 8px; margin-top: 2px;">
                    <span style="padding-right: 18px;">pesos ($P$)</span>
                    <span style="font-weight: bold;">Water Bill - </span>
                </div>
                <div style="font-size: 10px; margin-top: 6px;">as full/partial payment for ___________________________________</div>
            </div>

            {{-- Cashier / Authorized Representative --}}
            <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 14px;">
                <div style="width: 30%; font-size: 9px;">NATIONAL PRINTING OFFICE</div>
                <div style="width: 40%; font-size: 10px;">By:</div>
                <div style="width: 30%; text-align: center;">
                    <span class="sig-line-data" style="border-bottom: 1px solid #000;">{{ strtoupper($cashier) }}</span>
                    <div style="font-size: 8px;">Cashier / Authorized Representative</div>
                </div>
            </div>

            {{-- NPO / BIR / Ref Number Block (Bottom Left of Right Panel) --}}
            <div class="npo-details">
                TIN: 000-769-754-0000
                <br>
                EDSA cor. NIA, North Side Road, Pinyahan 1111, Quezon City, NCR, 2nd District, Philippines
                <br>
                Printer's Accreditation No. 039MP20240000000002 Issued 01/25/2024
                <br>
                BIR Authority to Print No. OCN20AUI20250000004387
                <br>
                300 bkls (50x 3) 055001 - 070000
                <br>
                Date Issued: July 14, 2025
                <br>
                **"This document is not valid for claiming input taxes"**
            </div>

            {{-- Main Receipt Number Stamp (Bottom Right) --}}
            <div style="position: absolute; bottom: 0.1in; right: 0.4in; font-size: 28px; font-weight: 700; line-height: 1.1;">
                <span style="font-size: 14px; font-weight: 400;">No</span> 062523
            </div>

        </div>

    </div>
</div>

<!-- =========================
     Option 2 - OVERLAY ONLY
     (absolute positioned fields to print on pre-printed form)
     ========================= -->
<div id="receipt-overlay" class="printable receipt-sheet overlay-preview " >
    <!-- style="display:none;
     @if($previewBg) background-image: url('{{ $previewBg }}'); @endif" -->

  {{-- NOTE: positions are in cm and intended to match the full receipt above. If you need to calibrate, tweak the top/left/right values by +/- 0.05cm. --}}

  {{-- Date (below OR no.) --}}
  <div style="position:absolute; top:11cm; right:10cm; font-size:9px;">
    {{ \Carbon\Carbon::parse($datePaid)->format('F d, Y') }}
  </div>

  {{-- Agency --}}
  <div style="position:absolute; top:3.9cm; left:1.2cm; font-size:11px;">
    SANTA RITA WATER DISTRICT
  </div>

  {{-- Payor --}}
  <div style="position:absolute; top:4.4cm; left:1.2cm; right:1.2cm; font-size:11px; text-transform:uppercase;">
    {{ $data['client']['name'] ?? 'N/A' }} {{ !empty($data['client']['account_no']) ? ' | '.$data['client']['account_no'] : '' }}
  </div>

  {{-- Account No --}}
  <div style="position:absolute; top:4.9cm; left:1.2cm; font-size:11px;">
    {{ $account_no }}
  </div>

  {{-- Table: WB (Nature) main --}}
  <div style="position:absolute; top:7.0cm; left:0.7cm; font-size:10px;">
    WB {{ $bill_month }}
  </div>
  <div style="position:absolute; top:7.0cm; right:0.8cm; width:3.0cm; font-size:10px; text-align:right;">
    ₱ {{ number_format($amount,2) }}
  </div>

  {{-- Arrears --}}
  @if($arrears > 0)
    <div style="position:absolute; top:7.6cm; left:0.7cm; font-size:10px;">Arrears</div>
    <div style="position:absolute; top:7.6cm; right:0.8cm; width:3.0cm; font-size:10px; text-align:right;">₱ {{ number_format($arrears,2) }}</div>
  @endif

  {{-- Penalty --}}
  @if($assumed_penalty > 0)
    <div style="position:absolute; top:8.2cm; left:0.7cm; font-size:10px;">Penalty</div>
    <div style="position:absolute; top:8.2cm; right:0.8cm; width:3.0cm; font-size:10px; text-align:right;">₱ {{ number_format($assumed_penalty,2) }}</div>
  @endif

  {{-- Discount --}}
  <div style="position:absolute; top:10.1cm; left:0.7cm; font-size:10px;">Less: Senior Discount</div>
  <div style="position:absolute; top:10.1cm; right:0.8cm; width:3.0cm; font-size:10px; text-align:right;">₱ {{ number_format($discount,2) }}</div>

  {{-- Total --}}
  <div style="position:absolute; top:10.6cm; left:0.7cm; font-size:11px; font-weight:700;">TOTAL</div>
  <div style="position:absolute; top:10.6cm; right:0.8cm; width:3.0cm; font-size:11px; font-weight:700; text-align:right;">₱ {{ number_format($total,2) }}</div>

  {{-- Amount in words --}}
  <div style="position:absolute; top:12.5cm; left:0.9cm; right:0.9cm; font-size:10px; text-align:center; font-style:italic;">
    {{ $amount_in_words }}
  </div>

  {{-- Collecting Officer signature --}}
  <div style="position:absolute; bottom:3.5cm; right:8.4cm; text-align:center; width:5.0cm; font-size:10px;">
    <div>{{ strtoupper($cashier) }}</div>
  </div>

</div> <!-- #receipt-overlay -->

<script>
  $(function () {
    // show full receipt in the browser by default for preview
    $('#receipt-full').show();

    // Print button logic toggles which printable to show and triggers print
    $(document).on('click', '.print-btn', function (e) {
      e.preventDefault();
      const target = $(this).data('target'); // "#receipt-full" or "#receipt-overlay"
      $('#receipt-full, #receipt-overlay').hide();
      $(target).show();
      // slight delay for rendering changes to take effect before print dialog
      setTimeout(() => window.print(), 180);
    });
  });
        $(function () {
            @if (session('alert'))
                setTimeout(() => {
                    let alertData = @json(session('alert'));
                    alert(alertData.status, alertData.message);
                }, 100);
            @endif

            const reference_no = '{{$reference_no}}';

            if (window.opener && window.opener !== window) {
                $('#goBackButton').on('click', function (e) {
                    e.preventDefault();
                    window.close();
                });
            }

            let selectedBillId = null;

            $(document).on('click', '.reRead', function() {
                selectedBillId = $(this).data('bill-id');
                $('#reReadModal').modal('show');
            });

            $('#confirmReRead').on('click', function() {
                $('#reReadModal').modal('hide');
                const redirect = `{{ route('reading.index') }}?re-read=true&reference_no=${encodeURIComponent(reference_no)}`;
                console.log(redirect);
                setTimeout(() => {
                    window.location.href = redirect;
                }, 1000);
            });
        });
</script>

<script>
        $(function () {
            @if (session('alert'))
                setTimeout(() => {
                    let alertData = @json(session('alert'));
                    alert(alertData.status, alertData.message);
                }, 100);
            @endif

            const reference_no = '{{$reference_no}}';

            if (window.opener && window.opener !== window) {
                $('#goBackButton').on('click', function (e) {
                    e.preventDefault();
                    window.close();
                });
            }

            let selectedBillId = null;

            $(document).on('click', '.reRead', function() {
                selectedBillId = $(this).data('bill-id');
                $('#reReadModal').modal('show');
            });

            $('#confirmReRead').on('click', function() {
                $('#reReadModal').modal('hide');
                const redirect = `{{ route('reading.index') }}?re-read=true&reference_no=${encodeURIComponent(reference_no)}`;
                console.log(redirect);
                setTimeout(() => {
                    window.location.href = redirect;
                }, 1000);
            });
        });
    </script>

@endsection
</body>
</html>
