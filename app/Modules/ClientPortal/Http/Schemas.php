<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Http;

use OpenApi\Attributes as OA;

// Esquemas de las respuestas del portal para el OpenAPI que consume el front.
#[OA\Schema(schema: 'CodeChallenge', required: ['challenge_id', 'channel', 'destination', 'mobile', 'email', 'resend_available_at', 'expires_in'], properties: [
    new OA\Property(property: 'challenge_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'channel', type: 'string', enum: ['sms', 'email']),
    new OA\Property(property: 'destination', type: 'string', example: '***-***-7392'),
    new OA\Property(property: 'mobile', type: 'string', example: '***-***-7392'),
    new OA\Property(property: 'email', type: 'string', example: 'l*****@gmail.com', nullable: true),
    new OA\Property(property: 'resend_available_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'expires_in', type: 'integer', example: 300),
])]
#[OA\Schema(schema: 'LoginError', required: ['code', 'message'], properties: [
    new OA\Property(property: 'code', type: 'string', enum: ['invalid_identifier', 'turnstile_failed', 'incorrect_code', 'expired_code', 'locked', 'resend_too_soon', 'invalid_challenge', 'send_failed', 'no_email', 'unauthenticated', 'session_expired', 'portal_disabled']),
    new OA\Property(property: 'message', type: 'string'),
    new OA\Property(property: 'attempts_left', type: 'integer'),
    new OA\Property(property: 'until', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'ClientOption', required: ['lead_id', 'label'], properties: [
    new OA\Property(property: 'lead_id', type: 'integer'),
    new OA\Property(property: 'label', type: 'string', example: 'Luis A. · Credit Experts'),
])]
#[OA\Schema(schema: 'Reactivation', required: ['token', 'available_at'], properties: [
    new OA\Property(property: 'token', type: 'string', nullable: true),
    new OA\Property(property: 'available_at', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'SignInResult', required: ['status'], properties: [
    new OA\Property(property: 'status', type: 'string', enum: ['signed_in', 'select_client', 'no_access']),
    new OA\Property(property: 'clients', type: 'array', items: new OA\Items(ref: '#/components/schemas/ClientOption')),
    new OA\Property(property: 'reactivation', ref: '#/components/schemas/Reactivation'),
])]
#[OA\Schema(schema: 'NoAccess', required: ['code', 'message', 'reactivation'], properties: [
    new OA\Property(property: 'code', type: 'string', enum: ['no_access']),
    new OA\Property(property: 'message', type: 'string'),
    new OA\Property(property: 'reactivation', ref: '#/components/schemas/Reactivation'),
])]
#[OA\Schema(schema: 'ReactivationStatus', required: ['sent', 'available_at'], properties: [
    new OA\Property(property: 'sent', type: 'boolean'),
    new OA\Property(property: 'available_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'PortalSessionInfo', required: ['client', 'account', 'accounts', 'shared_phone', 'language'], properties: [
    new OA\Property(property: 'client', required: ['lead_id', 'first_name', 'full_name', 'initials'], properties: [
        new OA\Property(property: 'lead_id', type: 'integer'),
        new OA\Property(property: 'first_name', type: 'string'),
        new OA\Property(property: 'full_name', type: 'string'),
        new OA\Property(property: 'initials', type: 'string'),
    ], type: 'object'),
    new OA\Property(property: 'account', required: ['id', 'number'], properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'number', type: 'string', example: 'CE1009757'),
    ], type: 'object'),
    new OA\Property(property: 'accounts', type: 'array', items: new OA\Items(required: ['id', 'number'], properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'number', type: 'string'),
    ], type: 'object')),
    new OA\Property(property: 'shared_phone', type: 'boolean'),
    new OA\Property(property: 'language', type: 'string', enum: ['en', 'es'], nullable: true),
])]
#[OA\Schema(schema: 'BureauScore', required: ['score', 'delta'], properties: [
    new OA\Property(property: 'score', type: 'integer', nullable: true),
    new OA\Property(property: 'delta', type: 'integer', nullable: true),
])]
#[OA\Schema(schema: 'Home', required: ['plan', 'scores', 'payments', 'card_alert', 'files', 'appointment', 'dispute'], properties: [
    new OA\Property(property: 'plan', required: ['name', 'status', 'paid_payments', 'total_payments', 'monthly_amount'], properties: [
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'service_completed']),
        new OA\Property(property: 'paid_payments', type: 'integer'),
        new OA\Property(property: 'total_payments', type: 'integer'),
        new OA\Property(property: 'monthly_amount', type: 'number'),
    ], type: 'object', nullable: true),
    new OA\Property(property: 'scores', required: ['date', 'equifax', 'experian', 'transunion'], properties: [
        new OA\Property(property: 'date', type: 'string', format: 'date'),
        new OA\Property(property: 'equifax', ref: '#/components/schemas/BureauScore'),
        new OA\Property(property: 'experian', ref: '#/components/schemas/BureauScore'),
        new OA\Property(property: 'transunion', ref: '#/components/schemas/BureauScore'),
    ], type: 'object', nullable: true),
    new OA\Property(property: 'payments', required: ['state'], properties: [
        new OA\Property(property: 'state', type: 'string', enum: ['current', 'late', 'loyal']),
        new OA\Property(property: 'next_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'next_amount', type: 'number', nullable: true),
        new OA\Property(property: 'pending_count', type: 'integer'),
        new OA\Property(property: 'monthly_amount', type: 'number'),
        new OA\Property(property: 'amount_due', type: 'number', nullable: true),
    ], type: 'object'),
    new OA\Property(property: 'files', type: 'array', items: new OA\Items(ref: '#/components/schemas/Document')),
    new OA\Property(property: 'card_alert', required: ['state', 'expiration'], properties: [
        new OA\Property(property: 'state', type: 'string', enum: ['expired', 'expires_soon']),
        new OA\Property(property: 'expiration', type: 'string', example: '11/26'),
    ], type: 'object', nullable: true),
    new OA\Property(property: 'appointment', required: ['date', 'time', 'specialist'], properties: [
        new OA\Property(property: 'date', type: 'string', format: 'date'),
        new OA\Property(property: 'time', type: 'string', example: '16:00'),
        new OA\Property(property: 'specialist', type: 'string', example: 'Elvis R.'),
    ], type: 'object', nullable: true),
    new OA\Property(property: 'dispute', required: ['round', 'sent_at', 'respond_by', 'window_closed'], properties: [
        new OA\Property(property: 'round', type: 'integer'),
        new OA\Property(property: 'sent_at', type: 'string', format: 'date'),
        new OA\Property(property: 'respond_by', type: 'string', format: 'date'),
        new OA\Property(property: 'window_closed', type: 'boolean'),
    ], type: 'object', nullable: true),
])]
#[OA\Schema(schema: 'CreditReport', required: ['id', 'date', 'first_report', 'has_pdf', 'equifax', 'experian', 'transunion'], properties: [
    new OA\Property(property: 'id', type: 'integer'),
    new OA\Property(property: 'date', type: 'string', format: 'date'),
    new OA\Property(property: 'first_report', type: 'boolean'),
    new OA\Property(property: 'has_pdf', type: 'boolean'),
    new OA\Property(property: 'equifax', ref: '#/components/schemas/BureauScore'),
    new OA\Property(property: 'experian', ref: '#/components/schemas/BureauScore'),
    new OA\Property(property: 'transunion', ref: '#/components/schemas/BureauScore'),
])]
#[OA\Schema(schema: 'PaymentItem', required: ['transaction_id', 'date', 'concept', 'label', 'amount', 'card_last4', 'status', 'plan_payment'], properties: [
    new OA\Property(property: 'transaction_id', type: 'string'),
    new OA\Property(property: 'date', type: 'string', format: 'date'),
    new OA\Property(property: 'concept', type: 'string', enum: ['initial_payment', 'monthly_payment', 'charge', 'other']),
    new OA\Property(property: 'label', type: 'string'),
    new OA\Property(property: 'amount', type: 'number'),
    new OA\Property(property: 'card_last4', type: 'string', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['approved', 'declined', 'refunded', 'voided', 'other']),
    new OA\Property(property: 'plan_payment', type: 'integer', nullable: true),
])]
#[OA\Schema(schema: 'Payments', required: ['plan_name', 'monthly_amount', 'plan_total', 'paid', 'remaining', 'history'], properties: [
    new OA\Property(property: 'plan_name', type: 'string', nullable: true),
    new OA\Property(property: 'monthly_amount', type: 'number'),
    new OA\Property(property: 'plan_total', type: 'number'),
    new OA\Property(property: 'paid', type: 'number'),
    new OA\Property(property: 'remaining', type: 'number'),
    new OA\Property(property: 'history', type: 'array', items: new OA\Items(ref: '#/components/schemas/PaymentItem')),
])]
#[OA\Schema(schema: 'Receipt', allOf: [
    new OA\Schema(ref: '#/components/schemas/PaymentItem'),
    new OA\Schema(required: ['plan', 'client', 'account'], properties: [
        new OA\Property(property: 'plan', required: ['name', 'total_payments'], properties: [
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'total_payments', type: 'integer'),
        ], type: 'object', nullable: true),
        new OA\Property(property: 'client', type: 'string'),
        new OA\Property(property: 'account', type: 'string'),
    ]),
])]
#[OA\Schema(schema: 'Profile', required: ['full_name', 'account', 'mobile', 'email', 'address', 'birth_year', 'plan', 'specialist', 'agent'], properties: [
    new OA\Property(property: 'full_name', type: 'string'),
    new OA\Property(property: 'account', type: 'string'),
    new OA\Property(property: 'mobile', type: 'string', nullable: true),
    new OA\Property(property: 'email', type: 'string', nullable: true),
    new OA\Property(property: 'address', type: 'string', nullable: true),
    new OA\Property(property: 'birth_year', type: 'integer', nullable: true),
    new OA\Property(property: 'plan', type: 'string', nullable: true),
    new OA\Property(property: 'specialist', type: 'string', nullable: true),
    new OA\Property(property: 'agent', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'AccessSession', required: ['id', 'device', 'browser', 'is_phone', 'city', 'region', 'started_at', 'current'], properties: [
    new OA\Property(property: 'id', type: 'integer'),
    new OA\Property(property: 'device', type: 'string'),
    new OA\Property(property: 'browser', type: 'string'),
    new OA\Property(property: 'is_phone', type: 'boolean'),
    new OA\Property(property: 'city', type: 'string', nullable: true),
    new OA\Property(property: 'region', type: 'string', nullable: true),
    new OA\Property(property: 'started_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'current', type: 'boolean'),
])]
#[OA\Schema(schema: 'Card', required: ['id', 'brand', 'last4', 'expiration', 'expired', 'expires_soon', 'is_default'], properties: [
    new OA\Property(property: 'id', type: 'integer'),
    new OA\Property(property: 'brand', type: 'string', enum: ['VISA', 'MC', 'AMEX', 'DISCOVER'], nullable: true),
    new OA\Property(property: 'last4', type: 'string'),
    new OA\Property(property: 'expiration', type: 'string', example: '11/26'),
    new OA\Property(property: 'expired', type: 'boolean'),
    new OA\Property(property: 'expires_soon', type: 'boolean'),
    new OA\Property(property: 'is_default', type: 'boolean'),
])]
#[OA\Schema(schema: 'NewCard', required: ['cardholdername', 'cardnumber', 'cvc', 'exp_month', 'exp_year', 'zipcode'], properties: [
    new OA\Property(property: 'cardholdername', type: 'string'),
    new OA\Property(property: 'cardnumber', type: 'string'),
    new OA\Property(property: 'cvc', type: 'string'),
    new OA\Property(property: 'exp_month', type: 'integer'),
    new OA\Property(property: 'exp_year', type: 'integer', description: 'Two digits'),
    new OA\Property(property: 'zipcode', type: 'string'),
    new OA\Property(property: 'set_default', type: 'boolean'),
])]
#[OA\Schema(schema: 'PayNow', required: ['payable', 'amount', 'count', 'monthly_amount', 'cards', 'preselected_card_id', 'default_card_expired'], properties: [
    new OA\Property(property: 'payable', type: 'boolean'),
    new OA\Property(property: 'amount', type: 'number'),
    new OA\Property(property: 'count', type: 'integer', nullable: true),
    new OA\Property(property: 'monthly_amount', type: 'number', nullable: true),
    new OA\Property(property: 'cards', type: 'array', items: new OA\Items(ref: '#/components/schemas/Card')),
    new OA\Property(property: 'preselected_card_id', type: 'integer', nullable: true),
    new OA\Property(property: 'default_card_expired', type: 'boolean'),
])]
#[OA\Schema(schema: 'PaymentResult', required: ['status', 'amount', 'transaction_id', 'message'], properties: [
    new OA\Property(property: 'status', type: 'string', enum: ['approved', 'declined', 'error']),
    new OA\Property(property: 'amount', type: 'number'),
    new OA\Property(property: 'transaction_id', type: 'string', nullable: true),
    new OA\Property(property: 'message', type: 'string', nullable: true),
    new OA\Property(property: 'paid_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'card', required: ['brand', 'last4'], properties: [
        new OA\Property(property: 'brand', type: 'string', nullable: true),
        new OA\Property(property: 'last4', type: 'string'),
    ], type: 'object'),
])]
#[OA\Schema(schema: 'Document', required: ['type', 'status', 'uploaded_at', 'expiration', 'expires_in_days', 'reject_reason'], properties: [
    new OA\Property(property: 'type', type: 'string', enum: ['id', 'utility_bill', 'ssn']),
    new OA\Property(property: 'status', type: 'string', enum: ['approved', 'in_review', 'rejected', 'expired', 'missing']),
    new OA\Property(property: 'uploaded_at', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'expiration', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'expires_in_days', type: 'integer', nullable: true),
    new OA\Property(property: 'reject_reason', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'Letter', required: ['id', 'type', 'bureau', 'files', 'status', 'sent_at'], properties: [
    new OA\Property(property: 'id', type: 'integer'),
    new OA\Property(property: 'type', type: 'string', enum: ['bureau_response', 'court_document', 'creditor_letter', 'other']),
    new OA\Property(property: 'bureau', type: 'string', enum: ['equifax', 'experian', 'transunion'], nullable: true),
    new OA\Property(property: 'files', type: 'integer'),
    new OA\Property(property: 'status', type: 'string', enum: ['received', 'reviewed']),
    new OA\Property(property: 'sent_at', type: 'string', format: 'date-time'),
])]
final class Schemas {}
