@extends('payments.layout')

@section('title', __('messages.payments.return.cancel.title'))

@section('content')
    <div class="icon-wrap icon-wrap--cancel" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.75"/>
            <path d="M9 9l6 6M15 9l-6 6" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
        </svg>
    </div>
    <h1>{{ __('messages.payments.return.cancel.title') }}</h1>
    <p class="lead">{{ __('messages.payments.return.cancel.lead') }}</p>
    <p class="copy">{{ __('messages.payments.return.cancel.body') }}</p>
    <p class="instruction">{{ __('messages.payments.return.cancel.instruction') }}</p>
@endsection
