<?php namespace la\core\db;
if ( ! defined( 'WPINC' ) ) die;

use Exception;
use la\core\settings\LASettingsUtils;

/**
 * Flow-Flow.
 *
 * @package   FlowFlow
 * @author    Looks Awesome <email@looks-awesome.com>

 * @link      http://looks-awesome.com
 * @copyright Looks Awesome
 */
class LADB {
	public static function create(){
		try{
			return new LASafeMySQL( [ 'host' => DB_HOST, 'user' => DB_USER, 'pass' => DB_PASSWORD, 'db' => DB_NAME, 'charset' => FF_DB_CHARSET, 'errmode' => 'exception' ] );
		// @codeCoverageIgnoreStart
		} catch( Exception $e){
            echo '<b>Flow-Flow</b> plugin encountered database connection error. Please contact for support via item\'s comments section and provide info below:<br>';
			echo esc_html( $e->getMessage() );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if (isset($_REQUEST['debug'])){
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_dump
				var_dump($e);
			}
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log($e->getMessage());
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log($e->getTraceAsString());
			die();
		}
		// @codeCoverageIgnoreEnd
	}

	private static $cache = [];

	public static function getOption($conn, $table_name, $option_name, $serialized = false, $lock_row = false, $without_cache = false){
		if ($lock_row  || $without_cache || !isset(self::$cache[$option_name])){
			$q = 'select `value` from ?n where `id`=?s';
			if ($lock_row) $q .= ' for update';
			$options = $conn->getOne($q, $table_name, $option_name);
			if ($options == false || $options == null ) return false;
			if ($without_cache){
				return $serialized ? unserialize($options) : $options;
			}
			self::$cache[$option_name] = $serialized ? unserialize($options) : $options;
		}
		return self::$cache[$option_name];
	}

    /**
     * @param $conn
     * @param $table_name
     * @param $optionName
     * @param $optionValue
     * @param false $serialized
     * @param bool $cached
     *
     * @throws Exception
     */
    public static function setOption($conn, $table_name, $optionName, $optionValue, $serialized = false, $cached = true){
		if ($cached) self::$cache[$optionName] = is_object($optionValue) ? clone $optionValue : $optionValue;
		if ($serialized) $optionValue = serialize($optionValue);
		$exists = $conn->getOne('SELECT 1 FROM ?n WHERE `id`=?s', $table_name, $optionName);
		if ($exists) {
			if ( false === $conn->query( 'UPDATE ?n SET `value`=?s WHERE `id`=?s',
					$table_name, $optionValue, $optionName ) ) {
				throw new Exception(); // @codeCoverageIgnore
			}
		} else {
			if ( false === $conn->query( 'INSERT INTO ?n SET `id`=?s, `value`=?s',
					$table_name, $optionName, $optionValue ) ) {
				throw new Exception(); // @codeCoverageIgnore
			}
		}
	}

    /**
     * @param $conn
     * @param $table_name
     * @param $optionName
     *
     * @throws Exception
     */
    public static function deleteOption($conn, $table_name, $optionName){
		if (false === $conn->query('DELETE FROM ?n WHERE `id`=?s', $table_name, $optionName)){
			throw new Exception(); // @codeCoverageIgnore
		}
		unset(self::$cache[$optionName]);
	}

    /**
     * @param LASafeMySQL $conn
     * @param string $table_name
     *
     * @return array
     */
    public static function streams($conn, $table_name){
		if (false !== ($result = $conn->getIndCol('id', 'SELECT `id`, `name`, `value` FROM ?n ORDER BY `id`', $table_name))){
			return $result;
		}
		return [];
	}

	/**
     * @param LASafeMySQL $conn
	 * @param $cache_table_name
	 * @param $streams_sources_table_name
	 * @param string $stream
	 * @param bool $only_enable
	 *
	 * @return array
	 */
	public static function sources($conn, $cache_table_name, $streams_sources_table_name, $stream = null, $only_enable = false){
		$sql_part = '';
		if ($only_enable && $stream == null)  $sql_part = $conn->parse('WHERE `enabled` = 1');
		if ($stream != null) $sql_part = $conn->parse('inner join ?n `conn` on `cach`.`feed_id` = `conn`.`feed_id` WHERE `enabled` = 1 and `conn`.`stream_id` = ?s', $streams_sources_table_name, $stream);
		$sql = $conn->parse('SELECT  `cach`.`feed_id` as `id`, `settings`, `errors`, `status`, `enabled`, `last_update`, `cach`.cache_lifetime, `cach`.system_enabled, `cach`.boosted FROM ?n `cach` ?p ORDER BY `changed_time` DESC', $cache_table_name, $sql_part);
		if (false !== ($result = $conn->getInd('id', $sql))){
			foreach ( $result as &$source ) {
				self::prepareSource($source);
			}
			return $result;
		}
		return [];
	}

