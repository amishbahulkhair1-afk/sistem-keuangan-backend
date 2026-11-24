@extends('reports.layout')

@section('content')
<table class="data-table">
  <thead>
    <tr>
      <th style="width: 35px;">No</th>
      <th>Nama Donatur</th>
      <th style="width: 110px;">No. Kontak</th>
      <th>Alamat / Domisili</th>
      <th style="width: 90px;">Frekuensi</th>
      <th style="width: 130px;">Total Donasi (Rp)</th>
      <th style="width: 90px;">Donasi Terakhir</th>
    </tr>
  </thead>
  <tbody>
    @php
      $grandTotal = 0;
    @endphp
    @forelse($donors as $idx => $d)
      @php
        $grandTotal += $d['total_amount'];
      @endphp
      <tr>
        <td class="text-center">{{ $idx + 1 }}</td>
        <td class="font-bold">{{ $d['name'] }}</td>
        <td class="text-center">{{ $d['phone'] ?: '-' }}</td>
        <td>{{ $d['address'] ?: '-' }}</td>
        <td class="text-center">{{ $d['frequency'] }}x Donasi</td>
        <td class="text-right font-bold" style="color: #0369a1;">Rp {{ number_format($d['total_amount'], 0, ',', '.') }}</td>
        <td class="text-center">{{ $d['last_donation'] ?: '-' }}</td>
      </tr>
    @empty
      <tr>
        <td colspan="7" class="text-center" style="padding: 24px; color: #94a3b8;">Belum ada data donatur yang tercatat.</td>
      </tr>
    @endforelse
  </tbody>
  <tfoot>
    <tr class="total-row">
      <td colspan="5" class="text-center font-bold">TOTAL AKUMULASI DONASI DITERIMA</td>
      <td class="text-right font-bold" style="color: #0369a1;">Rp {{ number_format($grandTotal, 0, ',', '.') }}</td>
      <td></td>
    </tr>
  </tfoot>
</table>
@endsection
