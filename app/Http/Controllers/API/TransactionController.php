<?php

namespace App\Http\Controllers\API;

use Exception;
use Midtrans\Snap;
use Midtrans\Config;
use App\Models\Transaction;
use Illuminate\Http\Request;
use App\Helpers\ResponseFormatter;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class TransactionController extends Controller
{
    public function all(Request $request)
    {
        $id = $request->input('id');
        $limit = $request->input('limit', 10);
        $food_id = $request->input('food_id');
        $status = $request->input('status');

        if ($id) 
        {
            $transaction = Transaction::with(['food', 'user'])->find($id);

            if ($transaction) 
            {
                return ResponseFormatter::success(
                    $transaction,
                    'Data Transaction Retrieved Successfully'
                );
            }
            else 
            {
                return ResponseFormatter::error(
                    null,
                    'Data Not Found',
                    404
                );
            }
        }

        $transaction = Transaction::with(['food', 'user'])->where('user.id', Auth::user()->id);

        if ($food_id) 
        {
            $transaction->where('food_id', $food_id);
        }

        if ($status) 
        {
            $transaction->where('status', $status);
        }

        return ResponseFormatter::success(
            $transaction->paginate($limit),
            'List Transaction Data Retrieved Successfully'
        );
    }

    public function update(Request $request, $id)
    {
        $transaction = Transaction::findOrFail($id);

        $transaction->update($request->all());

        return ResponseFormatter::success($transaction, 'Transaction has been Updated');
    }

    public function checkout(Request $request)
    {
        $request->validate([
            'food_id' => 'required|exists:food,id',
            'user_id' => 'required|exists:users,id',
            'quantity' => 'required',
            'total' => 'required',
            'status' => 'required',
        ]);

        $transaction = Transaction::create([
            'food_id' => $request->food_id,
            'user_id' => $request->user_id,
            'quantity' => $request->quantity,
            'total' => $request->total,
            'status' => $request->status,
            'payment_url' => '',
        ]);

        // Konfigurasi Midtrans
        Config::$serverKey = config('services.midtrans.serverKey');
        Config::$isProduction = config('services.midtrans.isProduction');
        Config::$isSanitized = config('services.midtrans.isSanitized');
        Config::$is3ds = config('services.midtrans.is3ds');

        // Memanggil Transaksi yang baru dibuat
        $transaction = Transaction::with(['food', 'user'])->find($transaction->id);

        // Membuat Transaksi Midtrans
        $midtrans = [
            'transaction_details' => [
                'order_id' => $transaction->id,
                'gross_amount' => (int) $transaction->total,
            ],
            'customer_details' => [
                'first_name' => $transaction->user->name,
                'email' => $transaction->user->email,
            ],
            'enabled_payments' => ['gopay', 'bank_transfer'],
            'vtweb' => []
        ];

        // Memanggil Midtrans
        try 
        {
            // Ambil halaman payment midtrans
            $paymentUrl = Snap::createTransaction($midtrans)->redirect_url;

            $transaction->payment_url = $paymentUrl;
            $transaction->save();

            //Mengambilkan Data ke API
            return ResponseFormatter::success($transaction, 'Transaction Successfully');
        } 
        catch (Exception $e) 
        {
            return ResponseFormatter::error($e->getMessage(), 'Transaction Failed');
        }

    }
}
