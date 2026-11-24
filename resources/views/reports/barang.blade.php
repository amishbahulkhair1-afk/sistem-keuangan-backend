@extends('reports.layout')

@section('content')
<table class="data-table">
  <thead>
    <tr>
      <th style="width: 35px;">No</th>
      <th style="width: 80px;">Tanggal</th>
      <th>Nama Donatur</th>
      <th style="width: 120px;">Kategori Barang</th>
      <th>Nama Barang / Material</th>
      <th style="width: 90px;">Volume / Qty</th>
      <th style="width: 120px;">Estimasi Nilai (Rp)</th>
      <th style="width: 120px;">Pos Program</th>
      <th style="width: 120px;">Keterangan</th>
    </tr>
  </thead>
  <tbody>
    @php
      $totNilai = 0;
    @endphp
    @forelse($items as $idx => $item)
      @php
        $totNilai += ($item->estimated_value ?? 0);
      @endphp
      <tr>
        <td class="text-center">{{ $idx + 1 }}</td>
        <td class="text-center">{{ $item->transaction_date ? $item->transaction_date->format('d/m/Y') : '-' }}</td>
        <td class="font-bold">{{ $item->donor_name }}</td>
        <td>{{ $item->item_category }}</td>
        <td>{{ $item->item_name }}</td>
        <td class="text-center">{{ (float)$item->quantity }} {{ $item->unit }}</td>
        <td class="text-right font-bold">Rp {{ number_format($item->estimated_value ?? 0, 0, ',', '.') }}</td>
        <td>{{ $item->category?->name ?? 'Umum' }}</td>
        <td>{{ $item->description ?: '-' }}</td>
      </tr>
    @empty
      <tr>
        <td colspan="9" class="text-center" style="padding: 24px; color: #94a3b8;">Tidak ada data penerimaan donasi barang/logistik.</td>
      </tr>
    @endforelse
  </tbody>
  <tfoot>
    <tr class="total-row">
      <td colspan="6" class="text-center font-bold">TOTAL ESTIMASI NILAI BARANG</td>
      <td class="text-right font-bold" style="color: #0369a1;">Rp {{ number_format($totNilai, 0, ',', '.') }}</td>
      <td colspan="2"></td>
    </tr>
  </tfoot>
</table>
@endsection
