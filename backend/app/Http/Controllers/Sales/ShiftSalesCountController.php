<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/sales/shift-count
 *
 * Number of sales rung up during the OPEN cash register shift, for the header
 * pill.
 *
 * WHY NOT A COUNT OF THE CALENDAR DAY. The pill used to ask `/orders` for the
 * day between 00:00 and 23:59, which answers a question nobody on the floor is
 * asking. A shift that starts at 17:00 and runs past midnight had its counter
 * reset under the cashier mid-service, and a drawer opened at 08:00 inherited
 * every sale of the shift that closed before it. The number the cashier
 * reconciles against the drawer is the one for the shift they are standing in,
 * so that is what the shift is scoped by — never a wall clock.
 *
 * WHOSE SHIFT. The caller's own open drawer when they have one, which is the
 * register the POS already rings sales into. Someone supervising the floor
 * never opens a drawer of their own, so for them it falls back to the shifts
 * the BUSINESS currently has open — the same reasoning that makes
 * CashRegisterController::status() business-wide. Without that fallback the
 * pill would read a permanent zero for every administrator.
 *
 * NO OPEN SHIFT MEANS ZERO, not the last shift's total: with the drawer closed
 * there is nothing in progress to count, and showing yesterday's figure beside
 * a closed register is how a cashier reconciles against the wrong number.
 */
class ShiftSalesCountController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $ownShift = CashRegister::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('closed_at')
            ->latest('opened_at')
            ->first();

        $shiftIds = $ownShift
            ? [$ownShift->id]
            : CashRegister::query()->whereNull('closed_at')->pluck('id')->all();

        /*
         * Only settled, completed tickets count as sales. A dine-in account
         * still in `open` has not been charged yet, and a canceled one was
         * charged and given back — counting either would put a number in the
         * header that no drawer will ever match.
         */
        $count = empty($shiftIds) ? 0 : Order::query()
            ->whereIn('cash_register_id', $shiftIds)
            ->where('status', Order::STATUS_COMPLETED)
            ->count();

        return response()->json([
            'status' => 'success',
            'data' => [
                'count' => $count,
                'has_open_shift' => ! empty($shiftIds),
                // Which drawer the figure belongs to, so the UI can word the
                // pill as the cashier's own shift or as the floor's.
                'scope' => $ownShift ? 'own' : 'business',
                'shift_opened_at' => $ownShift?->opened_at,
            ],
        ]);
    }
}
