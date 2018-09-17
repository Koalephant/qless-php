# qless-php

[![Build Status](https://travis-ci.org/pdffiller/qless-php.svg?branch=master)](https://travis-ci.org/pdffiller/qless-php)
[![Code Coverage](https://codecov.io/gh/pdffiller/qless-php/branch/master/graph/badge.svg)](https://codecov.io/gh/pdffiller/qless-php)

PHP Bindings for qless.


Qless is a powerful Redis-based job queueing system inspired by [resque](https://github.com/chrisboulton/php-resque),
but built on a collection of Lua scripts, maintained in the [qless-core repo](https://github.com/seomoz/qless-core).
Be sure to check the [change log](https://github.com/pdffiller/qless-php/blob/master/CHANGELOG.md).

**NOTE:** This library is fully reworked and separately developed version of
[Contatta's qless-php](https://github.com/Contatta/qless-php). The copyright to the
[Contatta/qless-php](https://github.com/Contatta/qless-php) code belongs to [Ryver, Inc](https://ryver.com).
For more see the [Contatta/qless-php license](https://github.com/Contatta/qless-php/commit/fab97f490157581d6171b165ab9a0a9e83b69005).

Documentation is borrowed from [seomoz/qless](https://github.com/seomoz/qless).

## Contents

- [Philosophy and Nomenclature](#philosophy-and-nomenclature)
- [Features](#features)
- [Installation](#installation)
- [Usage](#usage)
  - [Enqueing Jobs](#enqueing-jobs)
  - [Running A Worker](#running-a-worker)
  - [Web Interface](#web-interface)
  - [Job Dependencies](#job-dependencies)
  - [Priority](#priority)
  - [Scheduled Jobs](#scheduled-jobs)
  - [Recurring Jobs](#recurring-jobs)
  - [Configuration Options](#configuration-options)
  - [Tagging / Tracking](#tagging--tracking)
  - [Notifications](#notifications)
  - [Heartbeating](#heartbeating)
  - [Stats](#stats)
  - [Time](#time)
  - [Ensuring Job Uniqueness](#ensuring-job-uniqueness)
  - [Setting Default Job Options](#setting-default-job-options)
  - [Testing Jobs](#testing-jobs)
- [Demo](#demo)
- [Contributing and Developing](#contributing-and-developing)
- [License](#license)

## Philosophy and Nomenclature

A `job` is a unit of work identified by a job id or `jid`. A `queue` can contain several jobs that are scheduled to be
run at a certain time, several jobs that are waiting to run, and jobs that are currently running. A `worker` is a process
on a host, identified uniquely, that asks for jobs from the queue, performs some process associated with that job, and
then marks it as complete. When it's completed, it can be put into another queue.

Jobs can only be in one queue at a time. That queue is whatever queue they were last put in. So if a worker is working
on a job, and you move it, the worker's request to complete the job will be ignored.

A job can be `canceled`, which means it disappears into the ether, and we'll never pay it any mind ever again. A job can
be `dropped`, which is when a worker fails to heartbeat or complete the job in a timely fashion, or a job can be
`failed`, which is when a host recognizes some systematically problematic state about the job. A worker should only fail
a job if the error is likely not a transient one; otherwise, that worker should just drop it and let the system reclaim it.

## Features

1. **Jobs don't get dropped on the floor** -- Sometimes workers drop jobs. Qless
  automatically picks them back up and gives them to another worker
1. **Tagging / Tracking** -- Some jobs are more interesting than others. Track those
  jobs to get updates on their progress. Tag jobs with meaningful identifiers to
  find them quickly in the UI.
1. **Job Dependencies** -- One job might need to wait for another job to complete
1. **Stats** -- `qless` automatically keeps statistics about how long jobs wait
  to be processed and how long they take to be processed. Currently, we keep
  track of the count, mean, standard deviation, and a histogram of these times.
1. **Job data is stored temporarily** -- Job info sticks around for a configurable
  amount of time so you can still look back on a job's history, data, etc.
1. **Priority** -- Jobs with the same priority get popped in the order they were
  inserted; a higher priority means that it gets popped faster
1. **Retry logic** -- Every job has a number of retries associated with it, which are
  renewed when it is put into a new queue or completed. If a job is repeatedly
  dropped, then it is presumed to be problematic, and is automatically failed.
1. **Web App** -- With the advent of a Ruby client, there is a Sinatra-based web
  app that gives you control over certain operational issues
1. **Scheduled Work** -- Until a job waits for a specified delay (defaults to 0),
  jobs cannot be popped by workers
1. **Recurring Jobs** -- Scheduling's all well and good, but we also support
  jobs that need to recur periodically.
1. **Notifications** -- Tracked jobs emit events on [pubsub](https://en.wikipedia.org/wiki/Publish%E2%80%93subscribe_pattern)
  channels as they get completed, failed, put, popped, etc. Use these events to get notified of
  progress on jobs you're interested in.

## Installation

Qless PHP can be installed via Composer:

```bash
composer require pdffiller/qless-php
```

Alternatively, install qless-php from source by checking it out from GitHub:

```bash
git clone git://github.com/pdffiller/qless-php.git
cd qless-php
composer update
```

NOTE: The `master` branch will always contain the latest _unstable_ version.
If you wish to check older versions or formal, tagged release, please switch to the tag
[release](https://github.com/pdffiller/qless-php/releases).

## Usage

### Enqueing Jobs

First things first, create a Qless Client.
The Client accepts all the same arguments that you'd use when constructing a Redis client.

```php
use Qless\Client;

// Connect to localhost
$client = new Client();

// Connect to somewhere else
$client = new Client('foo.bar.com', 1234);
```

Jobs should be classes that define a `perform` method, which must accept a single `Qless\Job` argument:

```php
use Qless\Job;

class MyJobClass
{
    /**
     * @param Job $job Is an instance of `Qless\Job` and provides access to
     *                 the payload data via `$job->getData()`, a means to cancel
     *                 the job (`$job->cancel()`), and more.
     */
    public function perform(Job $job): void
    {
        // ...
        echo 'Perform ', $job->getId(), ' job', PHP_EOL;
        
        $job->complete();
    }
}
```

Now you can access a queue, and add a job to that queue.

```php
// This references a new or existing queue 'testing'
$queue = new Qless\Queue('testing', $client);

// Let's add a job, with some data. Returns Job ID
$jid = $queue->put(MyJobClass::class, ['hello' => 'howdy']);
// $jid here is "696c752a-7060-49cd-b227-a9fcfe9f681b"

// Now we can ask for a job
$job = $queue->pop();
// $job here is an array of the Qless\Job instances

// And we can do the work associated with it!
$job->perform();
// Perform 316eb06a-30d2-4d66-ad0d-33361306a7a1 job
```

The job data must be serializable to JSON, and it is recommended that you use a hash for it.
See below for a list of the supported job options.


The argument returned by `queue->put()` is the job ID, or `jid`.
Every Qless job has a unique `jid`, and it provides a means to interact with an existing job:

```php
// find an existing job by it's jid
$job = $client->jobs[$jid];

// query it to find out details about it:
$job->jid;          // the job id
$job->klass;        // the class of the job
$job->queue;        // the queue the job is in
$job->data;         // the data for the job
$job->history;      // the history of what has happened to the job so far
$job->dependencies; // the jids of other jobs that must complete before this one
$job->dependents;   // the jids of other jobs that depend on this one
$job->priority;     // the priority of this job
$job->worker;       // the internal worker name (usually consumer identifier)
$job->tags;         // array of tags for this job
$job->expires;      // when you must either check in with a heartbeat or turn it in as completed
$job->remaining;    // the number of retries remaining for this job
$job->retries;      // the number of retries originally requested

// there is a way to get seconds remaining before this job will timeout:
$job->ttl();

// you can also change the job in various ways:
$job->requeue('some_other_queue'); // move it to a new queue
$job->cancel();                    // cancel the job
$job->tag('foo');                  // add a tag
$job->untag('foo');                // remove a tag
```

### Running A Worker

The Qless PHP worker was heavily inspired by [Resque](https://github.com/chrisboulton/php-resque)'s worker, but thanks
to the power of the qless-core lua scripts, it is much simpler and you are welcome to write your own (e.g. if you'd
rather save memory by not forking the worker for each job).

As with resque...

- The worker forks a child process for each job in order to provide resilience against memory leaks
  (Pass the `RUN_AS_SINGLE_PROCESS` environment variable to force Qless to not fork the child process.
  Single process mode should only be used in some test/dev environments.)
- The worker updates its procline with its status so you can see what workers are doing using `ps`
- The worker registers signal handlers so that you can control it by sending it signals
- The worker is given a list of queues to pop jobs off of
- The worker logs out put based on setting of the `Psr\Log\LoggerInterface` instance passed to worker

Resque uses queues for its notion of priority. In contrast, qless has priority support built-in.
Thus, the worker supports two strategies for what order to pop jobs off the queues: ordered and round-robin.
The ordered reserver will keep popping jobs off the first queue until it is empty, before trying to pop job off the
second queue. The [round-robin](https://en.wikipedia.org/wiki/Round-robin_scheduling) reserver will pop a job off
the first queue, then the second queue, and so on. You could also easily implement your own.

To start a worker, write a bit of PHP code that instantiates a worker and runs it.
You could write a simple script to do this, for example:

```php
// The autoloader line is omitted

use Qless\Client;
use Qless\Jobs\Reservers\OrderedReserver;
use Qless\Queue;
use Qless\Workers\ForkingWorker;

// Create a client
$client = new Client('foo.bar.com', 1234);

// Get the queues you use
$queues = array_map(function (string $name) use ($client) {
    return new Queue($name, $client);
}, ['testing', 'testing-2', 'testing-3']);

// Create a job reserver; different reservers use different
// strategies for which order jobs are popped off of queues
$reserver = new OrderedReserver($queues);

$worker = new ForkingWorker($reserver, $client);
$worker->run();
```

The following POSIX-compliant signals are supported in the parent process:

- `TERM`: Shutdown immediately, stop processing jobs
- `INT`:  Shutdown immediately, stop processing jobs
- `QUIT`: Shutdown after the current job has finished processing
- `USR1`: Kill the forked child immediately, continue processing jobs
- `USR2`: Don't process any new jobs, and dump the current backtrace
- `CONT`: Start processing jobs again after a `USR2`

_For detailed info regarding the signals refer to [`signal(7)`](http://man7.org/linux/man-pages/man7/signal.7.html)._

You should send these to the master process, not the child.

The child process supports the `USR2` signal, which causes it to dump its current backtrace.

Workers also support middleware modules that can be used to inject logic before, after or around the processing of a
single job in the child process. This can be useful, for example, when you need to re-establish a connection to your
database in each job.

**`@todo`**

### Web Interface

**`@todo`**

### Job Dependencies

**`@todo`**

### Priority

**`@todo`**

### Scheduled Jobs

**`@todo`**

### Recurring Jobs

**`@todo`**

### Configuration Options

**`@todo`**

### Tagging / Tracking

**`@todo`**

### Notifications

**`@todo`**

### Heartbeating

**`@todo`**

### Stats

**`@todo`**

### Time

**`@todo`**

### Ensuring Job Uniqueness

**`@todo`**

### Setting Default Job Options

**`@todo`**

### Testing Jobs

**`@todo`**

## Demo

See the [`./demo/`](https://github.com/pdffiller/qless-php/tree/master/demo) directory contents for a simple example.

## Contributing and Developing

Please see [CONTRIBUTING.md](https://github.com/pdffiller/qless-php/blob/master/CONTRIBUTING.md).

## License

qless-php is open-sourced software licensed under the MIT License.
See the [`LICENSE.txt`](https://github.com/pdffiller/qless-php/blob/master/LICENSE.txt) file for more.


© 2018 PDFfiller<br>
© 2013-2015 Ryver, Inc <br>

All rights reserved.
