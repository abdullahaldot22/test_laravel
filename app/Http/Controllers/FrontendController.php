<?php

namespace App\Http\Controllers;

use PDF;
use Str;
use App;
use Carbon\Carbon;
use App\Models\size;
use App\Models\color;
use App\Models\Order;
use App\Models\product;
use App\Models\category;
use App\Models\inventory;
use App\Models\thumbnail;
use App\Models\subcategory;
use App\Models\orderProduct;
use Illuminate\Http\Request;
use App\Models\billingDetails;
use PhpParser\Node\Stmt\Echo_;
use Illuminate\Support\Facades\Auth;

class FrontendController extends Controller
{
    function about() {
        return view('about');
    }

    function welcome(){
        return view('welcome');
    }

    function home() {
        $category = category::orderBy('category_name')->take(7)->get();
        $subcategory = subcategory::orderBy('subcategory_name')->take(12)->get();
        $product = product::take(8)->get();
        $top_selling_product = orderProduct::groupBy('product_id')->selectRaw('sum(quantity) as sum, product_id')->orderBy('sum', 'DESC')->get()->take(3);
        return view('frontend.index', [
            'cat'=>$category,
            'scat'=>$subcategory,
            'product'=>$product,
            'top_selling_product'=>$top_selling_product,
        ]);
    }

    function product_details($slug){
        $pro_info = product::where('slug', $slug)->get();
        $thumb = thumbnail::where('product_id', $pro_info->first()->id)->get();
        $releted_product = product::where('subcategory_id', $pro_info->first()->subcategory_id)->where('id', '!=', $pro_info->first()->id)->get();
        $in_color = inventory::where('product_id', $pro_info->first()->id)->groupBy('color_id')->selectRaw('sum(color_id) as sum, color_id')->get('sum','color_id');
        $in_size = inventory::where('product_id', $pro_info->first()->id)->groupBy('size_id')->selectRaw('sum(size_id) as sum, size_id')->get('sum','size_id');
        $review = orderProduct::where('product_id', $pro_info->first()->id)->whereNotNull('review')->get();

        $star_sum = orderProduct::where('product_id', $pro_info->first()->id)->whereNotNull('review')->sum('star');
        
        if(count($review)<1){
            $avg_star = 0;
        }
        else{
            $avg_star = $star_sum/count($review);
        }
        
        $shareComponent = \Share::page(
            url()->current(),
            $pro_info->first()->product_name,
        )
        ->facebook()
        ->twitter()
        ->linkedin()
        ->telegram()
        ->whatsapp()        
        ->reddit();
     
        return view('frontend.product.details', [
            'pro_info'=>$pro_info,
            'thumb'=>$thumb,
            'sim_pro'=>$releted_product,
            'av_color'=>$in_color,
            'av_size'=>$in_size,
            'reviews'=>$review,
            'avg_star'=>$avg_star,
            'shareComponent'=>$shareComponent,
        ]);
    }

    function getsize(Request $request){
        $str = '';
        $sizes = inventory::where('product_id', $request->pro_id)->where('color_id', $request->color_id)->get();

        foreach($sizes as $itm){
            $str .= '<div class="form-check form-option size-option  form-check-inline mb-2">
                        <input class="form-check-input sesize" value="'.$itm->size_id.'" type="radio" name="size_id" id="large'.$itm->rel_size->id.'">
                        <label class="form-option-label" for="large'.$itm->rel_size->id.'">'.$itm->rel_size->product_size.'</label>
                    </div>';
        }
        
        echo $str;
    }

    function getavQuantity(Request $request){
        $quantity = inventory::where('product_id', $request->pro_id)->where('color_id', $request->color_id)->where('size_id', $request->size_id)->get()->first()->quantity;
        $found = '';
        if($quantity == null){
            $found .= '<option value=""> - </option>';
        }
        else{
            if($quantity >= 8){
                $data = 8;
            }
            else{
                $data = $quantity;
            }
            for ($i=1; $i <= $data; $i++) { 
                $found .=  '<option value="'.$i.'">'.$i.'</option>';
            }
        }
        echo $found;
    }

    function invoice_check(){
        return view('mail.orderInvoice');
    }

    function add_product_review(Request $request, $product_id) {
        if (orderProduct::where('customer_id', Auth::guard('customerlogin')->id())->where('product_id', $product_id)->whereNotNull('review')->exists()) {
            return back();
        }
        else {
            orderProduct::where('customer_id', Auth::guard('customerlogin')->id())->where('product_id', $product_id)->first()->update([
                'review' => $request->review,
                'star' => $request->rating,
            ]);
        }
        return back();
    }