	public static function prepareSource(&$source){
		if (isset($source['settings'])){
			$settings = unserialize($source['settings']);
			if (is_object($settings)) {
				$source = array_merge($source, (array) $settings);
				unset($source['settings']);
			}
		}

		$source['enabled'] = $source['system_enabled'] == 1 ? (($source['enabled'] == 1 || $source['enabled'] == LASettingsUtils::YEP) ? LASettingsUtils::YEP : LASettingsUtils::NOPE) : LASettingsUtils::NOPE;
		$offset = get_option('gmt_offset', 0);
		$date = $source['last_update'] + $offset * 3600;
		$source['last_update'] = $source['last_update'] == 0 ? 'N/A' : LASettingsUtils::classicStyleDate($date);
		if (!isset($source['errors']) || is_null($source['errors'])) {
			$source['errors'] = [];
		}
		if (!empty($source['errors'])){
			$errors = is_string($source['errors']) ? unserialize($source['errors']) : $source['errors'];
			if (false !== $errors){
				if (is_array($errors)){
					$escape = [ "'" ];
					$replacements = [ " " ];
					foreach ( $errors as &$error ) {
						if (isset($error['message'])){
							if (is_array($error['message'])){
								for ( $i = 0; $i < sizeof($error['message']); $i ++ ) {
									$error['message'][$i]['msg'] = str_replace($escape, $replacements, $error['message'][$i]['msg']);
								}
							}
							else {
								$error['message'] = str_replace($escape, $replacements, $error['message']);
							}
							continue;
						}

						//TODO delete
						if (is_array($error) && isset($error[0])){
							$error['message'] = $error[0];
							unset($error[0]);
						}
						if (is_array($error) && isset($error['msg'])){
							$error['message'] = $error['msg'];
							unset($error['msg']);
						}
					}
					$source['errors'] = $errors;
				}
			}
		}
		if ((empty($source['errors']) || is_string($source['errors'])) && $source['status'] === '0') {
			$source['errors'] = [ [ 'type' => $source['type'], 'message' => 'Feed cache has not been built. Try to manually rebuild cache using three dots menu on the left.' ] ];
		}
	}

	/**
     * @param LASafeMySQL $conn
	 * @param string $table_name
	 *
	 * @return bool|int
	 */
	public static function countFeeds($conn, $table_name){
		if (LADDLUtils::existTable($conn, $table_name) && false !== ($count = $conn->getOne('select count(*) from ?n', $table_name))){
			return (int) $count;
		}
		return false;
	}

	/**
     * @param LASafeMySQL $conn
	 * @param string $table_name
	 *
	 * @return bool|int
	 */
	public static function maxIdOfStreams($conn, $table_name){
		if (false !== ($max = $conn->getOne('select max(`id`) from ?n', $table_name))){
			return (int) $max;
		}
		return false;
	}

	public static function getStream($conn, $table_name, $id){
		if (!array_key_exists($id, self::$cache)){
			if (false !== ($row = $conn->getRow('select `value`, `feeds` from ?n where `id`=?s', $table_name, $id))) {
				if ($row != null){
					self::$cache[$id] = self::unserializeStream($row);
				}
				else return null;
			}
		}
		return self::$cache[$id];
	}

	public static function unserializeStream($stream){
        //$options->feeds = $stream['feeds'];
        $val = unserialize($stream['value']);
        if (is_object($val)) {
            if (isset($val->isOverlay)) unset($val->isOverlay);
            if (isset($val->trueLayout)) unset($val->trueLayout);
            if (isset($val->shop)) unset($val->shop);
            if (isset($val->plugin)) unset($val->plugin);
        } elseif (is_array($val)) {
            if (isset($val['isOverlay'])) unset($val['isOverlay']);
            if (isset($val['trueLayout'])) unset($val['trueLayout']);
            if (isset($val['shop'])) unset($val['shop']);
            if (isset($val['plugin'])) unset($val['plugin']);
        }
        return $val;
	}

