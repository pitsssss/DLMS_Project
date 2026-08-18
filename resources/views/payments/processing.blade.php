@extends('payments.layout')

@php
    $variant = $variant ?? 'confirming';
    $titleKey = match ($variant) {
        'verifying' => 'messages.payments.return.verifying.title',
        'inconclusive' => 'messages.payments.return.inconclusive.title',
        default => 'messages.payments.return.processing.title',
    };
    $leadKey = match ($variant) {
        'verifying' => 'messages.payments.return.verifying.lead',
        'inconclusive' => 'messages.payments.return.inconclusive.lead',
        default => 'messages.payments.return.processing.lead',
    };
    $bodyKey = match ($variant) {
        'verifying' => 'messages.payments.return.verifying.body',
        'inconclusive' => 'messages.payments.return.inconclusive.body',
        default => 'messages.payments.return.processing.body',
    };
@endphp

@section('title', __($titleKey))

@section('content')
    <div class="icon-wrap icon-wrap--processing" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.75"/>
            <path d="M12 7v5l3 2" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </div>
    <h1>{{ __($titleKey) }}</h1>
    <p class="lead">{{ __($leadKey) }}</p>
    <p class="copy">{{ __($bodyKey) }}</p>
    <p class="instruction">{{ __('messages.payments.return.return_to_app') }}</p>
@endsection
