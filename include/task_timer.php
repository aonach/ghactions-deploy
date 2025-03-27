<?php
namespace Deployer;

/**
 * Task timer that explicitly logs the time taken for each task in GitHub Actions
 */

// Store task start times
set('task_timers', []);

// Before each task starts, record its start time
on('task:before', function($task) {
    $taskName = $task->getName();
    
    // Skip internal/hidden tasks
    if ($task->isHidden() || $task->isPrivate()) {
        return;
    }
    
    // Store start time
    $taskTimers = get('task_timers', []);
    $taskTimers[$taskName] = ['start' => microtime(true)];
    set('task_timers', $taskTimers);
    
    // Output GitHub Actions compatible group start
    writeln("##[group]⏱️ Starting task: {$taskName}");
});

// After each task completes, calculate and output the time taken
on('task:success', function($task) {
    $taskName = $task->getName();
    
    // Skip internal/hidden tasks
    if ($task->isHidden() || $task->isPrivate()) {
        return;
    }
    
    $taskTimers = get('task_timers', []);
    
    if (isset($taskTimers[$taskName]['start'])) {
        $start = $taskTimers[$taskName]['start'];
        $end = microtime(true);
        $duration = $end - $start;
        
        // Format the duration nicely
        $seconds = floor($duration);
        $milliseconds = round(($duration - $seconds) * 1000);
        $formattedTime = ($seconds > 0 ? "{$seconds}s " : "") . "{$milliseconds}ms";
        
        // Output GitHub Actions compatible timing information and group end
        writeln("⏱️ Task '{$taskName}' completed in {$formattedTime}");
        writeln("##[endgroup]");
        
        // Store the timing info for summary
        $taskTimers[$taskName]['duration'] = $duration;
        set('task_timers', $taskTimers);
    }
});

// Add task to display timing summary at the end
desc('Display task timing summary');
task('deploy:timing:summary', function() {
    writeln("");
    writeln("##[group]⏱️ Task Timing Summary (longest first)");
    
    $taskTimers = get('task_timers', []);
    $taskDurations = [];
    
    // Extract durations for sorting
    foreach ($taskTimers as $taskName => $timing) {
        if (isset($timing['duration'])) {
            $taskDurations[$taskName] = $timing['duration'];
        }
    }
    
    // Sort by duration (longest first)
    arsort($taskDurations);
    
    // Display the sorted task timings
    foreach ($taskDurations as $taskName => $duration) {
        $seconds = floor($duration);
        $milliseconds = round(($duration - $seconds) * 1000);
        $formattedTime = ($seconds > 0 ? "{$seconds}s " : "") . "{$milliseconds}ms";
        writeln("⏱️ {$taskName}: {$formattedTime}");
    }
    
    // Calculate and show total time
    $totalTime = array_sum($taskDurations);
    $totalSeconds = floor($totalTime);
    $totalMinutes = floor($totalSeconds / 60);
    $remainingSeconds = $totalSeconds % 60;
    $totalMilliseconds = round(($totalTime - $totalSeconds) * 1000);
    
    $formattedTotal = "";
    if ($totalMinutes > 0) {
        $formattedTotal .= "{$totalMinutes}m ";
    }
    if ($remainingSeconds > 0 || $totalMinutes > 0) {
        $formattedTotal .= "{$remainingSeconds}s ";
    }
    $formattedTotal .= "{$totalMilliseconds}ms";
    
    writeln("");
    writeln("⏱️ Total measured task time: {$formattedTotal}");
    writeln("##[endgroup]");
})->once();

// Add the summary task to the end of the deployment
after('deploy:success', 'deploy:timing:summary');