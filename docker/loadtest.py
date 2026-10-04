#!/usr/bin/env python3
"""Fire a burst of simultaneous runs at a Jobe server and summarise what happened.

Each job is a Python program that sleeps for --job-secs seconds (so it holds a
Jobe worker slot without needing much CPU). Typical use, from docker/:

    python3 loadtest.py --port 4000 --jobs 200        # through the HAProxy queue

Reports how many runs succeeded and how many got "server overload" (outcome 21),
plus the spread of response times, and whether runs finished roughly in the
order they were submitted (first-come-first-served).
"""
import argparse, json, statistics, threading, time, urllib.request

def main():
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument('--host', default='localhost')
    p.add_argument('--port', type=int, default=4000)
    p.add_argument('--jobs', type=int, default=200, help='number of runs in the burst')
    p.add_argument('--job-secs', type=float, default=2.0, help='how long each run takes')
    p.add_argument('--stagger-ms', type=float, default=5, help='gap between submissions')
    args = p.parse_args()

    url = f'http://{args.host}:{args.port}/jobe/index.php/restapi/runs'
    code = f'import time\ntime.sleep({args.job_secs})\nprint("done")\n'
    body = json.dumps({'run_spec': {'language_id': 'python3', 'sourcecode': code,
                                    'parameters': {'cputime': 10}}}).encode()
    results = [None] * args.jobs

    def worker(i):
        t0 = time.time()
        try:
            req = urllib.request.Request(url, body, {'Content-type': 'application/json; charset=utf-8'})
            with urllib.request.urlopen(req, timeout=600) as resp:
                outcome = json.loads(resp.read()).get('outcome')
        except Exception as e:
            outcome = f'error: {e}'
        results[i] = (outcome, t0, time.time())

    threads = []
    start = time.time()
    for i in range(args.jobs):
        t = threading.Thread(target=worker, args=(i,))
        t.start()
        threads.append(t)
        time.sleep(args.stagger_ms / 1000)
    for t in threads:
        t.join()
    total = time.time() - start

    ok = [r for r in results if r[0] == 15]
    overload = [r for r in results if r[0] == 21]
    other = [r for r in results if r[0] not in (15, 21)]
    lat = sorted(r[2] - r[1] for r in ok)
    print(f'{args.jobs} runs of {args.job_secs}s against {url}')
    print(f'  succeeded: {len(ok)}   server overload: {len(overload)}   other: {len(other)}')
    if other:
        print(f'  e.g. {other[0][0]}')
    if lat:
        print(f'  response time of successful runs: min {lat[0]:.1f}s  median {statistics.median(lat):.1f}s  max {lat[-1]:.1f}s')
    if overload:
        ol = sorted(r[2] - r[1] for r in overload)
        print(f'  overloaded runs gave up after: min {ol[0]:.1f}s  max {ol[-1]:.1f}s')
    # FCFS check: of the successful runs, how often did a later submission finish
    # well (>1 job length) before an earlier one?
    done = sorted(ok, key=lambda r: r[1])
    inversions = sum(1 for a, b in zip(done, done[1:]) if b[2] < a[2] - args.job_secs)
    print(f'  out-of-order finishes (later submission done >{args.job_secs}s before the previous one): {inversions}')
    print(f'  whole burst took {total:.1f}s')

if __name__ == '__main__':
    main()
