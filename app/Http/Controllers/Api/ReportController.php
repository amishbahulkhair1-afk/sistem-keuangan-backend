<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CustomBalanceSheetItem;
use App\Models\DebtTransaction;
use App\Models\DonationItem;
use App\Models\Donor;
use App\Models\MaterialItem;
use App\Models\MaterialTransaction;
use App\Models\PosTransaction;
use App\Models\ProgramCategory;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function stats()
    {
        $totalIncome = (float) Transaction::where('type', 'income')->sum('amount');
        $totalExpense = (float) Transaction::where('type', 'expense')->sum('amount');

        $accounts = Account::all();
        $totalAssets = (float) $accounts->sum('current_balance');

        $totalDonors = Donor::count();
        $totalItems = DonationItem::count();

        $categories = ProgramCategory::where('is_active', true)->get();
        $categorySummary = [];
        foreach ($categories as $c) {
            $inc = (float) Transaction::where('type', 'income')->where('category_id', $c->id)->sum('amount');
            $exp = (float) Transaction::where('type', 'expense')->where('category_id', $c->id)->sum('amount');
            $categorySummary[] = [
                'id' => $c->id,
                'name' => $c->name,
                'code' => $c->code,
                'income' => $inc,
                'expense' => $exp,
                'net_balance' => $inc - $exp,
            ];
        }

        // Monthly trends
        $monthNamesId = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Ags', 'Sep', 'Okt', 'Nov', 'Des'];
        $currYear = Carbon::today()->year;
        $monthlyTrends = [];
        $prevMonthIncome = 0.0;

        for ($m = 1; $m <= 12; $m++) {
            $startDate = Carbon::create($currYear, $m, 1)->toDateString();
            $endDate = Carbon::create($currYear, $m, 1)->endOfMonth()->toDateString();

            $mInc = (float) Transaction::where('type', 'income')
                ->whereBetween('transaction_date', [$startDate, $endDate])
                ->sum('amount');

            $mExp = (float) Transaction::where('type', 'expense')
                ->whereBetween('transaction_date', [$startDate, $endDate])
                ->sum('amount');

            $growthPct = 0.0;
            if ($prevMonthIncome > 0) {
                $growthPct = round((($mInc - $prevMonthIncome) / $prevMonthIncome) * 100, 1);
            }

            $monthlyTrends[] = [
                'month_num' => $m,
                'month' => $monthNamesId[$m],
                'income' => $mInc,
                'expense' => $mExp,
                'net' => $mInc - $mExp,
                'growth_pct' => $growthPct,
            ];

            if ($mInc > 0) {
                $prevMonthIncome = $mInc;
            }
        }

        // Yearly trends (last 3 years)
        $yearlyTrends = [];
        $prevYearIncome = 0.0;
        for ($y = $currYear - 2; $y <= $currYear; $y++) {
            $yInc = (float) Transaction::where('type', 'income')
                ->whereYear('transaction_date', $y)
                ->sum('amount');

            $yExp = (float) Transaction::where('type', 'expense')
                ->whereYear('transaction_date', $y)
                ->sum('amount');

            $growth = 0.0;
            if ($prevYearIncome > 0) {
                $growth = round((($yInc - $prevYearIncome) / $prevYearIncome) * 100, 1);
            }

            $yearlyTrends[] = [
                'year' => (string) $y,
                'income' => $yInc,
                'expense' => $yExp,
                'net' => $yInc - $yExp,
                'growth_pct' => $growth,
            ];

            if ($yInc > 0) {
                $prevYearIncome = $yInc;
            }
        }

        return response()->json([
            'total_income' => $totalIncome,
            'total_expense' => $totalExpense,
            'net_balance' => $totalIncome - $totalExpense,
            'total_assets' => $totalAssets,
            'total_donors' => $totalDonors,
            'total_donation_items' => $totalItems,
            'category_summary' => $categorySummary,
            'accounts' => $accounts->map(fn ($a) => ['name' => $a->name, 'balance' => (float) $a->current_balance]),
            'monthly_trends' => $monthlyTrends,
            'yearly_trends' => $yearlyTrends,
        ]);
    }

    public function bukuBantu(Request $request)
    {
        $year = (int) $request->query('year', Carbon::today()->year);
        $month = (int) $request->query('month', Carbon::today()->month);

        $categories = ProgramCategory::where('is_active', true)->get();
        $rows = [];
        $totalSaldoLalu = 0.0;
        $totalMutasiMasuk = 0.0;
        $totalMutasiKeluar = 0.0;
        $totalSaldoAkhir = 0.0;

        $startCurrent = Carbon::create($year, $month, 1)->toDateString();
        $endCurrent = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

        foreach ($categories as $c) {
            $incLalu = (float) Transaction::where('category_id', $c->id)
                ->where('type', 'income')
                ->where('transaction_date', '<', $startCurrent)
                ->sum('amount');

            $expLalu = (float) Transaction::where('category_id', $c->id)
                ->where('type', 'expense')
                ->where('transaction_date', '<', $startCurrent)
                ->sum('amount');

            $saldoLalu = $incLalu - $expLalu;

            $incIni = (float) Transaction::where('category_id', $c->id)
                ->where('type', 'income')
                ->whereBetween('transaction_date', [$startCurrent, $endCurrent])
                ->sum('amount');

            $expIni = (float) Transaction::where('category_id', $c->id)
                ->where('type', 'expense')
                ->whereBetween('transaction_date', [$startCurrent, $endCurrent])
                ->sum('amount');

            $saldoIni = $incIni - $expIni;
            $saldoAkhir = $saldoLalu + $saldoIni;

            $totalSaldoLalu += $saldoLalu;
            $totalMutasiMasuk += $incIni;
            $totalMutasiKeluar += $expIni;
            $totalSaldoAkhir += $saldoAkhir;

            $rows[] = [
                'pos_id' => $c->id,
                'pos_code' => $c->code,
                'pos_name' => $c->name,
                'saldo_lalu' => $saldoLalu,
                'mutasi_masuk' => $incIni,
                'mutasi_keluar' => $expIni,
                'saldo_ini' => $saldoIni,
                'saldo_akhir' => $saldoAkhir,
            ];
        }

        return response()->json([
            'periode' => "Bulan {$month} Tahun {$year}",
            'rows' => $rows,
            'totals' => [
                'total_saldo_lalu' => $totalSaldoLalu,
                'total_mutasi_masuk' => $totalMutasiMasuk,
                'total_mutasi_keluar' => $totalMutasiKeluar,
                'total_saldo_akhir' => $totalSaldoAkhir,
            ],
        ]);
    }

    public function neraca(Request $request)
    {
        $year = (int) $request->query('year', Carbon::today()->year);
        $month = (int) $request->query('month', Carbon::today()->month);

        $accounts = Account::all();
        $categories = ProgramCategory::where('is_active', true)->orderBy('id')->get();

        // 1. Debts & Receivables across system
        $debts = DebtTransaction::all();
        $totHutangPinjam = (float) $debts->where('type', 'hutang_pinjam')->sum('amount');
        $totHutangBayar = (float) $debts->where('type', 'hutang_bayar')->sum('amount');
        $sisaHutang = max(0, $totHutangPinjam - $totHutangBayar);

        $totPiutangPinjam = (float) $debts->where('type', 'piutang_pinjam')->sum('amount');
        $totPiutangBayar = (float) $debts->where('type', 'piutang_bayar')->sum('amount');
        $sisaPiutang = max(0, $totPiutangPinjam - $totPiutangBayar);

        // 2. Custom Balance Sheet Items (category_id is null)
        $customItems = CustomBalanceSheetItem::whereNull('category_id')->get();

        // ==========================================
        // AKTIVA A: HARTA LANCAR (Kas Tunai, Kas Bank, Piutang)
        // ==========================================
        $aktivaHartaLancar = [];

        // 1. Kas Tunai
        $kasTunaiAcc = $accounts->first(fn ($a) => $a->code === 'kas_tunai' || (stripos($a->name, 'tunai') !== false && stripos($a->name, 'piutang') === false));
        $kasTunaiSaldo = (float) ($kasTunaiAcc ? $kasTunaiAcc->current_balance : 0);
        $aktivaHartaLancar[] = [
            'id' => null,
            'code' => 'KAS',
            'name' => 'Kas Tunai',
            'amount_prev' => 0.0,
            'amount_current' => $kasTunaiSaldo,
            'amount' => $kasTunaiSaldo,
            'is_auto' => true,
            'side' => 'aktiva',
            'sub_category' => 'harta_lancar',
        ];

        // 2. Kas Bank
        $bankAccs = $accounts->filter(function ($a) {
            $code = strtolower($a->code ?: '');
            $name = strtolower($a->name ?: '');
            return $code !== 'kas_tunai' && $code !== 'piutang' && stripos($name, 'tunai') === false && stripos($name, 'piutang') === false;
        });

        if ($bankAccs->isEmpty()) {
            $aktivaHartaLancar[] = [
                'id' => null,
                'code' => 'BANK',
                'name' => 'Kas Bank',
                'amount_prev' => 0.0,
                'amount_current' => 0.0,
                'amount' => 0.0,
                'is_auto' => true,
                'side' => 'aktiva',
                'sub_category' => 'harta_lancar',
            ];
        } else {
            foreach ($bankAccs as $b) {
                $bCode = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $b->code ?: $b->name), 0, 5)) ?: 'BANK';
                $aktivaHartaLancar[] = [
                    'id' => null,
                    'code' => $bCode,
                    'name' => "Kas Bank ({$b->name})",
                    'amount_prev' => 0.0,
                    'amount_current' => (float) $b->current_balance,
                    'amount' => (float) $b->current_balance,
                    'is_auto' => true,
                    'side' => 'aktiva',
                    'sub_category' => 'harta_lancar',
                ];
            }
        }

        // 3. Piutang
        $piutangAcc = $accounts->first(fn ($a) => $a->code === 'piutang' || stripos($a->name, 'piutang') !== false);
        $piutangSaldo = (float) ($piutangAcc ? $piutangAcc->current_balance : 0) + $sisaPiutang;
        $aktivaHartaLancar[] = [
            'id' => null,
            'code' => 'P.U',
            'name' => 'Piutang ke Pihak Lain',
            'amount_prev' => 0.0,
            'amount_current' => $piutangSaldo,
            'amount' => $piutangSaldo,
            'is_auto' => true,
            'side' => 'aktiva',
            'sub_category' => 'harta_lancar',
        ];

        // Custom items for harta_lancar
        foreach ($customItems->where('side', 'aktiva')->filter(fn ($ci) => in_array($ci->sub_category, ['harta_lancar', 'piutang', 'kas'])) as $ci) {
            $aktivaHartaLancar[] = [
                'id' => $ci->id,
                'code' => $ci->code ?: '-',
                'name' => $ci->name,
                'amount_prev' => (float) $ci->amount_prev_month,
                'amount_current' => (float) $ci->amount_current_month,
                'amount' => (float) $ci->amount_total,
                'is_auto' => false,
                'side' => 'aktiva',
                'sub_category' => 'harta_lancar',
            ];
        }

        // ==========================================
        // AKTIVA B: BIAYA-BIAYA TIAP POS PROGRAM
        // ==========================================
        $aktivaBiaya = [];

        foreach ($categories as $c) {
            $centerExp = (float) Transaction::where('category_id', $c->id)->where('type', 'expense')->sum('amount');
            $posExp = (float) PosTransaction::where('category_id', $c->id)->where('type', 'expense')->sum('amount');
            $totalExp = $centerExp + $posExp;

            $aktivaBiaya[] = [
                'id' => null,
                'code' => $c->code,
                'name' => "Realisasi Biaya: {$c->name}",
                'amount_prev' => 0.0,
                'amount_current' => $totalExp,
                'amount' => $totalExp,
                'is_auto' => true,
                'side' => 'aktiva',
                'sub_category' => 'biaya_biaya',
            ];
        }

        // Realisasi Biaya Umum Lembaga (jika ada transaksi expense tanpa category_id)
        $centerGeneralExp = (float) Transaction::whereNull('category_id')->where('type', 'expense')->sum('amount');
        if ($centerGeneralExp > 0) {
            $aktivaBiaya[] = [
                'id' => null,
                'code' => 'UMUM',
                'name' => 'Realisasi Biaya: Operasional Umum Lembaga',
                'amount_prev' => 0.0,
                'amount_current' => $centerGeneralExp,
                'amount' => $centerGeneralExp,
                'is_auto' => true,
                'side' => 'aktiva',
                'sub_category' => 'biaya_biaya',
            ];
        }

        // Custom items for biaya_biaya
        foreach ($customItems->where('side', 'aktiva')->filter(fn ($ci) => !in_array($ci->sub_category, ['harta_lancar', 'piutang', 'kas'])) as $ci) {
            $aktivaBiaya[] = [
                'id' => $ci->id,
                'code' => $ci->code ?: '-',
                'name' => $ci->name,
                'amount_prev' => (float) $ci->amount_prev_month,
                'amount_current' => (float) $ci->amount_current_month,
                'amount' => (float) $ci->amount_total,
                'is_auto' => false,
                'side' => 'aktiva',
                'sub_category' => 'biaya_biaya',
            ];
        }

        // ==========================================
        // PASSIVA A: KEWAJIBAN / HUTANG
        // ==========================================
        $passivaKewajiban = [];

        $passivaKewajiban[] = [
            'id' => null,
            'code' => 'H.U',
            'name' => 'Kewajiban Hutang ke Pihak Lain',
            'amount_prev' => 0.0,
            'amount_current' => $sisaHutang,
            'amount' => $sisaHutang,
            'is_auto' => true,
            'side' => 'passiva',
            'sub_category' => 'kewajiban',
        ];

        // Custom items for kewajiban
        foreach ($customItems->where('side', 'passiva')->filter(fn ($ci) => in_array($ci->sub_category, ['kewajiban', 'hutang'])) as $ci) {
            $passivaKewajiban[] = [
                'id' => $ci->id,
                'code' => $ci->code ?: '-',
                'name' => $ci->name,
                'amount_prev' => (float) $ci->amount_prev_month,
                'amount_current' => (float) $ci->amount_current_month,
                'amount' => (float) $ci->amount_total,
                'is_auto' => false,
                'side' => 'passiva',
                'sub_category' => 'kewajiban',
            ];
        }

        // ==========================================
        // PASSIVA B: SUMBER DANA TIAP POS
        // ==========================================
        $passivaSumberDana = [];

        foreach ($categories as $c) {
            $centerInc = (float) Transaction::where('category_id', $c->id)->where('type', 'income')->sum('amount');
            $posInc = (float) PosTransaction::where('category_id', $c->id)
                ->where('type', 'income')
                ->whereIn('category_tx_type', ['pendapatan_pos_langsung', 'donasi_langsung'])
                ->sum('amount');
            $totalInc = $centerInc + $posInc;

            $passivaSumberDana[] = [
                'id' => null,
                'code' => $c->code,
                'name' => "Sumber Dana: {$c->name}",
                'amount_prev' => 0.0,
                'amount_current' => $totalInc,
                'amount' => $totalInc,
                'is_auto' => true,
                'side' => 'passiva',
                'sub_category' => 'sumber_dana',
            ];
        }

        // Sumber Dana Umum Pusat (jika ada donasi tanpa category_id)
        $centerGeneralInc = (float) Transaction::whereNull('category_id')->where('type', 'income')->sum('amount');
        if ($centerGeneralInc > 0) {
            $passivaSumberDana[] = [
                'id' => null,
                'code' => 'UMUM',
                'name' => 'Sumber Dana: Umum Lembaga (Tidak Terikat)',
                'amount_prev' => 0.0,
                'amount_current' => $centerGeneralInc,
                'amount' => $centerGeneralInc,
                'is_auto' => true,
                'side' => 'passiva',
                'sub_category' => 'sumber_dana',
            ];
        }

        // Custom items for sumber_dana
        foreach ($customItems->where('side', 'passiva')->filter(fn ($ci) => !in_array($ci->sub_category, ['kewajiban', 'hutang'])) as $ci) {
            $passivaSumberDana[] = [
                'id' => $ci->id,
                'code' => $ci->code ?: '-',
                'name' => $ci->name,
                'amount_prev' => (float) $ci->amount_prev_month,
                'amount_current' => (float) $ci->amount_current_month,
                'amount' => (float) $ci->amount_total,
                'is_auto' => false,
                'side' => 'passiva',
                'sub_category' => 'sumber_dana',
            ];
        }

        // Subtotals & Grand Totals
        $subtotalAktivaA = array_sum(array_column($aktivaHartaLancar, 'amount'));
        $subtotalAktivaB = array_sum(array_column($aktivaBiaya, 'amount'));
        $totalAktiva = $subtotalAktivaA + $subtotalAktivaB;

        $subtotalPassivaA = array_sum(array_column($passivaKewajiban, 'amount'));
        $subtotalPassivaB = array_sum(array_column($passivaSumberDana, 'amount'));
        $totalPassiva = $subtotalPassivaA + $subtotalPassivaB;

        $selisih = round($totalAktiva - $totalPassiva, 2);

        $aktivaGroups = [
            [
                'code' => 'A',
                'sub_category' => 'harta_lancar',
                'title' => 'A. Harta Lancar',
                'items' => $aktivaHartaLancar,
                'subtotal' => (float) $subtotalAktivaA,
            ],
            [
                'code' => 'B',
                'sub_category' => 'biaya_biaya',
                'title' => 'B. Biaya-Biaya Tiap Pos Program',
                'items' => $aktivaBiaya,
                'subtotal' => (float) $subtotalAktivaB,
            ],
        ];

        $passivaGroups = [
            [
                'code' => 'A',
                'sub_category' => 'kewajiban',
                'title' => 'A. Kewajiban',
                'items' => $passivaKewajiban,
                'subtotal' => (float) $subtotalPassivaA,
            ],
            [
                'code' => 'B',
                'sub_category' => 'sumber_dana',
                'title' => 'B. Sumber Dana Tiap Pos',
                'items' => $passivaSumberDana,
                'subtotal' => (float) $subtotalPassivaB,
            ],
        ];

        return response()->json([
            'periode' => "Bulan {$month} Tahun {$year}",
            'aktiva' => [
                'groups' => $aktivaGroups,
                'harta_lancar' => $aktivaHartaLancar,
                'biaya_biaya' => $aktivaBiaya,
                'total_aktiva' => (float) $totalAktiva,
            ],
            'passiva' => [
                'groups' => $passivaGroups,
                'kewajiban' => $passivaKewajiban,
                'sumber_dana' => $passivaSumberDana,
                'dana_program' => $passivaSumberDana,
                'total_passiva' => (float) $totalPassiva,
            ],
            'reconciliation' => [
                'is_balanced' => ($selisih == 0.0),
                'selisih' => (float) $selisih,
                'status' => ($selisih == 0.0) ? 'SEIMBANG (KLOP)' : "SELISIH Rp " . number_format($selisih, 2, ',', '.'),
            ],
            'pengesahan' => [
                'tanggal_laporan' => Carbon::today()->translatedFormat('d F Y'),
                'pemeriksa' => 'Ainul Fais',
                'penanggung_jawab' => 'Penanggung Jawab Keuangan',
            ],
        ]);
    }

    public function neracaPos($categoryId, Request $request)
    {
        $cat = ProgramCategory::findOrFail($categoryId);

        // Pos internal transactions
        $posTxs = PosTransaction::where('category_id', $categoryId)->get();
        $posIncomeTotal = (float) $posTxs->where('type', 'income')->sum('amount');
        $posExpenseTotal = (float) $posTxs->where('type', 'expense')->sum('amount');
        $sisaKasPos = $posIncomeTotal - $posExpenseTotal;

        // Debt transactions
        $debts = DebtTransaction::where('category_id', $categoryId)->get();
        $totHutangPinjam = (float) $debts->where('type', 'hutang_pinjam')->sum('amount');
        $totHutangBayar = (float) $debts->where('type', 'hutang_bayar')->sum('amount');
        $sisaHutang = $totHutangPinjam - $totHutangBayar;

        $totPiutangPinjam = (float) $debts->where('type', 'piutang_pinjam')->sum('amount');
        $totPiutangBayar = (float) $debts->where('type', 'piutang_bayar')->sum('amount');
        $sisaPiutang = $totPiutangPinjam - $totPiutangBayar;

        // ==========================================
        // 1. AKTIVA A: HARTA LANCAR & PERSEDIAAN
        // ==========================================
        $aktivaHartaLancar = [
            ['code' => 'K.K', 'name' => "Kas Operasional Pos ({$cat->name})", 'amount' => (float) $sisaKasPos, 'is_auto' => true],
            ['code' => 'P.U', 'name' => 'Piutang ke Pihak Lain', 'amount' => (float) $sisaPiutang, 'is_auto' => true],
        ];

        // ==========================================
        // 2. AKTIVA B: AKUMULASI REALISASI BIAYA POS
        // ==========================================
        // Pengeluaran pos otomatis tercatat di Aktiva B sehingga neraca pos seimbang (klop)
        $aktivaBiaya = [];

        $expenseCategories = [
            'belanja_material' => ['code' => 'B.MAT', 'name' => 'Realisasi Belanja Material & Logistik'],
            'upah_tukang' => ['code' => 'B.UPAH', 'name' => 'Realisasi Upah Tukang & Tenaga Kerja'],
            'operasional_lapangan' => ['code' => 'B.OPS', 'name' => 'Biaya Operasional Lapangan'],
            'sewa_alat' => ['code' => 'B.ALAT', 'name' => 'Sewa Alat & Perlengkapan'],
            'biaya_umum' => ['code' => 'B.UMUM', 'name' => 'Biaya Umum & Administrasi Pos'],
            'biaya_lainnya' => ['code' => 'B.LAIN', 'name' => 'Biaya Operasional Lainnya'],
        ];

        $expenseTxs = $posTxs->where('type', 'expense')->whereNotIn('category_tx_type', ['hutang_bayar', 'piutang_pinjam']);

        foreach ($expenseCategories as $txKey => $meta) {
            $amt = (float) $expenseTxs->where('category_tx_type', $txKey)->sum('amount');
            if ($amt > 0) {
                $aktivaBiaya[] = [
                    'code' => $meta['code'],
                    'name' => $meta['name'],
                    'amount' => $amt,
                    'is_auto' => true,
                ];
            }
        }

        // Biaya lainnya yang tidak masuk kategori standar di atas
        $handledTypes = array_merge(array_keys($expenseCategories), ['hutang_bayar', 'piutang_pinjam']);
        $unhandledExpenses = $expenseTxs->whereNotIn('category_tx_type', $handledTypes)->groupBy('category_tx_type');
        foreach ($unhandledExpenses as $typeKey => $groupTxs) {
            $amt = (float) $groupTxs->sum('amount');
            if ($amt > 0) {
                $codePart = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', (string)$typeKey), 0, 4)) ?: 'EXP';
                $aktivaBiaya[] = [
                    'code' => 'B.' . $codePart,
                    'name' => 'Realisasi Biaya: ' . ucwords(str_replace('_', ' ', (string)$typeKey)),
                    'amount' => $amt,
                    'is_auto' => true,
                ];
            }
        }

        // ==========================================
        // 3. PASSIVA A: KEWAJIBAN / HUTANG
        // ==========================================
        $passivaKewajiban = [
            ['code' => 'H.U', 'name' => 'Kewajiban Hutang ke Pihak Lain', 'amount' => (float) $sisaHutang, 'is_auto' => true],
        ];

        // ==========================================
        // 4. PASSIVA B: SUMBER DANA POS
        // ==========================================
        // Neraca Dinamis Pos HANYA terupdate bila terdapat transaksi pada pos:
        // - Pemasukan yang dicairkan dari kas utama (alokasi_pusat)
        // - Pemasukan dari pos itu sendiri (donasi_langsung, pendapatan_pos_langsung, dll)
        // Donasi masuk pusat (Transaction type: income) TIDAK mengupdate neraca dinamis pos.
        $alokasiKasUtama = (float) $posTxs->where('type', 'income')
            ->where('category_tx_type', 'alokasi_pusat')
            ->sum('amount');

        $pemasukanLangsungPos = (float) $posTxs->where('type', 'income')
            ->whereNotIn('category_tx_type', ['alokasi_pusat', 'hutang_pinjam', 'piutang_bayar'])
            ->sum('amount');

        $passivaSumberDana = [
            ['code' => 'D.PUSAT', 'name' => 'Pencairan Kas Utama (Alokasi Lembaga)', 'amount' => (float) $alokasiKasUtama, 'is_auto' => true],
            ['code' => 'D.POS', 'name' => 'Penerimaan / Donasi Langsung Pos', 'amount' => (float) $pemasukanLangsungPos, 'is_auto' => true],
        ];

        // ==========================================
        // 5. CUSTOM ITEMS (Penyesuaian Manual)
        // ==========================================
        $customItems = CustomBalanceSheetItem::where('category_id', $categoryId)->get();
        foreach ($customItems as $ci) {
            $totalAmount = (float) ($ci->amount_total ?: ((float) $ci->amount_prev_month + (float) $ci->amount_current_month));

            $itemData = [
                'id' => $ci->id,
                'code' => $ci->code ?: '-',
                'name' => $ci->name,
                'amount_prev' => (float) $ci->amount_prev_month,
                'amount_current' => (float) $ci->amount_current_month,
                'amount' => $totalAmount,
                'is_auto' => false,
            ];

            if ($ci->side === 'aktiva') {
                if ($ci->sub_category === 'harta_lancar') {
                    $aktivaHartaLancar[] = $itemData;
                } else {
                    $aktivaBiaya[] = $itemData;
                }
            } else {
                if (in_array($ci->sub_category, ['kewajiban', 'hutang'])) {
                    $passivaKewajiban[] = $itemData;
                } else {
                    $passivaSumberDana[] = $itemData;
                }
            }
        }

        $totalAktiva = array_sum(array_column($aktivaHartaLancar, 'amount')) + array_sum(array_column($aktivaBiaya, 'amount'));
        $totalPassiva = array_sum(array_column($passivaKewajiban, 'amount')) + array_sum(array_column($passivaSumberDana, 'amount'));
        $selisih = round($totalAktiva - $totalPassiva, 2);

        return response()->json([
            'pos' => [
                'id' => $cat->id,
                'code' => $cat->code,
                'name' => $cat->name,
                'category_type' => $cat->category_type,
                'pic_name' => $cat->pic_name ?: 'Penanggung Jawab',
            ],
            'aktiva' => [
                'harta_lancar' => $aktivaHartaLancar,
                'biaya_operasional' => $aktivaBiaya,
                'subtotal_harta_lancar' => (float) array_sum(array_column($aktivaHartaLancar, 'amount')),
                'subtotal_biaya' => (float) array_sum(array_column($aktivaBiaya, 'amount')),
                'total_aktiva' => (float) $totalAktiva,
            ],
            'passiva' => [
                'kewajiban' => $passivaKewajiban,
                'sumber_dana' => $passivaSumberDana,
                'modal_dan_pendapatan' => $passivaSumberDana,
                'subtotal_kewajiban' => (float) array_sum(array_column($passivaKewajiban, 'amount')),
                'subtotal_sumber_dana' => (float) array_sum(array_column($passivaSumberDana, 'amount')),
                'total_passiva' => (float) $totalPassiva,
            ],
            'reconciliation' => [
                'is_balanced' => ($selisih == 0.0),
                'selisih' => (float) $selisih,
                'status' => ($selisih == 0.0) ? 'SEIMBANG (KLOP)' : "SELISIH Rp " . number_format($selisih, 2, ',', '.'),
            ],
        ]);
    }

    public function storeNeracaPosItem($categoryId, Request $request)
    {
        $validated = $request->validate([
            'side' => 'required|string',
            'sub_category' => 'required|string',
            'name' => 'required|string',
        ]);

        $prev = (float) ($request->amount_prev_month ?? 0);
        $curr = (float) ($request->amount_current_month ?? 0);
        $total = (float) ($request->amount_total ?? ($prev + $curr));

        $item = CustomBalanceSheetItem::create([
            'category_id' => $categoryId,
            'period' => $request->period ?: '2026-05',
            'side' => $validated['side'],
            'sub_category' => $validated['sub_category'],
            'code' => $request->code,
            'name' => trim($validated['name']),
            'amount_prev_month' => $prev,
            'amount_current_month' => $curr,
            'amount_total' => $total,
            'is_auto' => false,
        ]);

        return response()->json([
            'id' => $item->id,
            'message' => 'Baris neraca manual berhasil ditambahkan',
        ], 201);
    }

    public function updateNeracaPosItem($itemId, Request $request)
    {
        $item = CustomBalanceSheetItem::findOrFail($itemId);
        $validated = $request->validate([
            'side' => 'required|string',
            'sub_category' => 'required|string',
            'name' => 'required|string',
        ]);

        $prev = (float) ($request->amount_prev_month ?? 0);
        $curr = (float) ($request->amount_current_month ?? 0);
        $total = (float) ($request->amount_total ?? ($prev + $curr));

        $item->update([
            'side' => $validated['side'],
            'sub_category' => $validated['sub_category'],
            'code' => $request->code,
            'name' => trim($validated['name']),
            'amount_prev_month' => $prev,
            'amount_current_month' => $curr,
            'amount_total' => $total,
        ]);

        return response()->json([
            'id' => $item->id,
            'message' => "Baris neraca '{$item->name}' berhasil diperbarui",
        ]);
    }

    public function destroyNeracaPosItem($itemId)
    {
        $item = CustomBalanceSheetItem::findOrFail($itemId);
        $item->delete();
        return response()->json(['message' => 'Baris neraca berhasil dihapus']);
    }

    public function storeNeracaItem(Request $request)
    {
        $validated = $request->validate([
            'side' => 'required|string',
            'sub_category' => 'required|string',
            'name' => 'required|string',
        ]);

        $prev = (float) ($request->amount_prev_month ?? 0);
        $curr = (float) ($request->amount_current_month ?? 0);
        $total = (float) ($request->amount_total ?? ($prev + $curr));

        $item = CustomBalanceSheetItem::create([
            'category_id' => null,
            'period' => $request->period ?: Carbon::today()->format('Y-m'),
            'side' => $validated['side'],
            'sub_category' => $validated['sub_category'],
            'code' => $request->code ?: '-',
            'name' => trim($validated['name']),
            'amount_prev_month' => $prev,
            'amount_current_month' => $curr,
            'amount_total' => $total,
            'is_auto' => false,
        ]);

        return response()->json([
            'id' => $item->id,
            'message' => 'Baris neraca konsolidasi berhasil ditambahkan',
        ], 201);
    }

    public function updateNeracaItem($itemId, Request $request)
    {
        return $this->updateNeracaPosItem($itemId, $request);
    }

    public function destroyNeracaItem($itemId)
    {
        return $this->destroyNeracaPosItem($itemId);
    }

    public function exportPdf(Request $request, string $type)
    {
        // 1. Verifikasi Autentikasi (Mendukung Bearer Token header atau query ?token=...)
        $user = $request->user();
        if (!$user && $request->has('token')) {
            $pat = \Laravel\Sanctum\PersonalAccessToken::findToken($request->query('token'));
            if ($pat) {
                $user = $pat->tokenable;
            }
        }

        if (!$user) {
            return response('<!DOCTYPE html><html><head><title>Akses Ditolak</title><style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;background:#f8fafc;color:#1e293b;}.box{background:white;padding:32px;border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,0.08);text-align:center;max-width:400px;}h2{color:#ef4444;margin-top:0;}button{background:#0284c7;color:white;border:none;padding:10px 20px;border-radius:8px;font-weight:600;cursor:pointer;margin-top:16px;}</style></head><body><div class="box"><h2>Akses Ditolak (401)</h2><p>Sesi Anda tidak valid atau telah berakhir. Silakan login kembali ke portal untuk mencetak dokumen ini.</p><button onclick="window.close()">Tutup Halaman</button></div></body></html>', 401)->header('Content-Type', 'text/html');
        }

        $year = (int) $request->query('year', Carbon::today()->year);
        $month = (int) $request->query('month', Carbon::today()->month);
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $pos = $request->query('pos');
        $jenis = $request->query('jenis');

        $tanggalCetak = Carbon::today()->translatedFormat('d F Y');
        $auditor = 'Ainul Fais';
        $pimpinan = 'Pimpinan Pesantren Annuqayah Latee';

        switch (strtolower(trim($type))) {
            case 'neraca':
                $data = $this->neraca($request)->getData(true);
                return view('reports.neraca', array_merge($data, [
                    'title' => 'LAPORAN NERACA KEUANGAN RESMI',
                    'orientation' => 'portrait',
                    'tanggalCetak' => $tanggalCetak,
                    'auditor' => $auditor,
                    'pimpinan' => $pimpinan,
                ]));

            case 'buku_bantu':
                $data = $this->bukuBantu($request)->getData(true);
                return view('reports.buku_bantu', array_merge($data, [
                    'title' => 'BUKU PEMBANTU SALDO PROGRAM',
                    'orientation' => 'portrait',
                    'tanggalCetak' => $tanggalCetak,
                    'auditor' => $auditor,
                    'pimpinan' => $pimpinan,
                ]));

            case 'transaksi':
                $query = Transaction::with(['category', 'account', 'donor']);
                if ($startDate && $endDate) {
                    $query->whereBetween('transaction_date', [$startDate, $endDate]);
                    $periode = Carbon::parse($startDate)->format('d/m/Y') . ' s/d ' . Carbon::parse($endDate)->format('d/m/Y');
                } else {
                    $startMonth = Carbon::create($year, $month, 1)->toDateString();
                    $endMonth = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
                    $query->whereBetween('transaction_date', [$startMonth, $endMonth]);
                    $periode = "Bulan {$month} Tahun {$year}";
                }

                if ($pos) {
                    $query->whereHas('category', fn ($q) => $q->where('name', $pos)->orWhere('code', $pos));
                }
                if ($jenis) {
                    $query->where('type', $jenis);
                }

                $transactions = $query->orderBy('transaction_date', 'asc')->orderBy('id', 'asc')->get();

                return view('reports.transaksi', [
                    'title' => 'JURNAL MUTASI KAS & BANK',
                    'orientation' => 'landscape',
                    'periode' => $periode,
                    'posLabel' => $pos ?: 'Semua Pos Program',
                    'transactions' => $transactions,
                    'tanggalCetak' => $tanggalCetak,
                    'auditor' => $auditor,
                    'pimpinan' => $pimpinan,
                ]);

            case 'donatur':
                $donors = Donor::with(['transactions' => function ($q) use ($startDate, $endDate, $pos) {
                    if ($startDate && $endDate) {
                        $q->whereBetween('transaction_date', [$startDate, $endDate]);
                    }
                    if ($pos) {
                        $q->whereHas('category', fn ($c) => $c->where('name', $pos)->orWhere('code', $pos));
                    }
                }])->get();

                $donorRows = [];
                foreach ($donors as $d) {
                    $txs = $d->transactions->where('type', 'income');
                    if ($pos || ($startDate && $endDate)) {
                        if ($txs->isEmpty()) continue;
                    }
                    $tot = (float) $txs->sum('amount');
                    $lastTx = $txs->sortByDesc('transaction_date')->first();

                    $donorRows[] = [
                        'name' => $d->name,
                        'phone' => $d->phone,
                        'address' => $d->address,
                        'frequency' => $txs->count(),
                        'total_amount' => $tot,
                        'last_donation' => $lastTx && $lastTx->transaction_date ? $lastTx->transaction_date->format('d/m/Y') : '-',
                    ];
                }

                usort($donorRows, fn ($a, $b) => $b['total_amount'] <=> $a['total_amount']);

                $periode = ($startDate && $endDate)
                    ? Carbon::parse($startDate)->format('d/m/Y') . ' s/d ' . Carbon::parse($endDate)->format('d/m/Y')
                    : 'Akumulasi Seluruh Donasi Terdaftar';

                return view('reports.donatur', [
                    'title' => 'REKAPITULASI DONATUR & DERMAWAN',
                    'orientation' => 'portrait',
                    'periode' => $periode,
                    'posLabel' => $pos ?: 'Semua Pos Program',
                    'donors' => $donorRows,
                    'tanggalCetak' => $tanggalCetak,
                    'auditor' => $auditor,
                    'pimpinan' => $pimpinan,
                ]);

            case 'pemetaan_program':
                $categories = ProgramCategory::where('is_active', true)->get();
                $catRows = [];
                foreach ($categories as $c) {
                    $txs = Transaction::where('category_id', $c->id)->where('type', 'income');
                    if ($startDate && $endDate) {
                        $txs->whereBetween('transaction_date', [$startDate, $endDate]);
                    }
                    $totNominal = (float) $txs->sum('amount');
                    $totCount = $txs->count();

                    $catRows[] = [
                        'code' => $c->code,
                        'name' => $c->name,
                        'pic_name' => $c->pic_name,
                        'qris_unit_digit' => $c->qris_unit_digit,
                        'total_nominal' => $totNominal,
                        'total_count' => $totCount,
                    ];
                }

                $periode = ($startDate && $endDate)
                    ? Carbon::parse($startDate)->format('d/m/Y') . ' s/d ' . Carbon::parse($endDate)->format('d/m/Y')
                    : 'Akumulasi Distribusi Program';

                return view('reports.pemetaan_program', [
                    'title' => 'PEMETAAN DONASI & ALOKASI PROGRAM',
                    'orientation' => 'portrait',
                    'periode' => $periode,
                    'categories' => $catRows,
                    'tanggalCetak' => $tanggalCetak,
                    'auditor' => $auditor,
                    'pimpinan' => $pimpinan,
                ]);

            case 'barang':
                $query = DonationItem::with('category');
                if ($startDate && $endDate) {
                    $query->whereBetween('transaction_date', [$startDate, $endDate]);
                    $periode = Carbon::parse($startDate)->format('d/m/Y') . ' s/d ' . Carbon::parse($endDate)->format('d/m/Y');
                } else {
                    $startMonth = Carbon::create($year, $month, 1)->toDateString();
                    $endMonth = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
                    $query->whereBetween('transaction_date', [$startMonth, $endMonth]);
                    $periode = "Bulan {$month} Tahun {$year}";
                }

                if ($pos) {
                    $query->whereHas('category', fn ($q) => $q->where('name', $pos)->orWhere('code', $pos));
                }

                $items = $query->orderBy('transaction_date', 'asc')->get();

                return view('reports.barang', [
                    'title' => 'LAPORAN PENERIMAAN DONASI BARANG & LOGISTIK',
                    'orientation' => 'landscape',
                    'periode' => $periode,
                    'posLabel' => $pos ?: 'Semua Pos Program',
                    'items' => $items,
                    'tanggalCetak' => $tanggalCetak,
                    'auditor' => $auditor,
                    'pimpinan' => $pimpinan,
                ]);

            default:
                abort(404, "Jenis laporan '{$type}' tidak ditemukan.");
        }
    }
}
