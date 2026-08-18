@extends('payments.layout')

@section('title', __('messages.payments.return.success.title'))

@section('content')
    <div class="icon-wrap icon-wrap--success" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.75"/>
            <path d="M7.5 12.5l3 3 6-6.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </div>
    <h1>{{ __('messages.payments.return.success.title') }}</h1>
    <p class="lead">{{ __('messages.payments.return.success.lead') }}</p>
    <p class="copy">{{ __('messages.payments.return.success.body') }}</p>
    <p class="instruction">{{ __('messages.payments.return.return_to_app') }}</p>
@endsection
