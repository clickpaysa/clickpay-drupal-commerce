<?php

namespace Drupal\clickpay_drupal_commerce\Session;

use Drupal\Core\Session\SessionConfiguration;
use Symfony\Component\HttpFoundation\Request;

class CookieSameSiteSessionConfiguration extends SessionConfiguration
{
  /**
   * {@inheritdoc}
   */
  public function getOptions(Request $request)
  {
    $options = parent::getOptions($request);

    // Set the cookie samesite option to None.
    $options['cookie_samesite'] = 'None';

    return $options;
  }
}
