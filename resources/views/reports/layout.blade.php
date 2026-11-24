<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{{ $title ?? 'Laporan Keuangan' }} - Pondok Pesantren Annuqayah Latee</title>
  <style>
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }
    body {
      font-family: 'Segoe UI', Arial, Helvetica, sans-serif;
      font-size: 12px;
      line-height: 1.5;
      color: #1e293b;
      background: #f1f5f9;
      padding: 24px 0;
    }
    .no-print {
      display: block;
    }
    .print-toolbar {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      height: 52px;
      background: #0f172a;
      color: #ffffff;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 24px;
      z-index: 9999;
      box-shadow: 0 2px 10px rgba(0,0,0,0.15);
    }
    .toolbar-title {
      font-size: 14px;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .toolbar-actions {
      display: flex;
      gap: 12px;
    }
    .btn-action {
      border: none;
      padding: 7px 16px;
      font-size: 12px;
      font-weight: 600;
      border-radius: 6px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all 0.2s;
    }
    .btn-print {
      background: #0284c7;
      color: #ffffff;
    }
    .btn-print:hover {
      background: #0369a1;
    }
    .btn-close {
      background: #334155;
      color: #cbd5e1;
    }
    .btn-close:hover {
      background: #475569;
      color: #ffffff;
    }
    .sheet-wrapper {
      margin-top: 48px;
      display: flex;
      justify-content: center;
    }
    .sheet {
      background: #ffffff;
      width: 210mm;
      min-height: 297mm;
      padding: 20mm 15mm;
      box-shadow: 0 4px 20px rgba(0,0,0,0.08);
      position: relative;
    }
    .sheet.landscape {
      width: 297mm;
      min-height: 210mm;
      padding: 15mm 15mm;
    }
    /* Kop Surat */
    .kop-surat {
      text-align: center;
      margin-bottom: 16px;
      position: relative;
    }
    .kop-instansi {
      font-size: 14px;
      font-weight: 700;
      letter-spacing: 1.5px;
      color: #0f172a;
    }
    .kop-unit {
      font-size: 20px;
      font-weight: 800;
      letter-spacing: 2px;
      color: #0369a1;
      margin: 2px 0;
    }
    .kop-address {
      font-size: 11px;
      color: #475569;
    }
    .kop-sub {
      font-size: 11px;
      font-style: italic;
      color: #64748b;
    }
    .kop-line {
      height: 3px;
      background: #0f172a;
      margin-top: 10px;
      position: relative;
    }
    .kop-line::after {
      content: '';
      position: absolute;
      top: 4px;
      left: 0;
      right: 0;
      height: 1px;
      background: #0f172a;
    }
    /* Judul Dokumen */
    .doc-header {
      text-align: center;
      margin: 20px 0 16px 0;
    }
    .doc-title {
      font-size: 15px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: #0f172a;
      text-decoration: underline;
    }
    .doc-meta {
      font-size: 11px;
      color: #475569;
      margin-top: 4px;
    }
    /* Tabel Data */
    table.data-table {
      width: 100%;
      border-collapse: collapse;
      margin: 12px 0;
      font-size: 11px;
    }
    table.data-table th, table.data-table td {
      border: 1px solid #cbd5e1;
      padding: 6px 8px;
    }
    table.data-table th {
      background: #f8fafc;
      font-weight: 700;
      color: #0f172a;
      text-align: center;
    }
    table.data-table tbody tr:nth-child(even) {
      background: #fafafa;
    }
    .text-center { text-align: center; }
    .text-right { text-align: right; }
    .text-left { text-align: left; }
    .font-bold { font-weight: 700; }
    .total-row {
      background: #f1f5f9 !important;
      font-weight: 700;
      border-top: 2px solid #0f172a;
      border-bottom: 2px solid #0f172a;
    }
    /* Lembar Pengesahan */
    .signature-section {
      margin-top: 36px;
      display: flex;
      justify-content: space-between;
      page-break-inside: avoid;
    }
    .sig-box {
      width: 220px;
      text-align: center;
    }
    .sig-date {
      font-size: 11px;
      margin-bottom: 6px;
    }
    .sig-role {
      font-size: 11px;
      font-weight: 700;
      color: #0f172a;
    }
    .sig-space {
      height: 64px;
    }
    .sig-name {
      font-size: 12px;
      font-weight: 700;
      text-decoration: underline;
      color: #0f172a;
    }
    .sig-meta {
      font-size: 10px;
      color: #64748b;
    }
    /* Footer Cetak */
    .sheet-footer {
      position: absolute;
      bottom: 12mm;
      left: 15mm;
      right: 15mm;
      display: flex;
      justify-content: space-between;
      font-size: 9px;
      color: #94a3b8;
      border-top: 1px solid #e2e8f0;
      padding-top: 4px;
    }
    /* Print Styles */
    @media print {
      body {
        background: #ffffff !important;
        padding: 0 !important;
        margin: 0 !important;
      }
      .no-print {
        display: none !important;
      }
      .sheet-wrapper {
        margin: 0 !important;
        display: block !important;
      }
      .sheet {
        box-shadow: none !important;
        width: 100% !important;
        min-height: auto !important;
        padding: 0 !important;
        margin: 0 !important;
      }
      tr {
        page-break-inside: avoid;
      }
      @page {
        size: A4 {{ $orientation ?? 'portrait' }};
        margin: 12mm 12mm 12mm 12mm;
      }
    }
  </style>
</head>
<body>

  <!-- Floating Toolbar -->
  <div class="no-print print-toolbar">
    <div class="toolbar-title">
      <span>📄 Pratinjau Dokumen Cetak</span>
      <span style="font-size: 11px; color: #94a3b8; font-weight: normal;">({{ $title ?? 'Laporan Resmi' }})</span>
    </div>
    <div class="toolbar-actions">
      <button type="button" onclick="window.print()" class="btn-action btn-print">🖨️ Cetak / Simpan PDF</button>
      <button type="button" onclick="window.close()" class="btn-action btn-close">❌ Tutup</button>
    </div>
  </div>

  <div class="sheet-wrapper">
    <div class="sheet {{ $orientation ?? '' }}">
      
      <!-- Kop Surat -->
      <div class="kop-surat">
        <div class="kop-instansi">PONDOK PESANTREN ANNUQAYAH</div>
        <div class="kop-unit">DAERAH LATEE</div>
        <div class="kop-address">Guluk-Guluk, Sumenep, Madura, Jawa Timur 69463 • Telp/WA: 0812-3456-7890</div>
        <div class="kop-sub">Sistem Informasi & Manajemen Keuangan Donasi</div>
        <div class="kop-line"></div>
      </div>

      <!-- Header Judul Dokumen -->
      <div class="doc-header">
        <div class="doc-title">{{ $title ?? 'LAPORAN KEUANGAN RESMI' }}</div>
        <div class="doc-meta">
          <strong>Periode:</strong> {{ $periode ?? 'Semua Periode' }}
          @if(!empty($posLabel))
            &nbsp;|&nbsp; <strong>Pos Program:</strong> {{ $posLabel }}
          @endif
        </div>
      </div>

      <!-- Konten Utama Dokumen -->
      @yield('content')

      <!-- Lembar Pengesahan -->
      <div class="signature-section">
        <div class="sig-box">
          <div class="sig-date">&nbsp;</div>
          <div class="sig-role">Bendahara / Pengelola Keuangan</div>
          <div class="sig-space"></div>
          <div class="sig-name">{{ $auditor ?? 'Ainul Fais' }}</div>
          <div class="sig-meta">Bendahara Latee</div>
        </div>

        <div class="sig-box">
          <div class="sig-date">Guluk-Guluk, {{ $tanggalCetak ?? date('d F Y') }}</div>
          <div class="sig-role">Pimpinan / Pengasuh</div>
          <div class="sig-space"></div>
          <div class="sig-name">{{ $pimpinan ?? 'Pimpinan Pesantren' }}</div>
          <div class="sig-meta">Pondok Pesantren Annuqayah Latee</div>
        </div>
      </div>

      <!-- Footer Info -->
      <div class="sheet-footer">
        <div>Dokumen resmi dicetak secara otomatis melalui Sistem Pelaporan Keuangan Latee</div>
        <div>Waktu Cetak: {{ date('d/m/Y H:i') }} WIB</div>
      </div>

    </div>
  </div>

  <script>
    // Buka dialog print secara otomatis saat pratinjau selesai dimuat
    window.addEventListener('load', function() {
      setTimeout(function() {
        window.print();
      }, 500);
    });
  </script>
</body>
</html>
