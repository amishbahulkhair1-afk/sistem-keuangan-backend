<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CustomBalanceSheetItem;
use App\Models\DebtTransaction;
use App\Models\DonationItem;
use App\Models\Donor;
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

        $bukuBantu = $this->bukuBantu($request)->getData(true);
        $accounts = Account::all();

        $totalAktiva = (float) $accounts->sum('current_balance');
        $totalPassiva = (float) ($bukuBantu['totals']['total_saldo_akhir'] ?? 0);
        $selisih = round($totalAktiva - $totalPassiva, 2);

        return response()->json([
            'periode' => "Bulan {$month} Tahun {$year}",
            'aktiva' => [
                'harta_lancar' => $accounts->map(fn ($a) => ['nama' => $a->name, 'saldo' => (float) $a->current_balance]),
                'total_aktiva' => $totalAktiva,
            ],
            'passiva' => [
                'dana_program' => $bukuBantu['rows'],
                'total_passiva' => $totalPassiva,
            ],
            'reconciliation' => [
                'is_balanced' => ($selisih == 0.0),
                'selisih' => $selisih,
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

        // Center donations
        $centerDonasi = (float) Transaction::where('category_id', $categoryId)->where('type', 'income')->sum('amount');

        // Direct / allocated pos donations
        $posDonasiLangsung = (float) PosTransaction::where('category_id', $categoryId)
            ->where('type', 'income')
            ->whereIn('category_tx_type', ['alokasi_pusat', 'pendapatan_pos_langsung'])
            ->sum('amount');

        $totalDonasi = $centerDonasi + $posDonasiLangsung;

        // Pos internal cash
        $posIncomeTotal = (float) PosTransaction::where('category_id', $categoryId)->where('type', 'income')->sum('amount');
        $posExpenseTotal = (float) PosTransaction::where('category_id', $categoryId)->where('type', 'expense')->sum('amount');
        $sisaKasPos = $posIncomeTotal - $posExpenseTotal;

        // Debt transactions
        $debts = DebtTransaction::where('category_id', $categoryId)->get();
        $totHutangPinjam = $debts->where('type', 'hutang_pinjam')->sum('amount');
        $totHutangBayar = $debts->where('type', 'hutang_bayar')->sum('amount');
        $sisaHutang = $totHutangPinjam - $totHutangBayar;

        $totPiutangPinjam = $debts->where('type', 'piutang_pinjam')->sum('amount');
        $totPiutangBayar = $debts->where('type', 'piutang_bayar')->sum('amount');
        $sisaPiutang = $totPiutangPinjam - $totPiutangBayar;

        // Custom items
        $customItems = CustomBalanceSheetItem::where('category_id', $categoryId)->get();

        $aktivaHartaLancar = [
            ['code' => 'K.K', 'name' => "Kas Operasional Pos ({$cat->name})", 'amount' => (float) $sisaKasPos, 'is_auto' => true],
            ['code' => 'P.U', 'name' => 'Piutang ke Pihak Lain', 'amount' => (float) $sisaPiutang, 'is_auto' => true],
        ];

        $aktivaBiaya = [];

        $passivaKewajiban = [
            ['code' => 'H.U', 'name' => 'Kewajiban Hutang ke Pihak Lain', 'amount' => (float) $sisaHutang, 'is_auto' => true],
        ];

        $passivaSumberDana = [
            ['code' => 'DONASI', 'name' => 'Akumulasi Penerimaan Donasi & Alokasi Lembaga', 'amount' => (float) $totalDonasi, 'is_auto' => true],
        ];

        foreach ($customItems as $ci) {
            $matchedTxAmount = (float) PosTransaction::where('category_id', $categoryId)
                ->where(function ($q) use ($ci) {
                    $q->where('category_tx_type', $ci->code)->orWhere('category_tx_type', $ci->name);
                })->sum('amount');

            $currentAmount = (float) $ci->amount_current_month + $matchedTxAmount;
            $totalAmount = (float) $ci->amount_prev_month + $currentAmount;

            $itemData = [
                'id' => $ci->id,
                'code' => $ci->code ?: '-',
                'name' => $ci->name,
                'amount_prev' => (float) $ci->amount_prev_month,
                'amount_current' => (float) $currentAmount,
                'amount' => (float) $totalAmount,
                'is_auto' => false,
            ];

            if ($ci->side === 'aktiva') {
                if ($ci->sub_category === 'harta_lancar') {
                    $aktivaHartaLancar[] = $itemData;
                } else {
                    $aktivaBiaya[] = $itemData;
                }
            } else {
                if ($ci->sub_category === 'kewajiban') {
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
                'total_aktiva' => (float) $totalAktiva,
            ],
            'passiva' => [
                'kewajiban' => $passivaKewajiban,
                'sumber_dana' => $passivaSumberDana,
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
}
