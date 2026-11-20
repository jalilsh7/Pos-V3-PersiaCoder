<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Http;

use Throwable;

final readonly class Kernel
{
    public function __construct(private Router $router) {}

    public function handle(Request $request): Response
    {
        try {
            $response = $this->router->dispatch($request);
        } catch (HttpException $exception) {
            $response = Response::error([
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
                'details' => (object) $exception->details,
                'request_id' => $request->requestId,
            ], $exception->status);
        } catch (Throwable $exception) {
            error_log(sprintf(
                'POS_V3 request_id=%s exception=%s',
                $request->requestId,
                $exception::class,
            ));
            $response = Response::error([
                'code' => 'internal_error',
                'message' => 'An unexpected error occurred.',
                'request_id' => $request->requestId,
            ], 500);
        }

        return $response->withHeader('X-Request-ID', $request->requestId);
    }
}
