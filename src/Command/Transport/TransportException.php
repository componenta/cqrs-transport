<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Transport;

use RuntimeException;

/**
 * Base exception for transport operations.
 */
class TransportException extends RuntimeException {}
