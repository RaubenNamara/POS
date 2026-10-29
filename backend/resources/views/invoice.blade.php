<!doctype html>
<html><head><meta charset="utf-8"><title>{{ $r['number'] }}</title>
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
  h1 { font-size: 20px; margin: 0; } .muted { color: #555; }
  table { width: 100%; border-collapse: collapse; margin-top: 12px; }
  th, td { padding: 6px; border-bottom: 1px solid #ddd; text-align: left; } th { background: #f4f4f4; }
  .r { text-align: right; } .tot td { border: 0; padding: 3px 6px; } .big { font-size: 14px; font-weight: bold; }
  .stamp { border: 2px solid #b91c1c; color: #b91c1c; padding: 4px 8px; display: inline-block; font-weight: bold; }
  .paid { border-color: #15803d; color: #15803d; }
</style></head>
<body>
  <table style="margin:0"><tr>
    <td style="border:0; width:60%">
      <h1>{{ $r['shop']['name'] }}</h1>
      <div class="muted">{{ $r['shop']['address'] }}<br>{{ $r['shop']['phone'] }} {{ $r['shop']['email'] }}</div>
    </td>
    <td style="border:0" class="r">
      <div class="big">{{ $r['status'] === 'completed' ? 'INVOICE / RECEIPT' : 'ORDER' }} {{ $r['number'] }}</div>
      <div>{{ $r['date'] }}</div>
      @if($r['cashier'])<div>Served by: {{ $r['cashier'] }}</div>@endif
      <div style="margin-top:6px">
        @if($r['is_final_paid'])<span class="stamp paid">PAID</span>
        @elseif($r['status'] === 'cancelled')<span class="stamp">CANCELLED</span>
        @else<span class="stamp">BALANCE DUE {{ number_format((float) $r['balance_due'], $r['shop']['currency_decimals']) }}</span>@endif
      </div>
    </td>
  </tr></table>
  @if($r['customer'])<p><b>Bill to:</b> {{ $r['customer']['name'] }} {{ $r['customer']['phone'] }}</p>@endif
  <table>
    <thead><tr><th>Item</th><th>SKU</th><th class="r">Qty</th><th class="r">Unit price</th><th class="r">Discount</th><th class="r">Amount</th></tr></thead>
    <tbody>
    @foreach($r['items'] as $i)
      <tr><td>{{ $i['name'] }}</td><td>{{ $i['sku'] }}</td><td class="r">{{ $i['quantity'] }}</td>
        <td class="r">{{ $fmt($i['unit_price']) }}</td><td class="r">{{ (float) $i['discount'] ? $fmt($i['discount']) : '' }}</td><td class="r">{{ $fmt($i['amount']) }}</td></tr>
    @endforeach
    </tbody>
  </table>
  <table class="tot" style="width:45%; margin-left:55%">
    <tr><td>Subtotal</td><td class="r">{{ $fmt($r['subtotal']) }}</td></tr>
    @if((float) $r['discount_total'])<tr><td>Discounts</td><td class="r">-{{ $fmt($r['discount_total']) }}</td></tr>@endif
    @if((float) $r['delivery_fee'])<tr><td>Delivery</td><td class="r">{{ $fmt($r['delivery_fee']) }}</td></tr>@endif
    @if((float) $r['tax_total'])<tr><td>{{ $r['shop']['tax_label'] }}{{ $r['shop']['tax_inclusive'] ? ' (included)' : '' }}</td><td class="r">{{ $fmt($r['tax_total']) }}</td></tr>@endif
    <tr class="big"><td>Total ({{ $r['shop']['currency'] }})</td><td class="r">{{ $fmt($r['total']) }}</td></tr>
    @foreach($r['payments'] as $p)
      <tr><td>{{ $p['kind'] === 'refund' ? 'Refund' : 'Paid' }} - {{ $p['method'] }} {{ $p['reference'] }}</td><td class="r">{{ $p['kind'] === 'refund' ? '-' : '' }}{{ $fmt($p['amount']) }}</td></tr>
    @endforeach
    @if((float) $r['refunded_total'])<tr><td>Returned goods</td><td class="r">-{{ $fmt($r['refunded_total']) }}</td></tr>@endif
    <tr class="big"><td>Balance due</td><td class="r">{{ $fmt($r['balance_due']) }}</td></tr>
  </table>
  <p class="muted" style="margin-top:24px">{{ $r['shop']['return_policy'] }}</p>
  <p><b>{{ $r['shop']['footer'] }}</b></p>
</body></html>
