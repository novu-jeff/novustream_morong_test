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
    /* Page fixed size for 4" x 8.5" (10.16cm x 21.59cm) */
    .receipt-sheet {
      width: 10.16cm;
      height: 21.59cm;
      box-sizing: border-box;
      background: white;
      font-family: "Times New Roman", serif;
      color: #000;
      position: relative;
      margin: 0 auto;
    }

    /* Outer thin page border when viewing (optional) */
    .page-wrap { padding: 6px; }

    /* print rules: show only the visible printable block */
    @media print {
      html, body { height: 100%; margin: 0; padding: 0; }
      body * { visibility: hidden !important; }
      .printable, .printable * { visibility: visible !important; }
      .printable { position: absolute; top: 0; left: 0; margin: 0; }
    }

    /* On-screen */
    .controls { margin: 20px auto; text-align: center; }
    .small-note { font-size: 8px; color: #333; }

    /* Header container */
    .or-header {
      border: 1px solid #000;      /* single solid border as requested */
      padding: 6px;
      box-sizing: border-box;
      display: flex;
      gap: 8px;
      align-items: flex-start;
      height: 3.5cm;               /* fixed header height */
    }

    .or-logo {
      width: 18%;
      min-width: 1.7cm;
      text-align: center;
    }
    .or-logo .crest-box {
      width: 100px;
      height: 120px;
      border: 1px solid #000;
      background: #f8f8f8;
      margin: 0 auto;
      padding-top: 6px;
      box-sizing: border-box;
    }
    .or-center {
      width: 44%;
      text-align: center;
      font-weight: 700;
      font-size: 12px;
      line-height: 1.1;
      display:flex;
      align-items:center;
      justify-content:center;
      flex-direction:column;
      gap:2px;
    }
    .or-right {
      width: 38%;
      text-align: right;
      box-sizing:border-box;
    }
    .or-right .or-no {
      display:inline-block;
      border:1px solid #000;
      padding:6px 8px;
      font-weight:700;
    }

    /* small helper text */
    .muted-small { font-size: 8px; color: #666; }

    /* Section rows */
    .row-label {
      width: 22%;
      font-weight:700;
      font-size:10px;
    }
    .underline-field {
      border-bottom: 1px solid #000;
      padding: 6px 8px;
      font-size:11px;
    }

    /* table style */
    .or-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 10px;
      margin-top: 6px;
    }
    .or-table thead th {
      border:1px solid #000;
      padding:6px;
      vertical-align: middle;
    }
    .or-table tbody td {
      border-left:1px solid #000;
      border-right:1px solid #000;
      padding:6px;
      vertical-align: middle;
    }
    .or-table tbody tr:last-child td { border-bottom:1px solid #000; }
    /* make right-most column show right-aligned numbers */
    .amount-col { text-align: right; }

    /* Amount in words box */
    .amount-words {
      border:1px solid #000;
      padding:8px;
      font-style: italic;
      text-align:center;
      margin-top:8px;
      font-size:10px;
      min-height: 2.0cm;
      box-sizing: border-box;
    }

    /* Payment & drawee row */
    .payment-row { margin-top:8px; font-size:10px; }
    .drawee-box { border-bottom: 1px solid #000; height:18px; }

    /* signature area */
    .signature-area { margin-top:18px; display:flex; justify-content:flex-end; }
    .sig-box { width:60%; text-align:center; }

    /* overlay preview background (on-screen only) */
    .overlay-preview {
      background-repeat: no-repeat;
      background-position: center top;
      background-size: contain;
      opacity: 0.92;
    }
    @media print {
      .overlay-preview { background: transparent !important; opacity: 1 !important; }
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
  <div class="mt-2 text-muted small-note">Printer: set to Actual Size / 100% and No margins</div>
</div>

<!-- =========================
     Option 1 - FULL RECEIPT (exact layout)
     ========================= -->
<div id="receipt-full" class="printable page-wrap" style="display:none;">
  <main id="bill" class="d-flex justify-content-center p-2">
  <div class="border border-dark bg-white text-dark p-2" style="width: 10.16cm; height: 21.59cm; font-family: 'Times New Roman', serif; font-size: 11px; position: relative; box-sizing: border-box;">
    {{-- Form Header --}}
    <div class="d-flex justify-content-between mb-1">
      <div>
        <div class="small text-muted" style="font-size: 8px;">ACCOUNTABLE FORM No. 51-C</div>
        <div class="small text-muted" style="font-size: 8px;">Revised January, 1992</div>
      </div>
      <div class="fw-bold" style="font-size: 9px;">(ORIGINAL)</div>
    </div>

    {{-- Main Frame --}}
    <div class="border border-dark" style="height: calc(100% - 28px); box-sizing: border-box;">

      {{-- Top Row --}}
      <div class="d-flex align-items-start mb-2">
        <div class="text-center" style="width: 100%; height: 12rem; display: flex; border: 2px solid black;">
          <div class="border border-dark" style="width: 40%; height: auto; background: #f8f8f8; display: flex; flex-direction: column; align-items: center; padding: 10px;">
            <img src="{{ asset('images/rnp.png') }}"
                alt="logo"
                class="web-logo"
                style="width: 60%; height: auto; margin-bottom: 10px; padding-top: 25px;">
            <div style="margin-top: auto; text-align: center; width: 100%;">
                {{ \Carbon\Carbon::now()->format('H:i:s') }}
            </div>
          </div>
          <div style="display: flex; flex-direction: column; width: 60%;">
            <div class="text-center" style="width: 100%; height: 40%; border: 1px solid black;">
                <div class="fw-bold" style="font-size: 17px; display: flex; justify-content: center; align-items: center;">
                    Official Receipt <br> of the <br> Republic of the Philippines
                </div>
                </div>
                    <div class="fw-bold border border-dark px-2 py-1" style="width: 100%; height: 40%; font-size: 30px; display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-weight: bold;">No.</span>
                        <span style="font-weight: normal; letter-spacing: 1px;">4364322</span>
                        <span class="suffix">T</span>
                    </div>
                    <div class="border-top border-dark text-start" style="padding-left: 2px; font-size: 15px; width: 100%; height: 20%; border: 1px solid black;">
                        Date: <span>{{ \Carbon\Carbon::parse($datePaid)->format('F d, Y') }}</span>
                    </div>
            </div>
        </div>
        </div>

        {{-- Agency and Payor --}}
        <div class="d-flex gap-2 align-items-center mt-1">
            <div class="fw-bold" style="width: 10%; font-size: 10px;">Agency</div>
            <div class="flex-grow-1 border-bottom border-dark px-2" style="font-size: 11px;">SANTA RITA WATER DISTRICT</div>
        </div>
        <div class="d-flex gap-2 align-items-center mt-1">
            <div class="fw-bold" style="width: 10%; font-size: 10px;">Payor</div>
            <div class="flex-grow-1 border-bottom border-dark px-2 text-uppercase" style="font-size: 11px;">{{$data['client']['name']}} | {{$data['client']['account_no'] ?? ''}}</div>
        </div>

      {{-- Table --}}
      <table class="table table-bordered border-dark mt-2 mb-0" style="font-size: 5px;">
        <thead>
          <tr style="line-height: 10px;">
            <th style="width: 55%;">Nature of<br>Collection</th>
            <th style="width: 20%;" class="text-center">Account<br>Code</th>
            <th style="width: 25%;" class="text-end">Amount</th>
          </tr>
        </thead>
        <tbody>
          {{-- Always show current bill --}}
          <tr style="line-height: 2px;">
            <td>WB {{ $bill_month }}</td>
            <td></td>
            <td class="text-end">₱ {{ number_format($amount, 2) }}</td>
          </tr>

          {{-- Conditionally show Arrears and Penalty --}}
          @if($arrears > 0)
            <tr style="line-height: 2px;">
              <td>Arrears</td>
              <td></td>
              <td class="text-end">₱ {{ number_format($arrears, 2) }}</td>
            </tr>
          @endif

          @if($assumed_penalty > 0)
            <tr style="line-height: 2px;">
              <td>Penalty</td>
              <td></td>
              <td class="text-end">₱ {{ number_format($assumed_penalty, 2) }}</td>
            </tr>
          @endif

          {{-- Blank rows --}}
          @php
            $filled = 1 + ($arrears > 0 ? 1 : 0) + ($assumed_penalty > 0 ? 1 : 0);
            $blank = 6 - $filled;
          @endphp
          @for($i = 0; $i < $blank; $i++)
            <tr style="line-height: 2px;">
              <td>&nbsp;</td>
              <td></td>
              <td></td>
            </tr>
          @endfor

          {{-- Discount and Total --}}
          <tr style="line-height: 2px;">
            <td style="font-size: 10px;">Less: Senior Discount</td>
            <td></td>
            <td class="text-end">₱ {{ number_format($discount, 2) }}</td>
          </tr>
          <tr class="fw-bold" style="line-height: 2px;">
            <td>TOTAL</td>
            <td></td>
            <td class="text-end">₱ {{ number_format($total, 2) }}</td>
          </tr>
        </tbody>
      </table>

      {{-- Amount in words --}}
      <div class="mt-2 border-top border-dark pt-1">
        <div class="fw-bold" style="font-size: 13px;">Amount in Words</div>
            <div class="border border-dark p-1 fst-italic text-center" style="font-size: 10px; min-height: 20px;">
                {{ App\Helper\NumberHelper::convertToWords(2986.00) }}
            </div>
      </div>

      {{-- Payment methods --}}
    <div class="border-bottom border-dark" style="font-size: 10px; padding-bottom: 10px;">
        <div class="d-flex">
            <!-- Left column: checkboxes -->
            <div class="d-flex flex-column justify-content-between" style="gap: 6px;">
            <label class="form-check-label d-flex align-items-center gap-1">
                <input type="checkbox"> Cash
            </label>
            <label class="form-check-label d-flex align-items-center gap-1">
                <input type="checkbox"> Check
            </label>
            <label class="form-check-label d-flex align-items-center gap-1">
                <input type="checkbox"> Money Order
            </label>
            </div>

            <!-- Right column: Drawee Bank / Number / Date -->
            <div class="flex-fill ms-2">
                <table class="table m-0 text-center border-0" style="table-layout: fixed;">
                    <thead>
                        <tr class="border border-dark">
                            <th class="border-end border-dark p-1">Drawee Bank</th>
                            <th class="border-end border-dark p-1">Number</th>
                            <th class="p-1">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Full row -->
                        <tr style="height: 22px;">
                            <td class="border border-dark"></td>
                            <td class="border-end border-dark"></td>
                            <td></td>
                        </tr>

                        <!-- Row with only Number and Date -->
                        <tr style="height: 22px;">
                            <td style="border: none; background: transparent;"></td>
                            <td class="border border-dark"></td> <!-- Number -->
                            <td></td> <!-- Date -->
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
      <div class="mt-2" style="font-size: 10px;">Received the amount stated above.</div>

      {{-- Signature --}}
      <div class="d-flex justify-content-end align-items-center mt-2">
        <div class="text-center" style="width: 60%;">
          <div class="border-bottom border-dark pt-1 fw-bold">{{ strtoupper($cashier) }}</div>
          <div style="font-size: 9px;">Collecting Officer</div>
        </div>
      </div>

      <div class="border-top border-dark p-1 mt-2 text-center" style="font-size: 11px;">
        NOTE: Write the number and date of this receipt on the back of check or money order received.
      </div>
    </div>
  </div>
</main>

</div> <!-- #receipt-full -->

<!-- =========================
     Option 2 - OVERLAY ONLY
     (absolute positioned fields to print on pre-printed form)
     ========================= -->
<div id="receipt-overlay" class="printable receipt-sheet overlay-preview" style="display:none;
     @if($previewBg) background-image: url('{{ $previewBg }}'); @endif">

  {{-- NOTE: positions are in cm and intended to match the full receipt above. If you need to calibrate, tweak the top/left/right values by +/- 0.05cm. --}}

  {{-- OR Number (top-right) --}}
  <div style="position:absolute; top:0.9cm; right:0.7cm; font-weight:700; font-size:13px;">
    {{ $receipt_no }}
  </div>

  {{-- Date (below OR no.) --}}
  <div style="position:absolute; top:1.6cm; right:0.7cm; font-size:9px;">
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
  <div style="position:absolute; bottom:2.0cm; right:1.6cm; text-align:center; width:5.0cm; font-size:10px;">
    <div style="border-top:1px solid #000; font-weight:700;">{{ strtoupper($cashier) }}</div>
    <div style="font-size:9px;">Collecting Officer</div>
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
