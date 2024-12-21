<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaksi;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransaksiAdminController extends Controller
{
    public function index()
    {
        $transaksis = Transaksi::with(['user', 'product'])
            ->latest()
            ->paginate(10);

        return view('admin.transaksi.index', compact('transaksis'));
    }

    public function show(Transaksi $transaksi)
    {
        return view('admin.transaksi.show', compact('transaksi'));
    }

    public function updateStatus(Request $request, Transaksi $transaksi)
    {
        $request->validate([
            'status' => 'required|in:menunggu,diproses,selesai,dibatalkan',
            'payment_status' => 'required|in:menunggu,menunggu konfirmasi,selesai,gagal'
        ]);

        DB::beginTransaction();
        try {
            $transaksi->update([
                'status' => $request->status,
                'payment_status' => $request->payment_status
            ]);

            // Jika status dibatalkan, set produk menjadi available kembali
            if ($request->status === 'dibatalkan') {
                $transaksi->product->update(['status' => 'available']);
            }

            DB::commit();
            return back()->with('success', 'Status transaksi berhasil diperbarui');
        } catch (\Exception $e) {
            DB::rollback();
            return back()->with('error', 'Terjadi kesalahan saat memperbarui status');
        }
    }

    public function report()
    {
        // Data transaksi per bulan
        $monthlyTransactions = Transaksi::selectRaw('
            MONTH(created_at) as month, 
            COUNT(*) as total_transactions, 
            SUM(CASE WHEN status != "dibatalkan" THEN total_price ELSE 0 END) as total_revenue
        ')
            ->whereYear('created_at', date('Y'))
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        // Data produk terlaris (hanya menghitung transaksi yang tidak dibatalkan)
        $topProducts = Product::withCount(['transaksis as total_sold' => function($query) {
                $query->where('status', '!=', 'dibatalkan');
            }])
            ->orderByDesc('total_sold')
            ->limit(5)
            ->get();

        // Data kategori terlaris (tidak termasuk transaksi yang dibatalkan)
        $topCategories = Category::withCount('products')
            ->withCount(['products as total_sold' => function($query) {
                $query->whereHas('transaksis', function($q) {
                    $q->where('status', '!=', 'dibatalkan');
                });
            }])
            ->orderByDesc('total_sold')
            ->limit(5)
            ->get();

        return view('admin.report.index', compact('monthlyTransactions', 'topProducts', 'topCategories'));
    }
} 