	public static function getStatusInfo($conn, $cache_table_name, $streams_sources_table_name, $streamId, $format = true) {
        $sql_part = $conn->parse('where `src`.`stream_id` = ?s and `cach`.`enabled` = true', $streamId);
		$sql = $conn->parse('select `src`.`stream_id` as `id`, MIN(`cach`.`status`) as `status`, COUNT(`cach`.`feed_id`) as `feeds_count` from ?n `cach` inner join ?n `src` on `cach`.`feed_id` = `src`.`feed_id`  ?p  group by `src`.`stream_id`', $cache_table_name, $streams_sources_table_name, $sql_part);
		$status_info = $conn->getAll($sql);
		if (empty($status_info)){
			return [ 'id' => (string)$streamId, 'status' => '1', 'feeds_count' => '0' ];
		}
		$status_info = $status_info[0];
		if ($status_info['status'] == '0') {
			$status_info['error'] = self::getError($conn, $cache_table_name, $streams_sources_table_name, $streamId, $format);
		}
		return $status_info;
	}

    /**
     * @param LASafeMySQL $conn
     * @param $cache_table_name
     * @param $streams_sources_table_name
     * @param $streamId
     * @param bool $format
     *
     * @return array|string|true
     */
    public static function getError($conn, $cache_table_name, $streams_sources_table_name, $streamId, $format = true){
		$result = '';
		$errors = $conn->getInd('feed_id', 'select `cach`.`errors`, `cach`.`feed_id` from ?n `cach` inner join ?n `src` on `cach`.`feed_id` = `src`.`feed_id` where `src`.`stream_id` = ?s and `cach`.`enabled` = 1', $cache_table_name, $streams_sources_table_name, $streamId);
		foreach ( $errors as $feed => $error ) {
			unset($error['feed_id']);
			if (is_array($error)){
				foreach ( $error as $str ) {
					$value = unserialize($str);
					if (!empty($value)){
						if (is_array($value) && sizeof($value) > 0){
							$value = $value[0];
						}
						if (!is_array($result)) $result = [];
						$result[$feed] = $value;
					}

				}
			}
			else if (is_string($error)){
				$value = unserialize($error);
				if (!empty($value)){
					$result[] = $value;
				}

			}
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
		return $format ? print_r($result, true) : $result;
	}

    /**
     * @param LASafeMySQL $conn
     * @param $streams_table_name
     * @param $streams_sources_table_name
     * @param $id
     * @param $stream
     *
     * @throws Exception
     */
    public static function setStream($conn, $streams_table_name, $streams_sources_table_name, $id, $stream){
		self::$cache[$id] = clone $stream;
		$name = @$stream->name;
		$originalFeed = $stream->feeds;
		if (is_string($stream->feeds)){
			$feeds = stripslashes($stream->feeds);
			$feeds = json_decode($feeds);
		}
		else{
			$feeds = (array)$stream->feeds;
		}
		unset($stream->feeds);
		$serialized = serialize($stream);

		$common = [
			'name'      => $name,
			'value'     => $serialized
        ];
		if ( false === $conn->query( 'INSERT INTO ?n SET `id`=?s, ?u ON DUPLICATE KEY UPDATE ?u',
				$streams_table_name, $id, $common, $common ) ) {
			throw new Exception();
		}

		$stream->feeds = $originalFeed;

		$feed_ids = [];
		foreach ( $feeds as $feed ) {
			$fid = is_array($feed) ? $feed['id'] :  $feed->id;
			$feed_ids[] = $fid;
			$connect = [
				'stream_id' => $id,
				'feed_id' => $fid
            ];
			if ( false === $conn->query( 'INSERT INTO ?n SET ?u ON DUPLICATE KEY UPDATE ?u',
					$streams_sources_table_name, $connect, $connect ) ) {
				throw new Exception();
			}
		}
        $sql_part = '';
        if (!empty($feed_ids)) {
            $sql_part = $conn->parse(' AND `feed_id` NOT IN (?a)', $feed_ids);
        }
		if ( false === $conn->query( 'DELETE FROM ?n WHERE `stream_id`=?s ?p',
				$streams_sources_table_name, $id, $sql_part ) ) {
			throw new Exception();
		}
        $conn->commit();
	}

	public static function deleteStream($conn, $streams_table_name, $streams_sources_table_name, $id){
		unset(self::$cache[$id]);
		if (false === $conn->query('DELETE FROM ?n WHERE `id`=?s', $streams_table_name, $id)){
			return new Exception();
		}
		if (false === $conn->query('DELETE FROM ?n WHERE `stream_id`=?s', $streams_sources_table_name, $id)){
			return new Exception();
		}
		return true;
	}

	public static function saveFeed($conn, $cache_table_name, $feed_id, $values){
		$sql = $conn->parse('UPDATE ?n SET ?u WHERE `feed_id` = ?s', $cache_table_name, $values, $feed_id);
		return $conn->query($sql);
	}
}