@extends('reports.layout')

@section('content')
<div style="display: flex; gap: 16px; align-items: flex-start;">
  <!-- SISI KIRI: AKTIVA -->
  <div style="flex: 1;">
    <div style="background: #0f172a; color: #f8fafc; padding: 6px 12px; font-weight: 700; font-size: 12px; border-radius: 4px 4px 0 0;">
      I. AKTIVA (HARTA LANCAR & BIAYA-BIAYA)
    </div>
    <table class="data-table" style="margin-top: 0; margin-bottom: 0;">
      <thead>
        <tr>
          <th style="width: 22%;">Kode</th>
          <th style="width: 48%;">Uraian Komponen</th>
          <th style="width: 30%;" class="text-right">Jumlah (Rp)</th>
        </tr>
      </thead>
      <tbody>
        @if(!empty($aktiva['groups']))
          @foreach($aktiva['groups'] as $grp)
            <tr style="background: #e2e8f0; font-weight: 700; color: #0f172a;">
              <td colspan="3">{{ $grp['title'] }}</td>
            </tr>
            @foreach($grp['items'] as $item)
              <tr>
                <td><code>{{ $item['code'] ?? '-' }}</code></td>
                <td>{{ $item['name'] }}</td>
                <td class="text-right">Rp {{ number_format($item['amount'], 0, ',', '.') }}</td>
              </tr>
            @endforeach
            <tr style="background: #f1f5f9; font-style: italic; font-size: 11px;">
              <td colspan="2">Subtotal {{ $grp['title'] }}</td>
              <td class="text-right font-bold">Rp {{ number_format($grp['subtotal'], 0, ',', '.') }}</td>
            </tr>
          @endforeach
        @endif
      </tbody>
      <tfoot>
        <tr class="total-row">
          <td colspan="2" class="font-bold">TOTAL AKTIVA</td>
          <td class="text-right font-bold">Rp {{ number_format($aktiva['total_aktiva'], 0, ',', '.') }}</td>
        </tr>
      </tfoot>
    </table>
  </div>

  <!-- SISI KANAN: PASSIVA -->
  <div style="flex: 1;">
    <div style="background: #0f172a; color: #f8fafc; padding: 6px 12px; font-weight: 700; font-size: 12px; border-radius: 4px 4px 0 0;">
      II. PASSIVA (KEWAJIBAN & SUMBER DANA)
    </div>
    <table class="data-table" style="margin-top: 0; margin-bottom: 0;">
      <thead>
        <tr>
          <th style="width: 22%;">Kode</th>
          <th style="width: 48%;">Uraian Komponen</th>
          <th style="width: 30%;" class="text-right">Jumlah (Rp)</th>
        </tr>
      </thead>
      <tbody>
        @if(!empty($passiva['groups']))
          @foreach($passiva['groups'] as $grp)
            <tr style="background: #e2e8f0; font-weight: 700; color: #0f172a;">
              <td colspan="3">{{ $grp['title'] }}</td>
            </tr>
            @foreach($grp['items'] as $item)
              <tr>
                <td><code>{{ $item['code'] ?? '-' }}</code></td>
                <td>{{ $item['name'] }}</td>
                <td class="text-right">Rp {{ number_format($item['amount'], 0, ',', '.') }}</td>
              </tr>
            @endforeach
            <tr style="background: #f1f5f9; font-style: italic; font-size: 11px;">
              <td colspan="2">Subtotal {{ $grp['title'] }}</td>
              <td class="text-right font-bold">Rp {{ number_format($grp['subtotal'], 0, ',', '.') }}</td>
            </tr>
          @endforeach
        @endif
      </tbody>
      <tfoot>
        <tr class="total-row">
          <td colspan="2" class="font-bold">TOTAL PASSIVA</td>
          <td class="text-right font-bold">Rp {{ number_format($passiva['total_passiva'], 0, ',', '.') }}</td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<div style="margin: 16px 0; padding: 12px 16px; border: 1px solid #cbd5e1; border-radius: 6px; background: {{ $reconciliation['is_balanced'] ? '#f0fdf4' : '#fef2f2' }};">
  <div style="font-weight: 700; font-size: 13px; color: {{ $reconciliation['is_balanced'] ? '#15803d' : '#b91c1c' }};">
    STATUS REKONSILIASI KEUANGAN: {{ $reconciliation['status'] }}
  </div>
  <div style="font-size: 11px; color: #475569; margin-top: 4px;">
    @if($reconciliation['is_balanced'])
      ✓ Seluruh dana kas fisik dan saldo rekening bank telah seimbang (klop) dengan akumulasi saldo alokasi program Latee.
    @else
      ⚠️ Terdapat selisih pembukuan sebesar Rp {{ number_format(abs($reconciliation['selisih']), 0, ',', '.') }}. Harap lakukan verifikasi pada mutasi kas dan buku pembantu pos.
    @endif
  </div>
</div>
@endsection