    function product_search(Request $request) {
        $data = $request->all();
        $sorting = 'created_at';
        $type = 'DESC';

        if(!empty($data['sort']) && $data['sort'] != '' && $data['sort'] != 'undefined'){
            if ($data['sort'] == 1) {
                $sorting = 'after_discount';
                $type = 'ASC';
            }
            elseif ($data['sort'] == 2) {
                $sorting = 'after_discount';
                $type = 'DESC';
            }
            elseif($data['sort'] == 3) {
                $sorting = 'product_name';
                $type = 'ASC';
            }
            elseif($data['sort'] == 4) {
                $sorting = 'product_name';
                $type = 'DESC';
            }
            else {
                $sorting = 'Created_at';
                $type = 'DESC';
            }
        }

        $products_search = product::where(function ($q) use ($data){
            $min_price = 0;
            $max_price = '';
            if(!empty($data['minprice']) && $data['minprice'] != '' && $data['minprice'] != 'undefined'){
                $min_price = $data['minprice'];
            }
            else{
                $min_price = 1;
            }
            if(!empty($data['maxprice']) && $data['maxprice'] != '' && $data['maxprice'] != 'undefined'){
                $max_price = $data['maxprice'];
            }
            else{
                $max_price = product::max('after_discount');
            }

            if(!empty($data['keyword']) && $data['keyword'] != '' && $data['keyword'] != 'undefined'){
                $q->where(function ($q) use ($data){
                    $q->where('product_name', 'like', '%'. $data['keyword'] . '%');
                    $q->orWhere('long_description', 'like', '%'. $data['keyword'] . '%');
                });
            }
            if(!empty($data['category']) && $data['category'] != '' && $data['category'] != 'undefined'){
                $q->where(function ($q) use ($data){
                    $q->where('category_id', $data['category']);
                });
            }
            if(!empty($data['minprice']) && $data['minprice'] != '' && $data['minprice'] != 'undefined' || !empty($data['maxprice']) && $data['maxprice'] != '' && $data['maxprice'] != 'undefined'){
                $q->whereBetween('after_discount', [$min_price, $max_price]);
            }
            if(!empty($data['color']) && $data['color'] != '' && $data['color'] != 'undefined' || !empty($data['size']) && $data['size'] != '' && $data['size'] != 'undefined'){
                $q->whereHas('rel_to_inventory',function ($q) use ($data){
                    if (!empty($data['color']) && $data['color'] != '' && $data['color'] != 'undefined') {
                        $q->whereHas('rel_color', function ($q) use ($data){
                            $q->where('colors.id', $data['color']);
                        });
                    }
                    if (!empty($data['size']) && $data['size'] != '' && $data['size'] != 'undefined') {
                        $q->whereHas('rel_size', function ($q) use ($data){
                            $q->where('sizes.id', $data['size']);
                        });
                    }
                });
            }
        })->orderBy($sorting, $type)->get();
        $categories = category::all();
        $colors = color::all();
        $sizes = size::all();

        return view('frontend.product.shop_search', [
            'products' => $products_search,
            'categories' => $categories,
            'colors' => $colors,
            'sizes' => $sizes,
        ]);
    }

    function order_invoice_download($order_id) {
        $order_id = '#'.$order_id;
        $billing_info = billingDetails::where('order_id', $order_id)->get();
        $product_info = orderProduct::where('order_id', $order_id)->get();
        $order_info = Order::where('order_id', $order_id)->get();
        // return Order::where('order_id', $order_id)->get();
        // return view('frontend.profile_personal.invoice_download', [
        //     'order_id' => $order_id,
        //     'billing_info'=>$billing_info,
        //     'product_info'=>$product_info ,
        //     'order_info'=>$order_info ,
        // ]);

        $pdf = PDF::loadView('frontend.profile_personal.invoice_download', [
            'order_id'=>$order_id,
            'billing_info'=>$billing_info,
            'product_info'=>$product_info ,
            'order_info'=>$order_info ,
        ]);
        return $pdf->stream('invoice.pdf');

        // $customPaper = array(0,0,567.00,283.80);

        // $pdf = PDF::loadView('frontend.profile_personal.invoice_download', [
        //     'order_id' => $order_id,
        // ]);
        // 'now' => Carbon::now(),
        // return orderProduct::where('order_id', $order_id)->get();
    }

    function change_lang(Request $request){
        App::setLocale($request->lang);
        session()->put('locale', $request->lang);
        return redirect()->back();
    }


}
