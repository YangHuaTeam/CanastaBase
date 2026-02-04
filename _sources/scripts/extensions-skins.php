<?php

/**
 * Optimized installation script with Parallel Processing and Batch Composer.
 */

$MW_HOME = getenv("MW_HOME");
$MW_VERSION = getenv("MW_VERSION");
$MW_VOLUME = getenv("MW_VOLUME");
$MW_ORIGIN_FILES = getenv("MW_ORIGIN_FILES");
$path = $argv[1];

// Configuration for parallelism
const MAX_CONCURRENT_JOBS = 8; // Adjust based on CPU/Network capability

$contentsData = [
    "extensions" => [],
    "skins" => [],
];

populateContentsData($path, $contentsData);

$composerPackages = [];
$shellJobs = [];

echo "Preparing installation list...\n";

foreach (["extensions", "skins"] as $type) {
    foreach ($contentsData[$type] as $name => $data) {
        // 'remove: true' logic
        $remove = $data["remove"] ?? false;
        if ($remove) {
            continue;
        }

        $repository = $data["repository"] ?? null;
        $commit = $data["commit"] ?? null;
        $branch = $data["branch"] ?? null;
        $patches = $data["patches"] ?? null;
        $persistentDirectories = $data["persistent directories"] ?? null;
        $additionalSteps = $data["additional steps"] ?? null;
        $bundled = $data["bundled"] ?? false;
        $requiredExtensions = $data["required extensions"] ?? null;

        // Collect Composer packages for batch installation
        if ($data["composer-name"] ?? null) {
            $packageName = $data["composer-name"];
            $packageVersion = $data["composer-version"] ?? null;
            $packageString = $packageVersion
                ? "$packageName:$packageVersion"
                : $packageName;
            $composerPackages[] = $packageString;
            continue;
        }

        // Build a command chain for this extension to run in parallel later
        if (!$bundled) {
            $cmds = [];

            $gitCloneCmd = "git clone ";
            if ($repository === null) {
                $repository = "https://github.com/wikimedia/mediawiki-$type-$name";
                if ($branch === null) {
                    $branch = $MW_VERSION;
                    $gitCloneCmd .= "--single-branch -b $branch ";
                }
            }
            $gitCloneCmd .= "$repository $MW_HOME/canasta-$type/$name";
            $cmds[] = $gitCloneCmd;

            // 2. Git Checkout
            // We use && to ensure subsequent commands only run if clone succeeded
            $cmds[] = "cd $MW_HOME/canasta-$type/$name && git checkout -q $commit";

            // 3. Patches
            if ($patches !== null) {
                foreach ($patches as $patch) {
                    $cmds[] = "cd $MW_HOME/canasta-$type/$name && git apply /tmp/$patch";
                }
            }

            // 4. Additional Steps
            if ($additionalSteps !== null) {
                foreach ($additionalSteps as $step) {
                    if ($step === "composer update") {
                        // Note: Using 'composer install' is usually safer/faster for CI than update ?
                        $cmds[] = "composer install --working-dir=$MW_HOME/canasta-$type/$name --no-interaction --no-dev";
                    } elseif ($step === "git submodule update") {
                        $cmds[] = "cd $MW_HOME/canasta-$type/$name && git submodule update --init";
                    } else {
                        // Fallback for custom steps if added in future, assuming they are shell commands
                        // $cmds[] = $step;
                    }
                }
            }

            // 5. Cleanup .git
            $cmds[] = "rm -rf $MW_HOME/canasta-$type/$name/.git";

            // 6. Persistent Directories
            if ($persistentDirectories !== null) {
                $cmds[] = "mkdir -p $MW_ORIGIN_FILES/$type/$name";
                foreach ($persistentDirectories as $directory) {
                    $cmds[] = "mv $MW_HOME/canasta-$type/$name/$directory $MW_ORIGIN_FILES/$type/$name/";
                    $cmds[] = "ln -s $MW_VOLUME/$type/$name/$directory $MW_HOME/canasta-$type/$name/$directory";
                }
            }

            // Combine all steps for this extension into one long shell string
            $fullCommand = implode(" && ", $cmds);

            // Store job with a unique key
            $shellJobs["$type/$name"] = $fullCommand;
        }
    }
}

