/* rmjobedir.c
 *
 * Remove one Jobe run directory, running as root via sudo. Invoked by the
 * web-server user (see /etc/sudoers.d/jobe-sudoers) from LanguageTask::close()
 * in place of a blanket wildcard "rm -R" sudoers rule over /home/jobe/runs,
 * whose wildcard sudo would also let match "/", ".." and spaces - i.e. an
 * arbitrary delete as root should the web server ever be compromised.
 *
 * The single argument must be a direct child of /home/jobe/runs named
 * "jobe_<token>" (no '/', no ".." in the token), an existing real directory
 * (not a symlink), whose canonical parent is exactly /home/jobe/runs. A missing
 * target is treated as success so cleanup is idempotent. Anything else is
 * refused and nothing is deleted.
 *
 * Compiled alongside runguard by the Jobe installer, and - like runguard -
 * left owned by root and not writable by the web-server user.
 *
 * Build:  gcc -O2 -Wall -o rmjobedir rmjobedir.c
 */

#define _GNU_SOURCE
#include <errno.h>
#include <limits.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>
#include <unistd.h>

/* The Jobe run directory. Must match the path used in
 * app/Libraries/LanguageTask.php (tempnam("/home/jobe/runs", ...)).
 * Overridable at build time only to allow unprivileged testing. */
#ifndef RUNS
#define RUNS "/home/jobe/runs"
#endif

static void refuse(const char *dir, const char *why)
{
    fprintf(stderr, "rmjobedir: refusing '%s': %s\n", dir, why);
    exit(1);
}

int main(int argc, char *argv[])
{
    if (argc != 2) {
        fprintf(stderr, "usage: %s %s/jobe_XXXXXX\n", argv[0], RUNS);
        return 1;
    }
    const char *dir = argv[1];

    if (strncmp(dir, RUNS "/jobe_", strlen(RUNS "/jobe_")) != 0) {
        refuse(dir, "not a " RUNS "/jobe_* path");
    }

    const char *tail = dir + strlen(RUNS "/");
    if (strchr(tail, '/') != NULL || strstr(tail, "..") != NULL) {
        refuse(dir, "not a direct child of " RUNS);
    }

    struct stat st;
    if (lstat(dir, &st) != 0) {
        if (errno == ENOENT) {
            return 0;                       /* already gone: idempotent success */
        }
        refuse(dir, strerror(errno));
    }
    if (!S_ISDIR(st.st_mode)) {             /* a symlink fails this test too */
        refuse(dir, "not a plain directory");
    }

    char parent[PATH_MAX];
    char resolved[PATH_MAX];
    if (snprintf(parent, sizeof parent, "%s/..", dir) >= (int) sizeof parent) {
        refuse(dir, "path too long");
    }
    if (realpath(parent, resolved) == NULL) {
        refuse(dir, "cannot resolve parent");
    }
    if (strcmp(resolved, RUNS) != 0) {
        refuse(dir, "parent is not " RUNS);
    }

    execl("/bin/rm", "rm", "-rf", "--", dir, (char *) NULL);
    fprintf(stderr, "rmjobedir: exec /bin/rm failed: %s\n", strerror(errno));
    return 1;
}
