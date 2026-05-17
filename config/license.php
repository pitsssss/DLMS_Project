<?php

return [
  'validity_years' => (int) env('LICENSE_VALIDITY_YEARS', 10),
  'renewal_grace_days' => (int) env('LICENSE_RENEWAL_GRACE_DAYS', 90),
];