// EXECUTION PHASE

// 1. Batch Install Composer Packages
if (!empty($composerPackages)) {
    echo "Batch installing " .
        count($composerPackages) .
        " composer packages...\n";
    $packageList = implode(" ", $composerPackages);
    // This runs on the main process as it locks composer.json
    passthru(
        "composer require $packageList --working-dir=$MW_HOME --no-interaction --update-no-dev",
    );
}

// 2. Parallel Install Git Extensions
if (!empty($shellJobs)) {
    echo "Installing " .
        count($shellJobs) .
        " extensions/skins in parallel (max " .
        MAX_CONCURRENT_JOBS .
        ")...\n";
    run_parallel_jobs($shellJobs, MAX_CONCURRENT_JOBS);
}

echo "All extensions and skins processed.\n";

/**
 * Helper function to run commands in parallel using proc_open
 */
function run_parallel_jobs($jobs, $maxConcurrency)
{
    $running = []; // [pid => resource]
    $queue = $jobs;

    // While there are jobs left to start OR jobs currently running
    while (!empty($queue) || !empty($running)) {
        // Fill the slots up to maxConcurrency
        while (count($running) < $maxConcurrency && !empty($queue)) {
            $name = key($queue);
            $cmd = array_shift($queue);

            $descriptors = [
                0 => ["pipe", "r"], // stdin
                1 => ["pipe", "w"], // stdout
                2 => ["pipe", "w"], // stderr
            ];

            $process = proc_open($cmd, $descriptors, $pipes);

            if (is_resource($process)) {
                // We don't strictly need to read the output for this script,
                // but closing pipes is good practice to prevent deadlocks.
                // For a build script, we might want to see output, but interleaved output is messy.
                // Let's just print start/finish.
                // echo " [Started] $name\n";

                // We set stream blocking to 0 so we don't hang checking them,
                // though we aren't actively reading them in this simple implementation.
                stream_set_blocking($pipes[1], 0);
                stream_set_blocking($pipes[2], 0);

                $running[] = [
                    "process" => $process,
                    "pipes" => $pipes,
                    "name" => $name,
                ];
            } else {
                echo " [Error] Failed to spawn process for $name\n";
            }
        }

        // Check for finished processes
        foreach ($running as $key => $job) {
            $status = proc_get_status($job["process"]);

            if (!$status["running"]) {
                // Process finished
                $exitCode = $status["exitcode"];
                $name = $job["name"];

                // Close pipes and process
                fclose($job["pipes"][0]);
                fclose($job["pipes"][1]);
                fclose($job["pipes"][2]);
                proc_close($job["process"]);

                if ($exitCode !== 0) {
                    echo " [Failed] $name (Exit code: $exitCode)\n";
                } else {
                    // echo " [Done] $name\n";
                }

                unset($running[$key]);
            }
        }

        // Wait a tiny bit to prevent CPU spinning
        usleep(100000); // 100ms
    }
}

/**
 * Recursive function to populate YAML data (Unchanged from original)
 */
function populateContentsData($pathOrURL, &$contentsData)
{
    $yamlText = file_get_contents($pathOrURL);
    if (
        preg_match(
            '/<syntaxhighlight\s+lang=["\']yaml["\']>(.*?)<\/syntaxhighlight>/si',
            $yamlText,
            $matches,
        )
    ) {
        $yamlText = $matches[1];
    }
    $dataFromFile = yaml_parse($yamlText);

    if (array_key_exists("inherits", $dataFromFile)) {
        populateContentsData($dataFromFile["inherits"], $contentsData);
    }

    if (array_key_exists("extensions", $dataFromFile)) {
        foreach ($dataFromFile["extensions"] as $obj) {
            $extensionName = key($obj);
            $extensionData = $obj[$extensionName];
            $contentsData["extensions"][$extensionName] = $extensionData;
        }
    }

    if (array_key_exists("skins", $dataFromFile)) {
        foreach ($dataFromFile["skins"] as $obj) {
            $skinName = key($obj);
            $skinData = $obj[$skinName];
            $contentsData["skins"][$skinName] = $skinData;
        }
    }
}

?>
