<?php
// phpcs:disable
namespace flow;

/**
 * Class FFLogManager
 * 
 * Manages debug log file maintenance, including size-based trimming
 * to prevent unlimited growth of the flow-flow-debug.log file.
 * 
 * @package flow
 */
class FFLogManager
{
    /**
     * Trim log file to keep only the most recent content
     * 
     * @param string $file Path to the log file
     * @param int $maxSize Maximum file size in bytes before trimming
     * @param int $keepSize Amount of data to keep in bytes (from the end of file)
     * @return bool True if file was trimmed, false otherwise
     */
    public static function trimLog($file, $maxSize, $keepSize)
    {
        // Safety checks
        if (!file_exists($file)) {
            return false;
        }
        
        if (!is_writable($file)) {
            return false;
        }
        
        // Check if file size exceeds threshold
        $size = filesize($file);
        if ($size === false || $size <= $maxSize) {
            return false;
        }
        
        try {
            // Keep only last $keepSize bytes from the end of the file
            $fp = fopen($file, 'r+');
            if (!$fp) {
                return false;
            }
            
            // Seek to position: $keepSize bytes from the end
            fseek($fp, -$keepSize, SEEK_END);
            
            // Read the content we want to keep
            $keep = stream_get_contents($fp);
            
            if ($keep === false) {
                fclose($fp);
                return false;
            }
            
            // Truncate and write back
            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, $keep);
            fclose($fp);
            
            // Log the trimming action
            $timestamp = date('Y-m-d H:i:s');
            $trimmed = $size - strlen($keep);
            $msg = sprintf(
                "[%s] DEBUG LOG TRIMMED: Removed %s, kept %s (file was %s)\n",
                $timestamp,
                self::formatBytes($trimmed),
                self::formatBytes(strlen($keep)),
                self::formatBytes($size)
            );
            error_log($msg, 3, $file);
            
            return true;
        } catch (\Exception $e) {
            // Silently fail - don't break the site over log maintenance
            return false;
        }
    }
    
    /**
     * Format bytes into human-readable string
     * 
     * @param int $bytes Size in bytes
     * @return string Formatted string (e.g., "1.5 MB")
     */
    private static function formatBytes($bytes)
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        } elseif ($bytes < 1048576) {
            return round($bytes / 1024, 2) . ' KB';
        } else {
            return round($bytes / 1048576, 2) . ' MB';
        }
    }

    /**
     * @param string $msg
     * @param mixed $data
     */
    public static function log($msg, $data = null)
    {
        $file = defined('FF_LOG_FILE_DEST') ? FF_LOG_FILE_DEST : plugin_dir_path(__DIR__) . 'flow-flow-debug.log';
        $timestamp = date('Y-m-d H:i:s');
        $formattedMsg = "[$timestamp] $msg";

        if ($data !== null) {
            $formattedMsg .= "\nData: " . print_r($data, true);
        }

        $formattedMsg .= "\n-----------------------------------\n";

        // Failsafe: Use @ to suppress warnings if file is not writable
        @error_log($formattedMsg, 3, $file);
    }
}

// phpcs:enable
