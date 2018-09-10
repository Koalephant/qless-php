<?php

namespace Qless\Tests;

use Qless\Config;
use Qless\Events\Subscriber;
use Qless\Jobs;
use Qless\LuaScript;
use Qless\Queue;
use Qless\Tests\Support\RedisAwareTrait;
use Redis;

/**
 * Qless\Tests\ClientTest
 *
 * @package Qless\Tests
 */
class ClientTest extends QlessTestCase
{
    use RedisAwareTrait;

    /** @test */
    public function shouldCreateASubscriber()
    {
        $this->assertInstanceOf(Subscriber::class, $this->client->createSubscriber([]));
    }

    /**
     * @test
     * @dataProvider inaccessiblePropertyDataProvider
     *
     * @param string $property
     * @param $expected
     */
    public function shouldReturnExpectedValueOnMagicGet(string $property, $expected)
    {
        if ($expected === null) {
            $this->assertSame($expected, $this->client->{$property});
        } else {
            $this->assertInstanceOf($expected, $this->client->{$property});
        }
    }

    public function inaccessiblePropertyDataProvider()
    {
        return [
            ['foo',    null],
            ['job',    null],
            ['jobs',   Jobs::class],
            ['config', Config::class],
            ['lua',    LuaScript::class],
            ['redis',  Redis::class],
        ];
    }

    /**
     * @test
     * @dataProvider popDataProvider
     *
     * @param string $qName
     * @param string $jName
     * @param string $wName
     * @param string $cName
     * @param int    $expires
     * @param array  $data
     *
     * @throws \Qless\Exceptions\QlessException;
     */
    public function shouldGetTheNextJobOnTheDesiredQueue(
        string $qName,
        string $jName,
        string $wName,
        string $cName,
        int $expires,
        array $data
    ) {
        $queue = new Queue($qName, $this->client);
        $queue->put($cName, $jName, $data);

        $actual = $this->client->pop($qName, $wName, 1);

        $this->assertTrue(is_string($actual));
        $this->assertNotEmpty($actual);

        $actual = json_decode($actual, true);

        $this->assertTrue(is_array($actual));
        $this->assertCount(1, $actual);

        $actual = $actual[0];

        $this->assertArrayHasKey('expires', $actual);

        $this->assertGreaterThan($expires, $actual['expires']);
        $this->assertLessThan($expires + 2, $actual['expires']);

        $heartbeat = 60;
        $realDate  = intval($actual['expires'] - $heartbeat);

        $actual['expires'] = $expires;

        $this->assertEquals(
            $this->getExpectedJob($jName, $cName, $qName, $wName, $expires, $realDate, $data),
            [$actual]
        );
    }

    /** @test */
    public function shouldReturnEmptyStringWhenJobDoesNotExist()
    {
        $this->assertEquals('{}', $this->client->pop('non-existent-queue', 'worker-1', 1));
    }

    public function popDataProvider()
    {
        return [
            [
                'test-queue-' . mt_rand(1, 99),
                'job-' . mt_rand(1, 99),
                'worker-' . mt_rand(1, 99),
                'Xxx\Yyy',
                time() + 60,
                ['performMethod' => 'myPerformMethod', 'payload' => 'message-' . mt_rand(1, 99)],
            ]
        ];
    }

    /** @test */
    public function shouldReconnect()
    {
        $rcClient = new \ReflectionObject($this->client);

        $rcLua = $rcClient->getProperty('lua');
        $rcLua->setAccessible(true);

        $lua = $rcLua->getValue($this->client);

        $rcLua = new \ReflectionObject($lua);

        $rcRedis = $rcLua->getProperty('redis');
        $rcRedis->setAccessible(true);

        /** @var \Redis $redis */
        $redis = $rcRedis->getValue($lua);

        $this->assertSame('+PONG', $redis->ping());

        $redis->close();
        $this->client->reconnect();

        $this->assertSame('+PONG', $redis->ping());
    }

    protected function getExpectedJob(
        string $jName,
        string $cName,
        string $qName,
        string $wName,
        int $expires,
        int $realDate,
        array $data
    ) {
        return [
            [
                'jid'          => $jName,
                'retries'      => 5,
                'data'         => json_encode($data),
                'failure'      => [],
                'expires'      => $expires,
                'remaining'    => 5,
                'interval'     => 0,
                'klass'        => $cName,
                'tracked'      => false,
                'tags'         => [],
                'queue'        => $qName,
                'state'        => 'running',
                'history'      => [
                    [
                        'when'   => $realDate,
                        'q'      => $qName,
                        'what'   => 'put',
                    ],
                    [
                        'when'   => $realDate,
                        'what'   => 'popped',
                        'worker' => $wName,
                    ],
                ],
                'dependencies' => [],
                'dependents'   => [],
                'result_data'  => [],
                'resources'    => [],
                'priority'     => 0,
                'worker'       => $wName,

            ]
        ];
    }

    /** @test */
    public function shouldRetrieveStats()
    {
        $queue = new Queue('some-queue', $this->client);
        $queue->put('Xxx\Yyy', 'job-42', ['some-data']);

        $stats = $this->client->stats('some-queue', time());

        $this->assertNotEmpty($stats);
        $this->assertTrue(is_string($stats));

        $stats = json_decode($stats, true);

        $this->assertArrayHasKey('failed', $stats);
        $this->assertArrayHasKey('retries', $stats);
        $this->assertArrayHasKey('failures', $stats);
        $this->assertArrayHasKey('wait', $stats);
        $this->assertArrayHasKey('run', $stats);

        $this->assertCount(5, $stats);

        $this->assertTrue(is_array($stats['wait']));
        $this->assertCount(4, $stats['wait']);

        $this->assertTrue(is_array($stats['run']));
        $this->assertCount(4, $stats['run']);
    }

    /** @test */
    public function shouldPauseTheQueue()
    {
        $queue = new Queue('some-queue', $this->client);
        $queue->put('Xxx\Yyy', 'job-42', ['some-data']);

        $this->assertTrue($this->client->paused('some-queue') === false);

        $this->client->pause('some-queue');
        $this->assertTrue($this->client->paused('some-queue') === 1);

        $this->client->unpause('some-queue');
        $this->assertTrue($this->client->paused('some-queue') === false);
    }

    /** @test */
    public function shouldGetJob()
    {
        $queue = new Queue('some-queue', $this->client);
        $queue->put('Xxx\Yyy', 'job-42', ['some-data']);

        $actual = $this->client->get('job-42');

        $this->assertNotEmpty($actual);
        $this->assertJson($actual);

        $this->assertFalse($this->client->get('job-43'));
    }

    /** @test */
    public function shouldCorrectDetermineLength()
    {
        $queue = new Queue('some-queue-2', $this->client);

        $this->assertEquals(0, $this->client->length('some-queue-2'));

        $queue->put('Xxx\Yyy', 'job-42', ['some-data']);
        $this->assertEquals(1, $this->client->length('some-queue-2'));

        $job = $queue->pop('worker-1');
        $job[0]->complete();

        $this->assertEquals(0, $this->client->length('some-queue-2'));

        $this->assertEquals(0, $this->client->length('some-queue-3'));
    }
}
