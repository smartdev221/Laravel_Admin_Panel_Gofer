<?php

/**
 * Payout Detail Controller
 *
 * @package     Gofer
 * @subpackage  Controller
 * @category    Payout Detail
 * @author      Trioangle Product Team
 * @version     2.2.1
 * @link        http://trioangle.com
 */

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Trips;
use App\Models\Currency;
use App\Models\Payment;
use App\Models\Country;
use App\Models\PayoutPreference;
use App\Models\PayoutCredentials;
use App\Models\CurrencyConversion;
use JWTAuth;
use DB;
use Validator;

class PayoutDetailController extends Controller
{
    use CurrencyConversion;
	
	/**
	* View Over All Payout Details of driver
	*
    * @return payout data json
	*/
    public function earningList(Request $request)
    {
        $user_details = JWTAuth::parseToken()->authenticate();

        $rules = [
            'type' => 'required|in:week,weekly,date',
            'start_date' => 'required|date|date_format:Y-m-d',
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'status_code' => '0',
                'status_message' => $validator->messages()->first(),
            ]);
        }

        $start_of_the_week = 0;

        if ($request->type == 'week') {
            $start_date = strtotime($request->start_date);
            $end_date = strtotime("+6 day", $start_date);
            $week_start_date = date('Y-m-d' . ' 00:00:00', $start_date);
            $week_end_date = date('Y-m-d' . ' 23:59:59', $end_date);

            $trips = Trips::where('driver_id',$user_details->id)
                ->whereHas('payment',function($q){
                    // $q->where('driver_payout_status','Pending');
                })
                ->where('status','Completed')
                ->where('payment_mode','<>','Cash')
                ->whereBetween('created_at', [$week_start_date, $week_end_date])
                ->select('*',DB::raw('DATE_FORMAT(created_at,"%Y-%m-%d") as created_at_date'))
                ->orderBy('id')
                ->get();

            $trips_grouping = $trips->groupBy('created_at_date');
            $date_list = array();

            $current_date = strtotime($week_start_date);
            while ($current_date <= strtotime("+6 days", strtotime($week_start_date))) {
                $date = date('d-m-Y', $current_date);
                if ($trips_grouping->has(date('Y-m-d',$current_date))) {
                    $trip = $trips_grouping[date('Y-m-d',$current_date)];
                    
                    $order_data = [
                        "total_fare" => number_format($trip->sum('driver_or_company_earning'),2),
                        "day" => date('l', strtotime($date)),
                        "date" => $date,
                    ];
                }
                else{
                    $order_data = [
                        "total_fare" => "0",
                        "day" => date('l', strtotime($date)),
                        "date" => $date,
                    ];
                }

                $date_list[] = $order_data;
                $current_date = strtotime("+1 day", $current_date);
            }

            $last_trip_total_fare = @$trips->last()->driver_or_company_earning;

            $earning_list = [
                'total_fare' => number_format($trips->sum('driver_or_company_earning'),2),
                'date_list' => $date_list,
                'last_trip_total_fare' => $last_trip_total_fare,
                'last_payout' => '0',
            ];
        }

        $to_currency = $this->getSessionOrDefaultCode();
        $to_currency = Currency::whereCode($to_currency)->first();
        $symbol = html_entity_decode($to_currency->symbol);
        $earning_list['currency_code'] = $to_currency->code;
        $earning_list['currency_symbol'] = $symbol;

        return response()->json([
            'status_code' => '1',
            'status_message' => 'Earning list listed successfully',
            'earning_list' => $earning_list,
        ]);
    }

	/**
	* View Weekly Payout Details of Driver
	*
    * @return payout data json
	*/
	public function weeklyTrip(Request $request)
    {
        $data['filter'] = 'Weekly';
        $user_details = JWTAuth::parseToken()->authenticate();
        $data['driver_id'] = $user_details->id;

        $to_currency = $this->getSessionOrDefaultCode();
        $to_currency = Currency::whereCode($to_currency)->first();
        $symbol = html_entity_decode($to_currency->symbol);
        $per_page = $request->per_page ?? 50; 

        $trips = DB::table('trips')
        ->where('trips.driver_id',$data['driver_id'])
        ->join('users', 'users.id', '=', 'trips.driver_id')
        ->join('payment', 'payment.trip_id', '=', 'trips.id')
        ->where('trips.status','Completed')
        ->groupBy(DB::raw('WEEK(trips.created_at,1)'))
        ->select(
            DB::raw("GROUP_CONCAT(trips.id) as trip_ids"),
            DB::raw('DATE(DATE_FORMAT(trips.created_at,"%Y-%m-%d") + INTERVAL ( - WEEKDAY(trips.created_at)) DAY) as date'),
            DB::raw('DATE_FORMAT(trips.created_at,"%Y") as year')
        )
        ->orderBy('date','DESC')
        ->paginate($per_page);

        foreach ($trips as $trip) {
            //total amount
            $driver_earnings = \DB::table('trips')->join('currency', 'currency.code', '=', 'trips.currency_code')
            ->whereIn('trips.id',explode(",",$trip->trip_ids))
            ->sum(\DB::raw('FORMAT((((trips.subtotal_fare + trips.driver_peak_amount + trips.tips + trips.waiting_charge + trips.toll_fee + trips.additional_rider_amount - trips.driver_or_company_commission) / currency.rate) * '.$to_currency->rate.'),2)'));

            $trip->driver_earnings = number_format($driver_earnings,2);

            //week date
            $start_date = strtotime($trip->date);
            $end_date   = strtotime("+6 day", $start_date);
            $data['from_date']  = date('d M', $start_date);
            $data['to_date']    = date('d M', $end_date);
            $trip->week         = $data['from_date'].' - '.$data['to_date'];

            unset($trip->trip_ids);
        }

        $statement = $trips->items();
        if($request->count)
            $statement = $this->repeatMerge($statement,$request->count);

        return response()->json([
            'trip_week_details' => $statement,
            'status_message' => __('messages.api.listed_successfully'),
            'status_code' => '1',
            'currency_code'=> $to_currency->code,
            'symbol'=> $symbol,
            'current_page' => (int) $request->page,
            'total_page' => $trips->lastPage(),
        ]);
    }


    public function repeatMerge($array, $rCount){
        return call_user_func_array('array_merge', array_fill(1, $rCount, $array));
    }

	/**
	* View Week Day Payout Details of Driver
	*
    * @return payout data json
	*/
	public function weeklyStatement(Request $request)
	{
        $start_date = strtotime($request->date);
        $end_date = strtotime("+6 day", $start_date);
        $from_date = date('Y-m-d' . ' 00:00:00', $start_date);
        $to_date = date('Y-m-d' . ' 23:59:59', $end_date);
        $user_details = JWTAuth::parseToken()->authenticate();

        $to_currency = $user_details->currency_code;
        $to_currency = Currency::whereCode($to_currency)->first();
        $symbol = html_entity_decode($to_currency->symbol);

        Trips::$withoutAppends = true;
        $statement = Trips::getWeeklyStatement($user_details->id,$to_currency->rate,$symbol,$from_date,$to_date);

        $driver_earning = str_replace($symbol, '', array_column($statement->toArray(), 'driver_earning'));
        $driver_commission = str_replace($symbol, '', array_column($statement->toArray(), 'driver_company_commission'));

        $header = array(
            "key" => date('d M', $start_date).' - '.date('d M', $end_date),
            "value" => $symbol.array_sum($driver_earning),
            'colour' => 'green'
        );

        $title = __('messages.api.trip_earning');

        $total_fare = array(
            'key'   => __('messages.api.total_fare'),
            'value' => $symbol.number_format(array_sum($driver_earning) + array_sum($driver_commission),2),
            'tooltip' => __('messages.api.total_fare_tooltip'),
        );
        $content[] = formatStatementItem($total_fare);

        if($user_details->company_id==1) {
            $access_fee = array(
                "key" => __('messages.access_fee'),
                "value" => '-'.$symbol.number_format(array_sum($driver_commission),2),
            );
            $content[] = formatStatementItem($access_fee);
        }

        $driver_earning = array(
            "key" => __('messages.api.driver_earnings'),
            "value" => $symbol.number_format(array_sum($driver_earning),2),
            "bar"   => true,
            "colour"   => 'black',
        );
        $content[] = formatStatementItem($driver_earning);

        if($user_details->company_id==1) {

            $bank_deposits = Trips::getBankDeposits($user_details->id,$to_currency->rate,$from_date,$to_date,'weekly');

            $bank_deposits = array(
                'key'   => __('messages.api.admin_remaining_amount'),
                'value' => $symbol.number_format($bank_deposits,2),
            );
            $content[] = formatStatementItem($bank_deposits);
        }

        $footer = array(
            array(
                "key" => __('messages.driver_dashboard.completed_trips'),
                "value" => count($statement),
            ),
        );

        $driver_statement = array(
            'header'    => $header,
            'title'     => $title,
            'content'   => $content,
            'footer'    => $footer,
        );

        $statement->makeHidden(['driver_company_commission']);

        return response()->json([
            'status_code'       => '1',
            'status_message'    => __('messages.api.listed_successfully'),
            'driver_statement'  => $driver_statement,
            'statement'         => $statement,
        ]);
    }

	/**
	* View Daily Payout Details of Driver
	*
    * @return payout data json
	*/
	public function dailyStatement(Request $request)
	{
		$date = $request->date;
        $timezone = $request->timezone ?? 'UTC';

        $from_date = date('Y-m-d' . ' 00:00:00', strtotime($date));
        $to_date = date('Y-m-d' . ' 23:59:59', strtotime($date));
        $user_details = JWTAuth::parseToken()->authenticate();

        $to_currency = $this->getSessionOrDefaultCode();
        $to_currency = Currency::whereCode($to_currency)->first();
        $symbol = html_entity_decode($to_currency->symbol);
        $per_page = $request->per_page ?? 50; 

        Trips::$withoutAppends = true;
        $trips = Trips::getDailyStatement($user_details->id,$to_currency->rate,$symbol,$from_date,$to_date,$per_page);

        $total_trip_count = $trips->total();
        $total_tripe_page = $trips->lastPage();

        $data = ['status_code' => '1','status_message' => "successfully", 'current_page' => (int)$request->page, 'total_page' => $total_tripe_page];

        $driver_earning = str_replace($symbol, '', array_column($trips->toArray()['data'], 'driver_earning'));
        $driver_commission = str_replace($symbol, '', array_column($trips->toArray()['data'], 'driver_commission'));

        // Get Driver Statement Value Only Page One
        if($request->page == '1') {

            $header = array(
                "key" => date('l', strtotime($date)).' - '.date('d/m', strtotime($date)),
                "value" => $symbol.number_format(array_sum($driver_earning),2),
            );
            $title = __('messages.api.trip_earning');

            $total_fare = array(
                'key'   => __('messages.api.total_fare'),
                'value' => $symbol.number_format(array_sum($driver_earning) + array_sum($driver_commission),2),
                'tooltip' => __('messages.api.total_fare_tooltip'),
            );
            $content[] = formatStatementItem($total_fare);

            if($user_details->company_id==1){
                $access_fee = array(
                    "key" => __('messages.access_fee'),
                    "value" => '-'.$symbol.number_format(array_sum($driver_commission),2),
                );
                $content[] = formatStatementItem($access_fee);
            }
            $driver_earning = array(
                "key" => __('messages.api.driver_earnings'),
                "value" => $symbol.number_format(array_sum($driver_earning),2),
                "bar"   => true,
                'colour' => 'black',
            );
            $content[] = formatStatementItem($driver_earning);

            if($user_details->company_id==1){

                $bank_deposits = Trips::getBankDeposits($user_details->id,$to_currency->rate,$from_date,$to_date,'daily');

                $bank_deposits = array(
                    'key'   => __('messages.api.admin_remaining_amount'),
                    'value' => $symbol.number_format($bank_deposits,2),
                );
                $content[] = formatStatementItem($bank_deposits);
            }

            $footer = array(
                array(
                    "key" => __('messages.driver_dashboard.completed_trips'),
                    "value" => $total_trip_count,
                ),
            );

            $driver_statement = array(
                'header'    => $header,
                'title'     => $title,
                'content'   => $content,
                'footer'    => $footer,
            );

            // Daily Statement Details
            $trips->makeHidden(['driver_commission']);
            $statement = $trips->items();
            if($request->count)
                $statement = $this->repeatMerge($statement,$request->count);

            $data['daily_statement'] = $statement;
            $data['driver_statement'] = $driver_statement;
        }
        return response()->json($data);
	}

    /**
     * Add payout Preferences
     *
     * @param  Post method inputs
     * @return Response in Json
     */
    public function updatePayoutPreference(Request $request)
    {
        $user_details = JWTAuth::parseToken()->authenticate();
        $payout_methods = getPayoutMethods(1);
        $payout_methods = implode($payout_methods,',');

        $rules = array(
            'payout_method' => 'required|in:'.$payout_methods,
        );

        if ($request->payout_method == 'stripe') {
            $rules['country'] = 'required|exists:country,short_name';
        }

        $messages   = array('required'=> ':attribute '.trans('messages.home.field_is_required').'',);
        $validator = Validator::make($request->all(), $rules,$messages);
        
        if($validator->fails()) {
            return response()->json([
                'status_code' => '0',
                'status_message' => $validator->messages()->first()
            ]);
        }

        $user_id = $user_details->id;
        $country = $request->country;
        $payout_default_count = PayoutCredentials::where('user_id', $user_id)->where('default', '=', 'yes');
        $account_holder_type = 'company';
        $payout_method = snakeToCamel($request->payout_method,true);
        $payout_service = resolve('App\Services\Payouts\\'.$payout_method.'Payout');

        if ($payout_method == 'Stripe') {
            $account_holder_type = 'individual';

            $request['payout_country'] = $country;
            $iban_supported_country = Country::getIbanRequiredCountries();

            $bank_data = array(
                "country"               => $country,
                "currency"              => $request->currency,
                "account_holder_name"   => $request->account_holder_name,
                "account_holder_type"   => $account_holder_type,
            );

            if (in_array($country, $iban_supported_country)) {
                $request['account_number'] = $request->iban;
                $bank_data['account_number'] = $request->iban;
            }
            else {
                if ($country == 'AU') {
                    $request['routing_number'] = $request->bsb;
                }
                elseif ($country == 'HK') {
                    $request['routing_number'] = $request->clearing_code . '-' . $request->branch_code;
                }
                elseif ($country == 'CA') {
                    $request['routing_number'] = $request->transit_number . '-' . $request->institution_number;
                }
                elseif ($country == 'SG'|| $country == 'BR') {
                    $request['routing_number'] = $request->bank_code .'-'. $request->branch_code;
                }
                elseif ($country == 'JP') {
                    $request['routing_number'] = $request->bank_code.$request->branch_code;
                }
                elseif ($country == 'GB') {
                    $request['routing_number'] = $request->sort_code;
                }
                $bank_data['routing_number'] = $request['routing_number'];
                $bank_data['account_number'] = $request->account_number;
            }
        }

        $validate_data = $payout_service->validateRequest($request);

        if($validate_data) {
            return $validate_data;
        }

        if($request->hasFile('document')) {
            $image = $request->file('document');

            $image_uploader = resolve('App\Contracts\ImageHandlerInterface');

            $target_dir = '/images/driver/'.$user_id.'/uploads';
            $extension = $image->getClientOriginalExtension();
            $file_name = "payout_document_".time().".".$extension;
            $options = compact('target_dir','file_name');

            $upload_result = $image_uploader->upload($image,$options);
            if(!$upload_result['status']) {
                flashMessage('danger', $upload_result['status_message']);
                return back();
            }
            $filename = $upload_result['file_name'];
            $document_path = public_path($target_dir.'/'.$filename);
        }

        if($request->hasFile('additional_document')) {
            $image = $request->file('additional_document');

            $image_uploader = resolve('App\Contracts\ImageHandlerInterface');

            $target_dir = '/images/driver/'.$user_id.'/uploads';
            $extension = $image->getClientOriginalExtension();
            $file_name = "payout_additional_document_".time().".".$extension;
            $options = compact('target_dir','file_name');

            $upload_result = $image_uploader->upload($image,$options);
            if(!$upload_result['status']) {
                flashMessage('danger', $upload_result['status_message']);
                return back();
            }
            $filename = $upload_result['file_name'];
            $additional_document_path = public_path($target_dir.'/'.$filename);
        }

        if ($payout_method == 'Stripe') {
            $stripe_token = $payout_service->createStripeToken($bank_data);

            if(!$stripe_token['status']) {
                return response()->json([
                    'status_code' => '0',
                    'status_message' => $stripe_token['status_message'],
                ]);
            }

            $request['stripe_token'] = $stripe_token['token'];

            $stripe_preference = $payout_service->createPayoutPreference($request);

            if(!$stripe_preference['status']) {
                return response()->json([
                    'status_code' => '0',
                    'status_message' => $stripe_preference['status_message'],
                ]);
            }

            $recipient = $stripe_preference['recipient'];
            if(isset($document_path)) {
                $document_result = $payout_service->uploadDocument($document_path,$recipient->id);
                if(!$document_result['status']) {
                    return response()->json([
                        'status_code' => '0',
                        'status_message' => $document_result['status_message'],
                    ]);
                }
                $stripe_document = $document_result['stripe_document'];
                $payout_service->attachDocumentToRecipient($recipient->id,$recipient->individual->id,$stripe_document,'document');
            }
            if(isset($additional_document_path)) {
                $document_result = $payout_service->uploadDocument($additional_document_path,$recipient->id);
                if(!$document_result['status']) {
                    return response()->json([
                        'status_code' => '0',
                        'status_message' => $document_result['status_message'],
                    ]);
                }
                $additional_document = $document_result['stripe_document'];
                $payout_service->attachDocumentToRecipient($recipient->id,$recipient->individual->id,$additional_document,'additional_document');

            }

            $payout_email = isset($recipient->id) ? $recipient->id : $user->email;
            $payout_currency = $request->currency ?? '';
        }

        if ($payout_method == 'Paypal') {
            $payout_email = $request->email;
            $payout_currency = PAYPAL_CURRENCY_CODE;
        }

        if ($payout_method == 'BankTransfer') {
            $payout_email       = $request->account_number;
            $payout_currency    = "";
            $request['branch_code']= $request->bank_code;
        }

        $payout_preference = PayoutPreference::firstOrNew(['user_id' => $user_id,'payout_method' => $payout_method]);

        $payout_preference->user_id         = $user_id;
        $payout_preference->country         = $country;
        $payout_preference->currency_code   = $payout_currency;
        $payout_preference->routing_number  = $request->routing_number ?? '';
        $payout_preference->account_number  = $request->account_number ?? '';
        $payout_preference->holder_name     = $request->account_holder_name ?? '';
        $payout_preference->holder_type     = $account_holder_type;
        $payout_preference->paypal_email    = $payout_email;
        $payout_preference->address1    = $request->address1 ?? '';
        $payout_preference->address2    = $request->address2 ?? '';
        $payout_preference->city        = $request->city;
        $payout_preference->state       = $request->state;
        $payout_preference->postal_code = $request->postal_code;
        if (isset($document_path)) {
            $payout_preference->document_id     = $stripe_document ?? '';
            $payout_preference->document_image  = $filename;
        }
        $payout_preference->phone_number    = $request->phone_number ?? '';
        $payout_preference->branch_code     = $request->branch_code ?? '';
        $payout_preference->bank_name       = $request->bank_name ?? '';
        $payout_preference->branch_name     = $request->branch_name ?? '';
        $payout_preference->bank_location     = $request->bank_location ?? '';
        $payout_preference->ssn_last_4      = $country == 'US' ? $request->ssn_last_4 : '';
        $payout_preference->payout_method   = $payout_method;
        $payout_preference->address_kanji   = isset($address_kanji) ? json_encode($address_kanji) : json_encode([]);
        $payout_preference->save();

        $payout_credentials = PayoutCredentials::firstOrNew(['user_id' => $user_id,'type' => $payout_method]);

        $payout_credentials->user_id = $user_id;
        $payout_credentials->preference_id = $payout_preference->id;
        $payout_credentials->payout_id = $payout_email;
        $payout_credentials->type = $payout_method;

        if($payout_credentials->default != 'yes') {
            $payout_credentials->default = $payout_default_count->count() == 0 ? 'yes' : 'no';
        }

        $payout_credentials->save();

        return response()->json([
            'status_code' => '1',
            'status_message' => 'Payout Details Added Successfully',
        ]);
    }

    /**
     * Get payout Preferences
     *
     * @param  Get method Request
     * @return Response in Json
     */
    public function getPayoutPreference(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();

        $payout_methods = getPayoutMethods($user->company_id);

        if($request->filled('type')) {
            if(!in_array($request->type, ['default','delete'])) {
                return response()->json([
                    'status_code' => '1',
                    'status_message' => __('messages.api.invalid_request'),
                ]);
            }
            $payout_id = $request->payout_id;
            $payout_credential = PayoutCredentials::find($payout_id);
            if($payout_credential == '') {
                return response()->json([
                    'status_code' => '1',
                    'status_message' => __('messages.api.invalid_request'),
                ]);
            }

            if($request->type == 'delete') {
                PayoutPreference::where('id', $payout_credential->preference_id)->delete();
                $payout_credential->delete();
            }

            if($request->type == 'default') {
                PayoutCredentials::where('user_id', $user->id)->update(['default' => 'no']);
                $payout_credential->default = 'yes';
                $payout_credential->save();
            }
        }
        
        $payout_credentials = PayoutCredentials::with('payout_preference')->where('user_id', $user->id)->get();

        $payout_data = array();
        foreach ($payout_methods as $method) {
            $payout_credential = $payout_credentials->where('type',snakeToCamel($method,true))->first();
            $preference = optional($payout_credential)->payout_preference;
            $data = array(
                'address1'      => $preference->address1 ?? '',
                'address2'      => $preference->address2 ?? '',
                'city'          => $preference->city ?? '',
                'state'         => $preference->state ?? '',
                'country'       => $preference->country ?? '',
                'postal_code'   => $preference->postal_code ?? '',
                'paypal_email'  => $preference->paypal_email ?? '',
                'currency_code' => $preference->currency_code ?? '',
                'routing_number'=> $preference->routing_number ?? '',
                'account_number'=> $preference->account_number ?? '',
                'holder_name'   => $preference->holder_name ?? '',
                'bank_name'     => $preference->bank_name ?? '',
                'branch_name'   => $preference->branch_name ?? '',
                'branch_code'   => $preference->branch_code ?? '',
                'bank_location' => $preference->bank_location ?? '',
            );
            $payout_method = array(
                'id' => optional($payout_credential)->id ?? 0,
                'key' => $method,
                'is_default' => optional($payout_credential)->default == 'yes',
                'value' => \Lang::get('messages.api.'.$method),
                "icon"          => asset("images/icon/".$method.".png"),
                'payout_data' => $data,
            );
            $payout_data[] = $payout_method;
        }

        return response()->json([
            'status_code'   => '1',
            'status_message'=> 'Payout Details listed Successfully',
            'payout_methods'=> $payout_data,
        ]);
    }

    /**
     * Display Country List
     *
     * @param Get method request inputs
     * @return @return Response in Json
     */
    public function stripeSupportedCountryList(Request $request)
    {
        $helper = resolve('App\Http\Start\Helpers');

        $country_data = Country::select(
            'id as country_id',
            'long_name as country_name',
            'short_name as country_code'
        )
        ->where('stripe_country','Yes')
        ->get();

        $country_list = $country_data->map(function($data) use ($helper) {
            return [
                'country_id'    => $data->country_id,
                'country_name'  => $data->country_name,
                'country_code'  => $data->country_code,
                'currency_code' => $helper->getStripeCurrency($data->country_code),
            ];
        });
        
        return response()->json([
            'status_code'       => '1',
            'status_message'   => 'Country Listed Successfully',
            'country_list'      => $country_list,
        ]);
    }
}
