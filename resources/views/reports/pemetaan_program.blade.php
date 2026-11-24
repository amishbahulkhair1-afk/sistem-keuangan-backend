@extends('reports.layout')

@section('content')
<table class="data-table">
  <thead>
    <tr>
      <th style="width: 35px;">No</th>
      <th style="width: 75px;">Kode</th>
      <th>Nama Pos Program</th>
      <th style="width: 140px;">Koordinator / PIC</th>
      <th style="width: 75px;">Digit QRIS</th>
      <th style="width: 90px;">Jumlah Tx</th>
      <th style="width: 140px;">Total Terhimpun (Rp)</th>
    </tr>
  </thead>
  <tbody>
    @php
      $totNominal = 0;
      $totTx = 0;
    @endphp
    @forelse($categories as $idx => $c)
      @php
        $totNominal += $c['total_nominal'];
        $totTx += $c['total_count'];
      @endphp
      <tr>
        <td class="text-center">{{ $idx + 1 }}</td>
        <td class="text-center font-bold">{{ $c['code'] }}</td>
        <td>{{ $c['name'] }}</td>
        <td>{{ $c['pic_name'] ?: 'Koordinator Program' }}</td>
        <td class="text-center font-bold" style="font-family: monospace;">{{ $c['qris_unit_digit'] !== null ? "Digit {$c['qris_unit_digit']}" : '-' }}</td>
        <td class="text-center">{{ $c['total_count'] }} kali</td>
        <td class="text-right font-bold" style="color: #0369a1;">Rp {{ number_format($c['total_nominal'], 0, ',', '.') }}</td>
      </tr>
    @empty
      <tr>
        <td colspan="7" class="text-center" style="padding: 24px; color: #94a3b8;">Tidak ada data pos program.</td>
      </tr>
    @endforelse
  </tbody>
  <tfoot>
    <tr class="total-row">
      <td colspan="5" class="text-center font-bold">TOTAL DISTRIBUSI ALOKASI</td>
      <td class="text-center font-bold">{{ $totTx }} kali</td>
      <td class="text-right font-bold" style="color: #0369a1;">Rp {{ number_format($totNominal, 0, ',', '.') }}</td>
    </tr>
  </tfoot>
</table>
@endsection
