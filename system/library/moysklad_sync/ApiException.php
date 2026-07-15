<?php
namespace MoyskladSync;

/**
 * @author d_dyuk
 */

/**
 * Исключение для ошибок API МойСклад.
 *
 * Держим его отдельно от стандартного \Exception, чтобы в контроллерах и сервисах
 * можно было отличать ожидаемые ошибки внешнего API от внутренних ошибок модуля.
 */
class ApiException extends \Exception {
    private int $httpStatus;
    private ?string $apiCode;
    private array $details;

    public function __construct(string $message, int $httpStatus = 0, ?string $apiCode = null, array $details = []) {
        parent::__construct($message, $httpStatus);

        $this->httpStatus = $httpStatus;
        $this->apiCode = $apiCode;
        $this->details = $details;
    }

    public function getHttpStatus(): int {
        return $this->httpStatus;
    }

    public function getApiCode(): ?string {
        return $this->apiCode;
    }

    public function getDetails(): array {
        return $this->details;
    }
}
