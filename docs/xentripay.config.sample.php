<?php
/**
 * SAMPLE. Copy this file to  config/xentripay.php  on EVERY machine that runs the staff portal (your computer and the server).
 * The config/ folder is not in git, so the secret key never reaches GitHub.
 *
 * These are the same two values the RISE MOTIVE platform already uses with XentriPay.
 */
return [
    'enabled'  => true,                                  // false switches Digital Payment off everywhere
    'base_url' => 'https://merchant.test.xentripay.com',   // no slash at the end
    'api_key'  => '8b17a594613d44d99132eebaca7a1505',

    // optional (these are the defaults)
    'fallback_email'  => 'tasks.risemotive@gmail.com',       // XentriPay needs an email; used when the customer has none
    'min_amount'      => 100,                            // smallest Digital Payment, RWF
    'max_amount'      => 5000000,                        // largest Digital Payment, RWF
    'allow_airtel'    => false,                          // false = MTN MoMo numbers only (078 / 079)
    'callback_secret' => '',                             // if set, XentriPay must call digital_payment_callback.php?key=THIS
];