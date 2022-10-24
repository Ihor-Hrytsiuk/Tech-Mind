<?php

namespace App\Http\Controllers\Api;

use App\Coupons;
use App\CouponsOrder;
use App\CouponsUsedToLessons;
use App\Http\Controllers\Controller;
use App\Lesson;
use App\LessonToCourses;
use App\Traits\PaymentManager;
use App\UsersCoupons;
use App\UsersToLessons;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class CouponsApiController extends Controller
{
    /**
     * Get all coupons.
     * @group  Coupons
     * @response {
     *  "success": true,
     *  "data":[
     *          {
     *              "id":1,
     *              "name":"Coupon 1",
     *              "type":"group",
     *              "limits":[
     *                  "limit": 8,
     *                  "price": 22.00
     *              ]
     *          }
     *      ]
     * }
     */
    public function coupons()
    {
        $coupons = Coupons::with('price')->get();
        $result  = [];

        foreach ($coupons as $coupon) {
            $limits = [];

            foreach ($coupon->price as $price_info) {
                $limits[] = [
                    'limit' => $price_info->limit,
                    'price' => $price_info->price,
                ];
            }

            $result[] = [
                'id'        => $coupon->id,
                'name'      => $coupon->name,
                'type'      => $coupon->type,
                'limits'    => $limits,
            ];
        }

        return response()->json(['success' => true, 'data' => $result], 200);
    }

    /**
     * Refresh coupons.
     * @group  Coupons
     * @response {
     *  "success": true,
     *  "data":[
     *          {
     *              "id":1,
     *              "name":"Coupon 1",
     *              "type":"group"
     *          }
     *      ]
     * }
     */
    public function getCurrentUserCoupons()
    {
        $this->checkCoupons();

        $coupons = UsersCoupons::where('user_id', Auth::id())->get();
        $result  = [];

        foreach ($coupons as $coupon) {
            $coupon_info = Coupons::find($coupon->coupon_id);

            $result[] = [
                'id'            => $coupon_info->id,
                'name'          => $coupon_info->name,
                'type'          => $coupon_info->type,
                'count'         => $coupon->count,
            ];
        }

        return response()->json(['success' => true, 'data' => $result], 200);
    }

    private function checkCoupons()
    {
        $open_orders = CouponsOrder::where('user_id', Auth::user()->id)->where('payment_status', 'open')->get();

        foreach ($open_orders as $open_order) {
            try {
                if (! empty($open_order->payment_token)) {
                    PaymentManager::checkPayment($open_order->payment_token, 'coupon');
                } else {
                    Log::critical('payment_token is empty at ', ['order' => $open_order]);
                }
            } catch (\Exception $e) {
                Log::critical('error in check payment ', ['message' => $e->getMessage()]);
            }
        }
    }

    /**
     * Apply Coupon.
     * @group  Coupons
     * @bodyParam coupon_id integer required
     * @bodyParam lesson_id integer required
     * @response {
     *  "success": true
     * }
     *
     * @response 200 {
     *  "errors":{
     *      "lesson_id":["Такого урока не существует"]
     *  }
     * }
     */
    public function ApplyCoupon(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'coupon_id' => ['required', 'integer'],
            'lesson_id' => ['required', 'integer'],
        ]);

        if (! $validator->fails()) {
            $coupon = UsersCoupons::where('user_id', Auth::id())->where('coupon_id', $request->coupon_id)->first();

            if ($coupon->count > 0) {
                $lesson_exists = Lesson::where('id', $request->lesson_id)->count();

                if (! $lesson_exists) {
                    return response()->json(['errors' => ['lesson_id' => ['Такого урока не существует']]], 200);
                }

                try {
                    $this->addLesson(Auth::id(), $request->lesson_id, $request->coupon_id);
                } catch (\Exception $e) {
                    return response()->json(['errors' => ['course' => [$e->getMessage()]]], 200);
                }

                UsersCoupons::where('user_id', Auth::id())->where('coupon_id', $request->coupon_id)->update(['count' => $coupon->count - 1]);

                return response()->json(['success' => true]);
            }
        } else {
            return response()->json(['errors' => $validator->messages()], 200);
        }
    }

    /**
     * @param $user_id
     * @param $lesson_id
     * @param $coupon_id
     * @throws \Exception
     */
    private function addLesson($user_id, $lesson_id, $coupon_id)
    {
        $lesson_to_courses = LessonToCourses::where('lesson_id', $lesson_id)->first();
        if (! empty($lesson_to_courses)) {
            CouponsUsedToLessons::insert([
                'user_id'   => $user_id,
                'lesson_id' => $lesson_id,
                'coupon_id' => $coupon_id,
            ]);

            UsersToLessons::insert([
                'user_id'   => $user_id,
                'lesson_id' => $lesson_id,
                'course_id' => $lesson_to_courses->course_id,
                'progress'  => '{}',
                'finish'    => 0,
            ]);
        } else {
            throw new \Exception('У данной лекции нету курса!');
        }
    }
}
