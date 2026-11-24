@extends('reports.layout')

@section('content')
<table class="data-table">
  <thead>
    <tr>
      <th style="width: 35px;">No</th>
      <th style="width: 70px;">Kode Pos</th>
      <th>Nama Pos Program</th>
      <th style="width: 110px;">Saldo Awal (Rp)</th>
      <th style="width: 110px;">Penerimaan (Rp)</th>
      <th style="width: 110px;">Pengeluaran (Rp)</th>
      <th style="width: 120px;">Saldo Akhir (Rp)</th>
    </tr>
  </thead>
  <tbody>
    @forelse($rows as $idx => $r)
      <tr>
        <td class="text-center">{{ $idx + 1 }}</td>
        <td class="text-center font-bold">{{ $r['pos_code'] }}</td>
        <td>{{ $r['pos_name'] }}</td>
        <td class="text-right">Rp {{ number_format($r['saldo_lalu'], 0, ',', '.') }}</td>
        <td class="text-right" style="color: #15803d;">Rp {{ number_format($r['mutasi_masuk'], 0, ',', '.') }}</td>
        <td class="text-right" style="color: #b91c1c;">Rp {{ number_format($r['mutasi_keluar'], 0, ',', '.') }}</td>
        <td class="text-right font-bold">Rp {{ number_format($r['saldo_akhir'], 0, ',', '.') }}</td>
      </tr>
    @empty
      <tr>
        <td colspan="7" class="text-center" style="padding: 24px; color: #94a3b8;">Tidak ada data buku pembantu untuk periode ini.</td>
      </tr>
    @endforelse
  </tbody>
  <tfoot>
    <tr class="total-row">
      <td colspan="3" class="text-center font-bold">TOTAL KESELURUHAN</td>
      <td class="text-right font-bold">Rp {{ number_format($totals['total_saldo_lalu'], 0, ',', '.') }}</td>
      <td class="text-right font-bold" style="color: #15803d;">Rp {{ number_format($totals['total_mutasi_masuk'], 0, ',', '.') }}</td>
      <td class="text-right font-bold" style="color: #b91c1c;">Rp {{ number_format($totals['total_mutasi_keluar'], 0, ',', '.') }}</td>
      <td class="text-right font-bold">Rp {{ number_format($totals['total_saldo_akhir'], 0, ',', '.') }}</td>
    </tr>
  </tfoot>
</table>
@endsection
