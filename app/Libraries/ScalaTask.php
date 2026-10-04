<?php

/* ==============================================================
 *
 * Scala 3
 *
 * ==============================================================
 *
 * Compiles with the Scala 3 compiler (dotty.tools.dotc.Main) and runs the
 * result on the JVM, in both cases invoking /usr/bin/java directly rather
 * than via the scalac/scala launcher scripts. That gives control over JVM
 * flags, avoids an extra JVM start-up per run and works the same way for
 * every Scala 3 distribution layout (3.3 LTS and the scala-cli based 3.5+).
 *
 * The Scala distribution is expected in the directory given by the
 * scala_home configuration parameter in app/Config/Jobe.php (default
 * /usr/local/scala3), with the compiler and library jars in its lib
 * subdirectory. If it isn't there, the version check fails and the
 * language is simply not listed by the server.
 *
 * Compilation is CPU-hungry (a cold JVM running scalac takes a few CPU
 * seconds for even a trivial program), so the compile-time CPU minimum is
 * raised. Start-up can be roughly halved by building a class data sharing
 * (CDS) archive for the compiler; see the scala_cds_archive config
 * parameter and the command "php spark jobe:scalacds".
 *
 * @copyright  2014, 2026 Richard Lobb, University of Canterbury
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Jobe;

class ScalaTask extends LanguageTask
{
    const DEFAULT_SCALA_HOME = '/usr/local/scala3';
    const JAVA = '/usr/bin/java';

    // Printed to stdout by the compile command iff the compiler exits with
    // status 0. Scala reports warnings on stderr even when compilation
    // succeeds, so stderr alone can't be used to detect failure.
    const COMPILE_OK_MARKER = 'JOBE_SCALAC_COMPILED_OK';

    // JVM options for running the compiler. C1-only JIT plus the serial GC
    // roughly halves the CPU time of a short compilation.
    const COMPILER_JVM_ARGS = [
        '-Xss8m',
        '-Xmx768m',
        '-XX:TieredStopAtLevel=1',
        '-XX:+UseSerialGC',
        '-Xlog:cds=off',          // CDS warnings would otherwise appear on stdout.
        '-Xlog:cds+dynamic=off',
    ];

    public string $mainClassName = '';

    public function __construct($filename, $input, $params)
    {
        $params['memorylimit'] = 0;    // As for Java: let the JVM manage memory via -Xmx.
        $this->default_params['numprocs'] = 256;    // The JVM needs lots of threads.
        $this->default_params['interpreterargs'] = [
            "-Xrs",    // Reduce signal use (avoids JVM debug output on timeout kill).
            "-Xss8m",
            "-Xmx200m"
        ];
        $this->default_params['main_class'] = null;

        $extraflags = config('Jobe')->scala_extraflags ?? '';
        if ($extraflags !== '') {
            $this->default_params['interpreterargs'][] = $extraflags;
        }

        if (isset($params['numprocs']) && $params['numprocs'] < 256) {
            $params['numprocs'] = 256;
        }

        // scalac on a cold JVM needs several CPU seconds, more when the
        // server is busy, so don't let a small run cputime starve compilation.
        $this->min_params_compile['cputime'] = 20;

        parent::__construct($filename, $input, $params);
    }


    // ************************************************
    //   Paths and class paths
    // ************************************************

    public static function scalaHome()
    {
        $home = config('Jobe')->scala_home ?? '';
        return rtrim($home !== '' ? $home : self::DEFAULT_SCALA_HOME, '/');
    }

    // The class path for running the compiler: every jar in the distribution.
    // A JVM wildcard is used (rather than an expanded list) so that exactly the
    // same string can be used when building the optional CDS archive.
    public static function compilerClasspath()
    {
        return self::scalaHome() . '/lib/*';
    }

    // The jars needed at run time (and on the compiler's user class path):
    // the Scala 3 library plus the Scala 2.13 library it builds on.
    public static function libraryJars()
    {
        $jars = [];
        foreach (['scala3-library_3-*.jar', 'scala-library-*.jar'] as $pattern) {
            $matches = glob(self::scalaHome() . '/lib/' . $pattern);
            if ($matches) {
                $jars[] = end($matches);  // glob sorts, so take the highest version.
            }
        }
        return $jars;
    }

    public static function runtimeClasspath()
    {
        return implode(':', array_merge(['.'], self::libraryJars()));
    }

    // The JVM options for the compiler, including the CDS archive if one has
    // been configured and is readable. If the archive doesn't match the
    // current JDK/class path the JVM silently ignores it.
    public static function compilerJvmArgs()
    {
        $args = array_merge(self::COMPILER_JVM_ARGS, self::unsafeAccessArgs());
        $archive = config('Jobe')->scala_cds_archive ?? '';
        if ($archive !== '' && is_readable($archive)) {
            $args[] = '-XX:SharedArchiveFile=' . $archive;
        }
        $extra = config('Jobe')->scalac_jvmflags ?? '';
        if ($extra !== '') {
            $args = array_merge($args, preg_split('/\s+/', trim($extra)));
        }
        return $args;
    }

    /**
     * JVM options needed to stop Java 24+ printing warnings on stderr whenever
     * sun.misc.Unsafe's memory-access methods are used. The Scala 3.3 compiler
     * uses them, and so does the Scala runtime for every lazy val, so without
     * this any program with a lazy val would be reported as a runtime error.
     * The option only exists from Java 23; older JVMs refuse to start if given
     * it, hence the version check.
     */
    public static function unsafeAccessArgs()
    {
        return self::javaFeatureVersion() >= 23 ? ['--sun-misc-unsafe-memory-access=allow'] : [];
    }

    /**
     * The feature (major) version of the JVM at self::JAVA, e.g. 21 or 25, or 0
     * if it can't be determined. Read from the JDK's "release" file where
     * possible (no process start), falling back to "java -version". Cached per
     * request.
     */
    public static function javaFeatureVersion()
    {
        static $version = null;
        if ($version !== null) {
            return $version;
        }
        $text = '';
        $java = realpath(self::JAVA);
        if ($java !== false) {
            $release = dirname(dirname($java)) . '/release';
            if (is_readable($release)) {
                $text = (string) file_get_contents($release);
            }
        }
        if (!preg_match('/JAVA_VERSION="?([0-9][0-9._]*)/', $text, $m)) {
            $output = [];
            exec(escapeshellarg(self::JAVA) . ' -version 2>&1', $output);
            preg_match('/version "?([0-9][0-9._]*)/', implode("\n", $output), $m);
        }
        $parts = explode('.', $m[1] ?? '0');
        // Java 8 and earlier report "1.8.0_x".
        $version = (int) ($parts[0] === '1' && isset($parts[1]) ? $parts[1] : $parts[0]);
        return $version;
    }

    // The shell command (without the source file) that runs the compiler.
    public static function compilerCommand($compileArgs = [])
    {
        $bits = array_merge(
            [self::JAVA],
            array_map('escapeshellarg', self::compilerJvmArgs()),
            ['-cp', escapeshellarg(self::compilerClasspath()), 'dotty.tools.dotc.Main'],
            ['-color:never', '-d', '.', '-classpath', escapeshellarg(self::runtimeClasspath())],
            $compileArgs
        );
        return implode(' ', $bits);
    }


    // ************************************************
    //   LanguageTask methods
    // ************************************************

    public static function getVersionCommand()
    {
        $cp = escapeshellarg(self::compilerClasspath());
        return [self::JAVA . " -cp $cp dotty.tools.dotc.Main -version", '/version ([0-9][0-9.]*)/'];
    }

    public function compile()
    {
        $compileArgs = $this->getParam('compileargs');
        $cmd = self::compilerCommand($compileArgs) . ' ' . escapeshellarg($this->sourceFileName) .
            ' && echo ' . self::COMPILE_OK_MARKER;
        list($output, $stderr) = $this->runInSandbox($cmd);

        if (strpos($output, self::COMPILE_OK_MARKER) === false) {
            $stderr = trim(self::stripAnsi($stderr));
            $this->cmpinfo = $stderr !== '' ? $stderr :
                'Scala compilation failed (possibly a time or memory limit was exceeded)';
            return;
        }

        // Compilation succeeded. Any warnings are discarded, as with other
        // compiled languages. Use -Werror in compileargs to make them fatal.
        $this->executableFileName = $this->sourceFileName;
        $main = $this->getParam('main_class') ?? self::findMainClass(file_get_contents($this->sourceFileName));
        if ($main === null) {
            $this->cmpinfo = "Can't determine the main class. Use a single @main method or an " .
                "object with a main(args: Array[String]) method, or set the main_class parameter.";
        } elseif (!preg_match('/^[\w$]+(\.[\w$]+)*$/', $main)) {
            $this->cmpinfo = "Invalid main_class parameter";
        } else {
            $this->mainClassName = $main;
        }
    }

    public function defaultFileName($sourcecode)
    {
        return 'prog.scala';
    }

    public function getExecutablePath()
    {
        return self::JAVA;
    }

    public function getTargetFile()
    {
        return $this->mainClassName;
    }

    // java <interpreterargs> -cp <scala libs> <main class> <runargs>
    public function getRunCommand()
    {
        $cmd = array_merge([$this->getExecutablePath()], self::unsafeAccessArgs(),
            $this->getParam('interpreterargs'));
        $cmd[] = '-cp';
        $cmd[] = escapeshellarg(self::runtimeClasspath());
        $cmd[] = escapeshellarg($this->getTargetFile());
        return array_merge($cmd, $this->getParam('runargs'));
    }

    // As for Java, replace tabs at the start of stack-trace lines.
    public function filteredStderr()
    {
        return str_replace("\n\t", "\n        ", $this->stderr);
    }


    // ************************************************
    //   Helpers
    // ************************************************

    /**
     * Return the fully-qualified name of the class to run, or null if none
     * can be found. Like Java's main-class detection this uses regular
     * expressions rather than a parser, so it can be fooled, in which case
     * the main_class parameter can be used. Recognised, in order of priority:
     *   1. A top-level "@main def name(...)" (the first, if several).
     *   2. "object Name extends App".
     *   3. The object enclosing the first "def main(" in the file.
     * A leading "package a.b" clause is prepended.
     */
    public static function findMainClass($source)
    {
        $src = self::stripComments($source);
        $package = '';
        if (preg_match('/^\s*package\s+([\w.]+)/m', $src, $m)) {
            $package = $m[1] . '.';
        }

        if (preg_match('/@main\s+def\s+(\w+)/', $src, $m)) {
            return $package . $m[1];
        }
        if (preg_match('/\bobject\s+(\w+)\s+extends\s+App\b/', $src, $m)) {
            return $package . $m[1];
        }
        if (preg_match('/\bdef\s+main\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $mainPos = $m[0][1];
            if (preg_match_all('/\bobject\s+(\w+)/', $src, $objs, PREG_OFFSET_CAPTURE)) {
                $name = null;
                foreach ($objs[1] as $obj) {
                    if ($obj[1] < $mainPos) {
                        $name = $obj[0];
                    }
                }
                if ($name !== null) {
                    return $package . $name;
                }
            }
        }
        return null;
    }

    // Remove // and /* */ comments, leaving string literals intact.
    private static function stripComments($source)
    {
        $pattern = '~"""(?:.|\n)*?"""|"(?:\\\\.|[^"\\\\\n])*"|//[^\n]*|/\*.*?\*/~s';
        return preg_replace_callback($pattern, function ($m) {
            return $m[0][0] === '"' ? $m[0] : ' ';
        }, $source);
    }

    private static function stripAnsi($s)
    {
        return preg_replace('/\x1b\[[0-9;]*m/', '', $s);
    }
}
