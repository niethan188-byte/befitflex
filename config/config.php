<?php
declare(strict_types=1);

const APP_NAME     = 'Be Fit Flex Gym';
const APP_TAGLINE  = 'Laguna';
const APP_EMAIL    = 'befitgymlaguna@gmail.com';
const APP_FB       = 'Be Fit Flex Gym';
const APP_IG       = 'befitflexgymlaguna';
const APP_BRANCHES = [
    'Brgy. Dita, City of Santa Rosa',
    'Brgy. Marinig, City of Cabuyao',
];
const APP_OFFERS = [
    'Personal Training',
    'Physique / Body Building',
    'Weight loss / Weight gain',
    'Fat Loss program',
    'Functional training',
    'Conditioning',
];

/* Set BEFITFLEX_API_SECRET in the environment; never use a source-code secret. */
const MOBILE_API_SECRET = '';

const MAYA_API_BASE_URL = 'https://pg-sandbox.paymaya.com';
const MAYA_CHECKOUT_URL = 'https://pg-sandbox.paymaya.com/checkout/v1/checkouts';

/** Membership pricing used when generating dues. */
const MEMBERSHIP_PRICE = [
    'Monthly'   => 1500.00,
    'Quarterly' => 4200.00,
    'Annual'    => 12000.00,
];

const DAY_PASS_PRICE = 300.00;

date_default_timezone_set('Asia/Manila');
