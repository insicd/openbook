<?php

namespace App\Infrastructure\Security\Http;

use RuntimeException;

/**
 * Sollevata quando un URL remoto (o una sua ridirezione) punta, direttamente
 * o dopo risoluzione DNS, verso una destinazione non affidabile: schema non
 * consentito, host privato/locale/riservato, o troppe ridirezioni.
 */
final class SsrfViolationException extends RuntimeException {}
