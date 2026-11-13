<?php

namespace Darvis\Mfa\Exceptions;

use LogicException;

/**
 * The package is set up in a way that cannot work, such as a mailed link
 * without a route to land on. Never swallowed: the developer has to see it.
 */
class ConfigurationException extends LogicException {}
