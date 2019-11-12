@if(!session('business.enable_price_tax')) 
  @php
    $default = 0;
    $class = 'hide';
  @endphp
@else
  @php
    $default = null;
    $class = '';
  @endphp
@endif

<div class="col-sm-9"><br>
  <div class="table-responsive">
    <table class="table table-bordered add-product-price-table table-condensed {{$class}}">
        <tr>
          <th>@lang('product.default_purchase_price')</th>
          <th>@lang('product.profit_percent') @show_tooltip(__('tooltip.profit_percent'))</th>
          <th>@lang('product.default_selling_price')</th>
        </tr>
        <tr>
          <td>
            <div class="col-sm-12">
              {!! Form::label('single_dpp', 'DPP', ['class' => 'hide']) !!}

              {!! Form::text('single_dpp', $default, ['class' => 'form-control input-sm dpp input_number', 'placeholder' => 'Please insert value', 'required']); !!}
            </div>

            <div class="col-sm-12">
              {!! Form::label('single_dpp_inc_tax', trans('product.inc_of_tax') . ':*', ['class' => 'hide']) !!}
            
              {!! Form::text('single_dpp_inc_tax', $default, ['class' => 'form-control input-sm dpp_inc_tax input_number hide', 'placeholder' => 'Including Tax', 'required']); !!}
            </div>
          </td>

          <td>
            {!! Form::text('profit_percent', @num_format($profit_percent), ['class' => 'form-control input-sm input_number', 'id' => 'profit_percent', 'required']); !!}
          </td>

          <td>
            {!! Form::label('single_dsp', 'DSP', ['class' => 'hide']) !!}
            {!! Form::text('single_dsp', $default, ['class' => 'form-control input-sm dsp input_number', 'placeholder' => 'Default sell price', 'id' => 'single_dsp', 'required']); !!}

            {!! Form::text('single_dsp_inc_tax', $default, ['class' => 'form-control input-sm input_number hide', 'placeholder' => 'Including tax', 'id' => 'single_dsp_inc_tax', 'required']); !!}
          </td>
        </tr>
    </table>
    </div>
</div>