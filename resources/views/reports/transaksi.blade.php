@extends('reports.layout')

@section('content')
<table class="data-table">
  <thead>
    <tr>
      <th style="width: 30px;">No</th>
      <th style="width: 75px;">Tanggal</th>
      <th style="width: 80px;">No. Reff</th>
      <th style="width: 140px;">Pihak / Donatur</th>
      <th>Pos Program</th>
      <th style="width: 85px;">Metode</th>
      <th style="width: 95px;">Masuk (Rp)</th>
      <th style="width: 95px;">Keluar (Rp)</th>
      <th style="width: 120px;">Keterangan</th>
    </tr>
  </thead>
  <tbody>
    @php
      $totIn = 0;
      $totOut = 0;
    @endphp
    @forelse($transactions as $idx => $t)
      @php
        $isInc = ($t->type === 'income');
        if ($isInc) { $totIn += $t->amount; } else { $totOut += $t->amount; }
      @endphp
      <tr>
        <td class="text-center">{{ $idx + 1 }}</td>
        <td class="text-center">{{ $t->transaction_date ? $t->transaction_date->format('d/m/Y') : '-' }}</td>
        <td class="text-center" style="font-family: monospace; font-size: 10px;">{{ $t->qris_reff_id ? substr($t->qris_reff_id, -8) : "TX-{$t->id}" }}</td>
        <td>{{ $t->donor?->name ?? ($t->donor_name_raw ?: 'Hamba Allah') }}</td>
        <td>{{ $t->category?->name ?? '-' }}</td>
        <td class="text-center">{{ $t->account?->name ?? '-' }}</td>
        <td class="text-right" style="color: #15803d;">{{ $isInc ? 'Rp ' . number_format($t->amount, 0, ',', '.') : '-' }}</td>
        <td class="text-right" style="color: #b91c1c;">{{ !$isInc ? 'Rp ' . number_format($t->amount, 0, ',', '.') : '-' }}</td>
        <td>{{ $t->description ?: '-' }}</td>
      </tr>
    @empty
      <tr>
        <td colspan="9" class="text-center" style="padding: 24px; color: #94a3b8;">Tidak ada data transaksi kas yang sesuai kriteria.</td>
      </tr>
    @endforelse
  </tbody>
  <tfoot>
    <tr class="total-row">
      <td colspan="6" class="text-center font-bold">TOTAL MUTASI</td>
      <td class="text-right font-bold" style="color: #15803d;">Rp {{ number_format($totIn, 0, ',', '.') }}</td>
      <td class="text-right font-bold" style="color: #b91c1c;">Rp {{ number_format($totOut, 0, ',', '.') }}</td>
      <td class="text-right font-bold">Surplus: Rp {{ number_format($totIn - $totOut, 0, ',', '.') }}</td>
    </tr>
  </tfoot>
</table>
@endsection
