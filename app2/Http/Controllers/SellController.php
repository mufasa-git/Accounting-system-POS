<?php

namespace App\Http\Controllers;

use App\PurchaseLine;
use App\TransactionSellLinesPurchaseLines;
use Illuminate\Http\Request;

use App\Currency;
use App\Business;
use App\TaxRate;
use App\Transaction;
use App\BusinessLocation;
use App\TransactionSellLine;
use App\User;
use App\Bank;
use App\CustomerGroup;
use Yajra\DataTables\Facades\DataTables;
use App\Contact;
use App\TransactionPayment;
use DB;

use App\Utils\ContactUtil;
use App\Utils\BusinessUtil;
use App\Utils\TransactionUtil;
use App\Utils\ModuleUtil;

use Log;

class SellController extends Controller
{
    /**
     * All Utils instance.
     *
     */
    protected $contactUtil;
    protected $businessUtil;
    protected $transactionUtil;


    /**
     * Constructor
     *
     * @param ProductUtils $product
     * @return void
     */
    public function __construct(ContactUtil $contactUtil, BusinessUtil $businessUtil, TransactionUtil $transactionUtil, ModuleUtil $moduleUtil)
    {
        $this->contactUtil = $contactUtil;
        $this->businessUtil = $businessUtil;
        $this->transactionUtil = $transactionUtil;
        $this->moduleUtil = $moduleUtil;

        $this->dummyPaymentLine = ['method' => 'cash', 'amount' => 0, 'note' => '', 'card_transaction_number' => '', 'card_number' => '', 'card_type' => '', 'card_holder_name' => '', 'card_month' => '', 'card_year' => '', 'card_security' => '', 'cheque_number' => '', 'bank_account_number' => '', 
        'is_return' => 0, 'transaction_no' => ''];
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if (!auth()->user()->can('sell.view') && !auth()->user()->can('sell.create') && !auth()->user()->can('direct_sell.access')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        $locations = BusinessLocation::where('business_id', $business_id)
            ->pluck('name', 'id');

        $status_type[1] = 'Paid';
        $status_type[2] = 'Due';
        $status_type[3] = 'Partial';

        $payment_status = $status_type;

        if (request()->ajax()) {
            $business_id = request()->session()->get('user.business_id');

            $sells = Transaction::leftJoin('contacts', 'transactions.contact_id', '=', 'contacts.id')
                ->leftJoin('transaction_payments as tp', 'transactions.id', '=', 'tp.transaction_id')
                ->join(
                    'business_locations AS bl',
                    'transactions.location_id',
                    '=',
                    'bl.id'
                )
                ->where('transactions.business_id', $business_id)
                ->where('transactions.type', 'sell')
                ->where('transactions.status', 'final')
                ->select(
                    'transactions.id',
                    'transaction_date',
                    'is_direct_sale',
                    'invoice_no',
                    'contacts.name',
                    'payment_status',
                    'final_total',
                    DB::raw('SUM(IF(tp.is_return = 1,-1*tp.amount,tp.amount)) as total_paid'),
                    'bl.name as business_location'
                );

            if (!empty(request()->input('location'))) {
                $sells->where('bl.name', $locations[request()->input('location')]);
            }
            if (!empty(request()->input('payment_status'))) {
                $sells->where('payment_status', $payment_status[request()->input('payment_status')]);
            }

            $permitted_locations = auth()->user()->permitted_locations();
            if ($permitted_locations != 'all') {
                $sells->whereIn('transactions.location_id', $permitted_locations);
            }

            //Add condition for created_by,used in sales representative sales report
            if (request()->has('created_by')) {
                $created_by = request()->get('created_by');
                if (!empty($created_by)) {
                    $sells->where('transactions.created_by', $created_by);
                }
            }

            //Add condition for location,used in sales representative expense report
            if (request()->has('location_id')) {
                $location_id = request()->get('location_id');
                if (!empty($location_id)) {
                    $sells->where('transactions.location_id', $location_id);
                }
            }

            if (!empty(request()->customer_id)) {
                $customer_id = request()->customer_id;
                $sells->where('contacts.id', $customer_id);
            }
            if (!empty(request()->start_date) && !empty(request()->end_date)) {
                $start = request()->start_date;
                $end =  request()->end_date;
                $sells->whereDate('transaction_date', '>=', $start)
                            ->whereDate('transaction_date', '<=', $end);
            }

            //Check is_direct sell
            if (request()->has('is_direct_sale')) {
                $is_direct_sale = request()->is_direct_sale;
                if ($is_direct_sale == 0) {
                    $sells->where('is_direct_sale', 0);
                }
            }

            //Add condition for commission_agent,used in sales representative sales with commission report
            if (request()->has('commission_agent')) {
                $commission_agent = request()->get('commission_agent');
                if (!empty($commission_agent)) {
                    $sells->where('transactions.commission_agent', $commission_agent);
                }
            }
            $sells->groupBy('transactions.id');

            return Datatables::of($sells)
                ->addColumn(
                    'action',
                    '<div class="btn-group">
                    <button type="button" class="btn btn-info dropdown-toggle btn-xs" 
                        data-toggle="dropdown" aria-expanded="false">' .
                        __("messages.actions") .
                        '<span class="caret"></span><span class="sr-only">Toggle Dropdown
                        </span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-right" role="menu">
                    @if(auth()->user()->can("sell.view") || auth()->user()->can("direct_sell.access") )
                        <li><a href="#" data-href="{{action(\'SellController@show\', [$id])}}" class="btn-modal" data-container=".view_modal"><i class="fa fa-external-link" aria-hidden="true"></i> @lang("messages.view")</a></li>
                    @endif
                    @if($is_direct_sale == 0)
                        @can("sell.update")
                        <li><a target="_blank" href="{{action(\'SellPosController@edit\', [$id])}}"><i class="glyphicon glyphicon-edit"></i> @lang("messages.edit")</a></li>
                        @endcan
                    @else
                        @can("direct_sell.access")
                            <li><a href="{{action(\'SellController@edit\', [$id])}}"><i class="glyphicon glyphicon-edit"></i> @lang("messages.edit")</a></li>
                        @endcan
                    @endif
                    @can("sell.delete")
                    <li><a href="{{action(\'SellPosController@destroy\', [$id])}}" class="delete-sale"><i class="fa fa-trash"></i> @lang("messages.delete")</a></li>
                    @endcan

                    @if(auth()->user()->can("sell.view") || auth()->user()->can("direct_sell.access") )
                        <li><a href="#" class="print-invoice" data-href="{{route(\'sell.printInvoice\', [$id, "USD"])}}"><i class="fa fa-print" aria-hidden="true"></i> @lang("messages.print_usd")</a></li>
                        <li><a href="#" class="print-invoice" data-href="{{route(\'sell.printInvoice\', [$id, "IQD"])}}"><i class="fa fa-print" aria-hidden="true"></i> @lang("messages.print_iqd")</a></li>
                    @endif
                    
                    <li class="divider"></li> 
                    @if($payment_status != "paid")
                        @if(auth()->user()->can("sell.create") || auth()->user()->can("direct_sell.access") )
                            <li><a href="{{action(\'TransactionPaymentController@addPayment\', [$id])}}" class="add_payment_modal"><i class="fa fa-money"></i> @lang("purchase.add_payment")</a></li>
                        @endif
                    @endif
                        <li><a href="{{action(\'TransactionPaymentController@show\', [$id])}}" class="view_payment_modal"><i class="fa fa-money"></i> @lang("purchase.view_payments")</a></li>
                    </ul></div>'
                )
                ->removeColumn('id')
                ->editColumn(
                    'final_total',
                    '<span class="display_currency final-total" data-currency_symbol="true" data-orig-value="{{$final_total}}">{{$final_total}}</span>'
                )
                ->editColumn(
                    'total_paid',
                    '<span class="display_currency total-paid" data-currency_symbol="true" data-orig-value="{{$total_paid}}">{{$total_paid}}</span>'
                )
                ->editColumn('transaction_date', '{{@format_date($transaction_date)}}')
                ->editColumn(
                    'payment_status',
                    '<a href="{{ action("TransactionPaymentController@show", [$id])}}" class="view_payment_modal payment-status-label" data-orig-value="{{$payment_status}}" data-status-name="{{__(\'lang_v1.\' . $payment_status)}}"><span class="label @payment_status($payment_status)">{{__(\'lang_v1.\' . $payment_status)}}
                        </span></a>'
                )
                 ->addColumn('total_remaining', function ($row) {
                    $total_remaining =  $row->final_total - $row->total_paid;
                    return '<span data-orig-value="' . $total_remaining . '" class="display_currency total-remaining" data-currency_symbol="true">' . $total_remaining . '</span>';
                 })
                ->setRowAttr([
                    'data-href' => function ($row) {
                        if (auth()->user()->can("sell.view")) {
                            return  action('SellController@show', [$row->id]) ;
                        } else {
                            return '';
                        }
                    }])
                ->rawColumns(['final_total', 'action', 'total_paid', 'total_remaining', 'payment_status', 'invoice_no'])
                ->make(true);
        }
        return view('sell.index')->with(compact('locations', 'payment_status'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        if (!auth()->user()->can('direct_sell.access')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        //Check if subscribed or not, then check for users quota
        if (!$this->moduleUtil->isSubscribed($business_id)) {
            return $this->moduleUtil->expiredResponse();
        } elseif (!$this->moduleUtil->isQuotaAvailable('invoices', $business_id)) {
            return $this->moduleUtil->quotaExpiredResponse('invoices', $business_id, action('SellController@index'));
        }

        $banks = Bank::where('business_id', '=', $business_id)->pluck('name', 'id');
        $walk_in_customer = $this->contactUtil->getWalkInCustomer($business_id);
        
        $business_details = $this->businessUtil->getDetails($business_id);
        $taxes = TaxRate::forBusinessDropdown($business_id, true, true);

        $business_locations = BusinessLocation::forDropdown($business_id, false, true);
        $bl_attributes = $business_locations['attributes'];
        $business_locations = $business_locations['locations'];

        $default_location = null;
        if (count($business_locations) == 1) {
            foreach ($business_locations as $id => $name) {
                $default_location = $id;
            }
        }

        $business = Business::where('id', $business_id)->first();
        $default_currency_id = $business->currency_id;

        $commsn_agnt_setting = $business_details->sales_cmsn_agnt;
        $commission_agent = [];
        if ($commsn_agnt_setting == 'user') {
            $commission_agent = User::forDropdown($business_id);
        } elseif ($commsn_agnt_setting == 'cmsn_agnt') {
            $commission_agent = User::saleCommissionAgentsDropdown($business_id);
        }

        $types = [];
        if (auth()->user()->can('supplier.create')) {
            $types['supplier'] = __('report.supplier');
        }
        if (auth()->user()->can('customer.create')) {
            $types['customer'] = __('report.customer');
        }
        if (auth()->user()->can('supplier.create') && auth()->user()->can('customer.create')) {
            $types['both'] = __('lang_v1.both_supplier_customer');
        }
        $customer_groups = CustomerGroup::forDropdown($business_id);

	    $available_product_list = [];

        $payment_line = $this->dummyPaymentLine;
        $payment_types = $this->transactionUtil->payment_types();

	    $currency_details = $this->transactionUtil->purchaseCurrencyDetails($business_id);
	    $currencies = Currency::where('currency_rate', '>', 0)->pluck('code', 'id');
	    $business = Business::where('id', $business_id)->first();
	    $default_currency_id = $business->currency_id;
	    $default_currency = Currency::findOrFail($default_currency_id);
	    $default_currency_rate = $default_currency->currency_rate;

        return view('sell.create')
            ->with(compact(
                'business_details',
                'taxes',
                'walk_in_customer',
                'business_locations',
                'bl_attributes',
                'default_location',
                'commission_agent',
                'types',
                'customer_groups',
                'payment_line',
                'payment_types',
                'currencies',
                'default_currency_id',
                'available_product_list',
                'banks',
	            'currency_details',
                'default_currency_id',
                'default_currency_rate'
            ));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        if (!auth()->user()->can('sell.view') && !auth()->user()->can('direct_sell.access')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        $taxes = TaxRate::where('business_id', $business_id)
                            ->pluck('name', 'id');

        $transaction = Transaction::findOrFail($id);
//        $exp_dates = PurchaseLine::LeftJoin('transaction_sell_lines_purchase_lines as tslpl', 'tslpl.purchase_line_id', '=', 'purchase_lines.id')
//            ->LeftJoin('transaction_sell_lines as tsl', 'tsl.id', '=', 'tslpl.sell_line_id')
//            ->where('tsl.transaction_id', '=', $id)
//            ->select('exp_date')->get();
        $exp_dates = PurchaseLine::LeftJoin('transaction_sell_lines as tsl', 'tsl.purchase_lines_id', '=', 'purchase_lines.id')
	        ->where('tsl.transaction_id', '=', $id)
	        ->select('exp_date')
	        ->get();

	    $customer_transactions = Transaction::where('transactions.business_id', '=', $business_id)
		    ->where('transactions.contact_id', '=', $transaction['contact_id'])
		    ->where('transactions.status', '=', 'final')
		    ->select(
			    'id',
			    'type',
			    'transactions.final_total as final_total'
		    )->get();

	    $customer_balance = 0.0;
	    foreach ($customer_transactions as $ctrans) {
		    if ($ctrans->type == 'opening_balance') $customer_balance += $ctrans->final_total;
		    else if ($ctrans->type == 'sell') $customer_balance += $ctrans->final_total;
		    $query = TransactionPayment::where('transaction_id', '=', $ctrans->id)->select('amount')->get();
		    $payment = $query->sum('amount');
		    $customer_balance -= $payment;
	    }

        $sell = Transaction::where('business_id', $business_id)
                                ->where('id', $id)
                                ->with(['contact', 'sell_lines' => function ($q) {
                                    $q->whereNull('parent_sell_line_id');
                                },'sell_lines.product', 'sell_lines.variations', 'sell_lines.variations.product_variation', 'payment_lines', 'sell_lines.modifiers', 'sell_lines.lot_details'])
                                ->first();
        
        return view('sale_pos.show')
            ->with(compact('taxes', 'sell', 'customer_balance', 'exp_dates'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        if (!auth()->user()->can('direct_sell.access')) {
            abort(403, 'Unauthorized action.');
        }

	    try {
		    //Check if the transaction can be edited or not.
		    $edit_days = request()->session()->get('business.transaction_edit_days');
		    if (!$this->transactionUtil->canBeEdited($id, $edit_days)) {
			    return back()
				    ->with('status', ['success' => 0,
					    'msg' => __('messages.transaction_edit_not_allowed', ['days' => $edit_days])]);
		    }

		    $query = TransactionPayment::where('transaction_id', '=', $id)->get();
		    $sz = count($query);

		    if ($sz > 0) {
			    return back()
				    ->with('status', ['success' => 0,
					    'msg' => __('lang_v1.retry_after_delete_payment_record')
				    ]);
		    } else {
			    $business_id = request()->session()->get('user.business_id');

			    $banks = Bank::where('business_id', '=', $business_id)->pluck('name', 'id');

			    $business_details = $this->businessUtil->getDetails($business_id);
			    $taxes = TaxRate::forBusinessDropdown($business_id, true, true);

			    $business_locations = BusinessLocation::forDropdown($business_id, false, true);
			    $bl_attributes = $business_locations['attributes'];
			    $business_locations = $business_locations['locations'];

			    $transaction = Transaction::where('business_id', $business_id)
				    ->where('type', 'sell')
				    ->findorfail($id);

			    $contacts = Contact::where('business_id', $business_id)->where('type', '!=', 'supplier')->pluck('name', 'id');

			    $currencies = Currency::where('currency_rate', '>', 0)->select('id', DB::raw("concat(country, ' - ',currency, '(', code, ') ') as info"))
				    ->orderBy('country')
				    ->pluck('info', 'id');
			    $business = Business::where('id', $business_id)->first();
			    $default_currency_id = $business->currency_id;

			    $location_id = $transaction->location_id;
			    $location_printer_type = BusinessLocation::find($location_id)->receipt_printer_type;

			    $currencies = Currency::where('currency_rate', '>', 0)->pluck('code', 'id');
			    $business = Business::where('id', $business_id)->first();
			    $default_currency_id = $business->currency_id;
			    $default_currency = Currency::findOrFail($default_currency_id);
			    $default_currency_rate = $default_currency->currency_rate;
			    $currency_details = $this->transactionUtil->purchaseCurrencyDetails($business_id);

			    $dontShowExpiredItems = false;
			    $available_product_list = PurchaseLine::LeftJoin('transactions as t', 't.id', '=', 'purchase_lines.transaction_id')
				    ->LeftJoin('products as p', 'p.id', '=', 'purchase_lines.product_id')
				    ->LeftJoin('variations as v', 'v.id', '=', 'purchase_lines.variation_id')
				    ->where('t.status', '=', 'received')
				    ->select(
					    'purchase_lines.id',
					    'p.name',
//					    'v.default_sell_price as dsp',
					    'purchase_lines.purchase_price_inc_tax as dsp',
					    DB::raw('purchase_lines.quantity - purchase_lines.quantity_sold - purchase_lines.quantity_adjusted as quantity'),
					    DB::raw('purchase_lines.exp_date as exp_date',
						    'purchase_lines.product_id as product_id',
						    'purchase_lines.variation_id as variation_id')
				    )
				    ->when($dontShowExpiredItems, function ($query, $dontShowExpiredItems) {
					    if ($dontShowExpiredItems == 'true') {
						    $sql = '(exp_date >= "' . date("Y-m-d") . '" or exp_date is NULL)';
						    return $query->whereRaw($sql);
					    }
				    })
				    ->where('t.location_id', '=', $location_id)
				    ->where('t.business_id', '=', $business_id)
				    ->orderBy('name')
				    ->get();

			    $sell_details = TransactionSellLine::
			    join(
				    'products AS p',
				    'transaction_sell_lines.product_id',
				    '=',
				    'p.id'
			    )
				    ->join(
					    'variations AS variations',
					    'transaction_sell_lines.variation_id',
					    '=',
					    'variations.id'
				    )
				    ->join(
					    'product_variations AS pv',
					    'variations.product_variation_id',
					    '=',
					    'pv.id'
				    )
				    ->leftjoin('variation_location_details AS vld', function ($join) use ($location_id) {
					    $join->on('variations.id', '=', 'vld.variation_id')
						    ->where('vld.location_id', '=', $location_id);
				    })
				    ->leftjoin('purchase_lines as pl', 'pl.id', '=', 'transaction_sell_lines.purchase_lines_id')
				    ->leftjoin('units', 'units.id', '=', 'p.unit_id')
				    ->where('transaction_sell_lines.transaction_id', $id)
				    ->select(
					    'transaction_sell_lines.purchase_lines_id as id',
					    'p.name',
					    'p.id as product_id',
					    'p.enable_stock',
					    'p.name as product_actual_name',
					    'pv.name as product_variation_name',
					    'pv.is_dummy as is_dummy',
					    'variations.name as variation_name',
					    'variations.sub_sku',
					    'p.barcode_type',
					    'p.enable_sr_no',
					    'variations.id as variation_id',
					    'units.short_name as unit',
					    'units.allow_decimal as unit_allow_decimal',
					    'transaction_sell_lines.tax_id as tax_id',
					    'transaction_sell_lines.item_tax as item_tax',
					    'transaction_sell_lines.unit_price as dsp',
					    'transaction_sell_lines.unit_price_inc_tax as sell_price_inc_tax',
					    'transaction_sell_lines.id as transaction_sell_lines_id',
					    'transaction_sell_lines.quantity as quantity_ordered',
					    'transaction_sell_lines.sell_line_note as sell_line_note',
					    'transaction_sell_lines.lot_no_line_id',
					    'transaction_sell_lines.line_discount_type',
					    'transaction_sell_lines.line_discount_amount',
					    'pl.exp_date as exp_date',
					    DB::raw('pl.quantity - pl.quantity_sold - pl.quantity_adjusted + transaction_sell_lines.quantity AS qty_available')
				    )
				    ->get();
			    if (!empty($sell_details)) {
				    foreach ($sell_details as $key => $value) {
					    $sell_details[$key]->formatted_qty_available = $this->transactionUtil->num_f($value->qty_available);

					    $lot_numbers = [];
					    if (request()->session()->get('business.enable_lot_number') == 1) {
						    $lot_number_obj = $this->transactionUtil->getLotNumbersFromVariation($value->variation_id, $business_id, $location_id);
						    foreach ($lot_number_obj as $lot_number) {
							    //If lot number is selected added ordered quantity to lot quantity available
							    if ($value->lot_no_line_id == $lot_number->purchase_line_id) {
								    $lot_number->qty_available += $value->quantity_ordered;
							    }

							    $lot_number->qty_formated = $this->transactionUtil->num_f($lot_number->qty_available);
							    $lot_numbers[] = $lot_number;
						    }
					    }
					    $sell_details[$key]->lot_numbers = $lot_numbers;
				    }
			    }

			    $commsn_agnt_setting = $business_details->sales_cmsn_agnt;
			    $commission_agent = [];
			    if ($commsn_agnt_setting == 'user') {
				    $commission_agent = User::forDropdown($business_id);
			    } elseif ($commsn_agnt_setting == 'cmsn_agnt') {
				    $commission_agent = User::saleCommissionAgentsDropdown($business_id);
			    }

			    $types = [];
			    if (auth()->user()->can('supplier.create')) {
				    $types['supplier'] = __('report.supplier');
			    }
			    if (auth()->user()->can('customer.create')) {
				    $types['customer'] = __('report.customer');
			    }
			    if (auth()->user()->can('supplier.create') && auth()->user()->can('customer.create')) {
				    $types['both'] = __('lang_v1.both_supplier_customer');
			    }
			    $customer_groups = CustomerGroup::forDropdown($business_id);

			    return view('sell.edit')
				    ->with(compact('business_details', 'business_locations', 'contacts', 'currency_details', 'banks', 'taxes', 'sell_details', 'transaction', 'commission_agent', 'types', 'customer_groups', 'currencies', 'default_currency_id', 'default_currency_rate', 'available_product_list'));
		    }
	    } catch (\Exception $e) {
		    DB::rollBack();
		    \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

		    $output = ['success' => 0,
			    'msg' => $e->getMessage()
		    ];
		    return back()->with('status', $output);
	    }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }

    /**
     * Display a listing sell drafts.
     *
     * @return \Illuminate\Http\Response
     */
    public function getDrafts()
    {
        if (!auth()->user()->can('sell.view') && !auth()->user()->can('direct_sell.access')) {
            abort(403, 'Unauthorized action.');
        }

        return view('sale_pos.draft');
    }

    /**
     * Display a listing sell quotations.
     *
     * @return \Illuminate\Http\Response
     */
    public function getQuotations()
    {
        if (!auth()->user()->can('sell.view') && !auth()->user()->can('direct_sell.access')) {
            abort(403, 'Unauthorized action.');
        }

        return view('sale_pos.quotations');
    }

    /**
     * Send the datatable response for draft or quotations.
     *
     * @return \Illuminate\Http\Response
     */
    public function getDraftDatables()
    {
        if (request()->ajax()) {
            $business_id = request()->session()->get('user.business_id');
            $is_quotation = request()->only('is_quotation', 0);

            $sells = Transaction::leftJoin('contacts', 'transactions.contact_id', '=', 'contacts.id')
                ->join(
                    'business_locations AS bl',
                    'transactions.location_id',
                    '=',
                    'bl.id'
                )
                ->where('transactions.business_id', $business_id)
                ->where('transactions.type', 'sell')
                ->where('transactions.status', 'draft')
                ->where('is_quotation', $is_quotation)
                ->select(
                    'transactions.id',
                    'transaction_date',
                    'invoice_no',
                    'contacts.name',
                    'bl.name as business_location',
                    'is_direct_sale'
                );

            $permitted_locations = auth()->user()->permitted_locations();
            if ($permitted_locations != 'all') {
                $sells->whereIn('transactions.location_id', $permitted_locations);
            }
                
            if (!empty(request()->start_date) && !empty(request()->end_date)) {
                $start = request()->start_date;
                $end =  request()->end_date;
                $sells->whereDate('transaction_date', '>=', $start)
                            ->whereDate('transaction_date', '<=', $end);
            }
            $sells->groupBy('transactions.id');

            return Datatables::of($sells)
                ->addColumn(
                    'action',
                    '<a href="#" data-href="{{action(\'SellController@show\', [$id])}}" class="btn btn-xs btn-success btn-modal" data-container=".view_modal"><i class="fa fa-external-link" aria-hidden="true"></i> @lang("messages.view")</a>
                    &nbsp;
                    @if($is_direct_sale == 1)
                        <a target="_blank" href="{{action(\'SellController@edit\', [$id])}}" class="btn btn-xs btn-primary"><i class="glyphicon glyphicon-edit"></i>  @lang("messages.edit")</a>
                    @else
                    <a target="_blank" href="{{action(\'SellPosController@edit\', [$id])}}" class="btn btn-xs btn-primary"><i class="glyphicon glyphicon-edit"></i>  @lang("messages.edit")</a>
                    @endif

                    &nbsp; 
                    <a href="#" class="print-invoice btn btn-xs btn-info" data-href="{{route(\'sell.printInvoice\', [$id])}}"><i class="fa fa-print" aria-hidden="true"></i> @lang("messages.print")</a>

                    &nbsp; <a href="{{action(\'SellPosController@destroy\', [$id])}}" class="btn btn-xs btn-danger delete-sale"><i class="fa fa-trash"></i>  @lang("messages.delete")</a>
                    '
                )
                ->removeColumn('id')
                ->editColumn('transaction_date', '{{@format_date($transaction_date)}}')
                ->setRowAttr([
                    'data-href' => function ($row) {
                        if (auth()->user()->can("sell.view")) {
                            return  action('SellController@show', [$row->id]) ;
                        } else {
                            return '';
                        }
                    }])
                ->rawColumns(['action', 'invoice_no', 'transaction_date'])
                ->make(true);
        }
    }

    public function getInventoryProducts() {
	    if (request()->ajax()) {
		    $location_id = request()->input('location_id');
		    $dontShowExpiredItems = false;
		    $business_id = request()->session()->get('user.business_id');
		    $products = PurchaseLine::LeftJoin('transactions as t', 't.id', '=', 'purchase_lines.transaction_id')
			    ->LeftJoin('products as p', 'p.id', '=', 'purchase_lines.product_id')
			    ->LeftJoin('variations as v', 'v.id', '=', 'purchase_lines.variation_id')
			    ->where('t.status', '=', 'received')
			    ->select(
			    	'purchase_lines.id',
			    	'p.name',
//				    'v.default_sell_price as dsp',
				    'purchase_lines.purchase_price_inc_tax as dsp',
				    DB::raw('purchase_lines.quantity - purchase_lines.quantity_sold - purchase_lines.quantity_adjusted as quantity'),
				    DB::raw('purchase_lines.exp_date as exp_date',
				    'purchase_lines.product_id as product_id',
				    'purchase_lines.variation_id as variation_id',
				    'purchase_lines.purchase_price'
				    )
			    )
			    ->when($dontShowExpiredItems, function ($query, $dontShowExpiredItems) {
				    if ($dontShowExpiredItems == 'true') {
					    $sql = '(exp_date >= "' . date("Y-m-d") . '" or exp_date is NULL)';
					    return $query->whereRaw($sql);
				    }
			    })
			    ->where('t.location_id', '=', $location_id)
			    ->where('t.business_id', '=', $business_id)
			    ->orderBy('name')
			    ->get();
		    $html = '<option value="">None</option>';
		    if (!empty($products)) {
		    	foreach($products as $product) if ($product->quantity > 0.0) {
		    		$html .= '<option value="' . $product->id .'">' .$product->name . ' ( $'. $product->dsp .' | '.$product->quantity. ($product->quantity > 1 ? ' units' : 'unit'). ' | EXP Date: '. ($product->exp_date != null ? $product->exp_date : ' '). ')'.'</option>';
			    }
		    }
		    echo $html;
		    exit;
	    }
    }
}
