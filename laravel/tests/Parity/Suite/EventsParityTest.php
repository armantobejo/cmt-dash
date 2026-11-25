<?php

namespace Tests\Parity\Suite;

use App\Listeners\LogRequestCompleted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;

/**
 * Event parity: the app's only domain event surface is Laravel's RequestHandled,
 * which drives the Python-parity structured access log
 * (`event=request, request_id, method, path, status, duration_ms, username`).
 */
class EventsParityTest extends ParitySuiteTestCase
{
    private string $accessLog;

    protected function setUp(): void
    {
        parent::setUp();

        // Redirect the cmt.access channel into the test fixture dir so the
        // listener's real JSON line can be asserted instead of mocked. The
        // channel key itself contains a dot, so replace the whole channels map.
        $this->accessLog = $this->dataDir . '/access.log';
        $channels = (array) config('logging.channels', []);
        $channels['cmt.access'] = [
            'driver' => 'monolog',
            'name' => 'cmt.access',
            'level' => 'debug',
            'handler' => StreamHandler::class,
            'handler_with' => ['stream' => $this->accessLog],
            'formatter' => JsonFormatter::class,
        ];
        config(['logging.channels' => $channels]);
    }

    public function test_request_handled_listener_is_registered(): void
    {
        $listeners = array_map(
            fn ($l) => is_string($l) ? $l : (is_object($l) ? get_class($l) : gettype($l)),
            Event::getRawListeners()[RequestHandled::class] ?? []
        );

        $this->assertContains(LogRequestCompleted::class, $listeners);

        $this->snapshotCase('events', 'listener_wiring', [
            'listener_class' => LogRequestCompleted::class,
            'registered_on_framework_event' => true,
            'listeners' => $listeners,
        ]);
    }

    public function test_request_through_kernel_dispatches_request_handled(): void
    {
        Event::fake([RequestHandled::class]);

        $this->guest()->get('/api/health')->assertOk();

        Event::assertDispatched(RequestHandled::class);
    }

    public function test_listener_writes_structured_access_log_line(): void
    {
        $request = \Illuminate\Http\Request::create('/api/health', 'GET');
        $request->attributes->set('cmt_request_id', 'abcdef0123456789');
        $request->attributes->set('cmt_request_start', microtime(true) - 0.05);
        $response = response()->json(['status' => 'ok']);

        (new LogRequestCompleted($this->auth()))->handle(new RequestHandled($request, $response));

        $this->assertFileExists($this->accessLog);
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->accessLog))));
        $this->assertNotEmpty($lines);
        $entry = json_decode(end($lines), true);

        $this->assertIsArray($entry, 'access log line is JSON');
        $this->assertSame('request', $entry['message']);
        $this->assertSame('request', $entry['context']['event']);
        $this->assertSame('GET', $entry['context']['method']);
        $this->assertSame('api/health', $entry['context']['path']);
        $this->assertSame(200, $entry['context']['status']);
        $this->assertSame('abcdef0123456789', $entry['context']['request_id']);
        $this->assertSame('', $entry['context']['username'], 'anonymous requests log an empty username');

        $this->snapshotCase('events', 'access_log_keys', [
            'level_name' => $entry['level_name'],
            'message' => $entry['message'],
            'context_keys' => array_keys($entry['context']),
        ]);
    }

    public function test_listener_logs_the_authenticated_username(): void
    {
        $token = $this->tokenFor('management');
        $request = \Illuminate\Http\Request::create('/ledger', 'GET');
        $request->cookies->set('cmt_session', $token);
        $response = response('ok', 200);
        (new LogRequestCompleted($this->auth()))->handle(new RequestHandled($request, $response));

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->accessLog))));
        $entry = json_decode(end($lines), true);

        $this->assertSame('manager1', $entry['context']['username']);
    }

    public function test_listener_logs_real_status_for_not_found(): void
    {
        $request = \Illuminate\Http\Request::create('/nonexistent-page', 'GET');
        $response = response('not found', 404);

        (new LogRequestCompleted($this->auth()))->handle(new RequestHandled($request, $response));

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->accessLog))));
        $entry = json_decode(end($lines), true);

        $this->assertSame(404, $entry['context']['status'], '404s log their real status, not a 500 placeholder');
    }
}
