# Running this Jobe in Docker (for testing with Moodle/CodeRunner)

This directory builds a container from **this working copy** of Jobe (so the
`scala3` branch, or any other local change, is what runs), modelled on
[JobeInABox](https://github.com/trampgeek/jobeinabox). It includes C, C++,
Python 3 (+pylint), Java, Scala 3 (with the CDS speed-up enabled), PHP,
Node.js and Pascal. Octave is off by default (it adds ~1 GB); set
`INSTALL_OCTAVE: "true"` in `docker-compose.yml` to include it.

Jobe runs behind an **HAProxy queue**:

    Moodle/CodeRunner ──> jobe (HAProxy, host port 4000) ──> jobe-worker (Jobe) × N

A plain Jobe server runs at most 16 jobs at once; extra requests wait up to
10 s, in no particular order, and then get "server overload". The queue only
lets 16 runs through to each Jobe worker at a time and holds the rest in
first-come-first-served order for up to 60 s, so bursts (a whole lab pressing
Check at once) are absorbed instead of failing. Language lists and file
uploads bypass the queue.

## 1. Build and start Jobe

    cd docker
    docker compose up -d --build

The first build takes a few minutes. Check it:

    curl http://localhost:4000/jobe/index.php/restapi/languages

which should list `scala` among the languages. `docker ps` shows both
containers as `healthy` after about 30 s. The queue's live statistics page
(queue length, runs in progress, workers up) is at http://localhost:8404/.

Run the full Jobe test suite against it (Octave tests fail unless you
enabled Octave):

    python3 ../testsubmit.py --port=4000
    python3 ../testsubmit.py --port=4000 scala      # just Scala

## 2. Connect it to the Moodle container

    ./connect-to-moodle.sh                 # finds the Moodle container itself
    ./connect-to-moodle.sh <container>     # or name it explicitly

This attaches Jobe (alias `jobe`) and the Moodle web container to a shared
Docker network, `jobe-net`, then checks from inside Moodle that
`http://jobe/jobe/index.php/restapi/languages` answers. Re-run it if you
recreate either container.

## 3. Configure Moodle

As a Moodle admin:

1. **Site administration > Plugins > Question types > CodeRunner**:
   set *Jobe server* to `jobe` (no `http://`, no port).
2. **Site administration > General > Security > HTTP security**:
   remove `172.16.0.0/12` from the *cURL blocked hosts list* (Docker's
   private address range; Moodle blocks it by default, which makes every
   CodeRunner run fail with a Jobe connection error). Port 80 is already
   allowed.

Alternative without the shared network: set *Jobe server* to
`host.docker.internal:4000`, add `4000` to *cURL allowed ports*, and unblock
the address `host.docker.internal` resolves to (on Docker Desktop for Mac,
`192.168.65.x`, inside `192.168.0.0/16`).

These security changes are fine for a local test instance; don't make them
on a production Moodle.

## Using Scala from CodeRunner

The built-in CodeRunner question types (python3, c, java, …) work straight
away. For Scala, import `../coderunner/scala3_coderunner_prototypes.xml`
(Moodle XML) into the course question bank; it adds the `scala_function` and
`scala_program` question types. `../coderunner/scala3_coderunner_examples.xml`
has an example question of each type.

## The queue

Settings are environment variables of the `jobe` service in
`docker-compose.yml`:

| Variable | Default | Meaning |
|---|---|---|
| `JOBE_SLOTS` | 16 | Runs each worker may have in progress. Keep ≤ `jobe_max_users` in `app/Config/Jobe.php`. |
| `QUEUE_TIMEOUT` | 60s | Longest a run waits in the queue. Keep it below Moodle's HTTP timeout. |
| `QUEUE_MAX` | 500 | Queue length beyond which new runs are refused at once. |

A run that waits longer than `QUEUE_TIMEOUT`, or arrives when the queue holds
`QUEUE_MAX` runs, gets Jobe's normal "server overload" result (outcome 21), so
CodeRunner shows its usual "try again shortly" message. Change a setting with
`docker compose up -d` (no rebuild needed).

**More workers:** `docker compose up -d --scale jobe-worker=3` adds Jobe
containers (up to 8) and the queue starts using them within a few seconds. They
share one file cache (the `jobe-files` volume), so support files uploaded by
CodeRunner are visible to every worker. On one Mac this only helps jobs that
wait rather than compute; for real extra capacity the workers need to be on
separate machines.

**Load test:** `python3 loadtest.py --jobs 200` sends 200 simultaneous
2-second runs and reports successes, overloads, response times and whether
they finished in submission order. Measured in testing (one worker, 16 slots):

| | Succeeded | Server overload | Slowest response | Out of order |
|---|---|---|---|---|
| Plain Jobe | 116 | 84 | 20.8 s | 21 |
| Behind the queue | 200 | 0 | 31.2 s | 0 |
| Behind the queue, 2 workers | 200 | 0 | 18.7 s | 1 |

## Rebuilding after code changes

    docker compose up -d --build

then re-run `./connect-to-moodle.sh` if the `jobe` (queue) container was
recreated. Logs: `docker logs -f jobe` for the queue (one line per request,
including how long it queued), `docker compose logs -f jobe-worker` for Jobe/Apache.
To debug a run, set `$debugging = true` in `app/Config/Jobe.php`, rebuild, and
look in `/home/jobe/runs` inside a worker
(`docker exec -it jobe-local-jobe-worker-1 bash`).

## Stopping

    docker compose down            # stop and remove the containers (keeps the file cache volume)
    docker network rm jobe-net     # optional, after disconnecting Moodle
