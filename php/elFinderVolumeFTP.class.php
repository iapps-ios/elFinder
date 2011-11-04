<?php

function chmodnum($chmod) {
    $trans = array('-' => '0', 'r' => '4', 'w' => '2', 'x' => '1');
    $chmod = substr(strtr($chmod, $trans), 1);
    $array = str_split($chmod, 3);
    return array_sum(str_split($array[0])) . array_sum(str_split($array[1])) . array_sum(str_split($array[2]));
}

/**
 * Simple elFinder driver for FTP
 *
 * @author Dmitry (dio) Levashov
 * @author Cem (discofever)
 **/
class elFinderVolumeFTP extends elFinderVolumeDriver {
	
	/**
	 * Driver id
	 * Must be started from letter and contains [a-z0-9]
	 * Used as part of volume id
	 *
	 * @var string
	 **/
	protected $driverId = 'f';
	
	/**
	 * FTP Connection Instance
	 *
	 * @var ftp
	 **/
	protected $connect = null;
	
	/**
	 * Directory for tmp files
	 * If not set driver will try to use tmbDir as tmpDir
	 *
	 * @var string
	 **/
	protected $tmpPath = '';
	
	/**
	 * Files info cache
	 *
	 * @var array
	 **/
	protected $cache = array();
		
	/**
	 * Last FTP error message
	 *
	 * @var string
	 **/
	protected $ftpError = '';
	
	/**
	 * undocumented class variable
	 *
	 * @var string
	 **/
	protected $separator = '';
	
	protected $ftpOsUnix;
	
	/**
	 * undocumented class variable
	 *
	 * @var string
	 **/
	// protected $cache = array();
	
	/**
	 * Constructor
	 * Extend options with required fields
	 *
	 * @return void
	 * @author Dmitry (dio) Levashov
	 * @author Cem (DiscoFever)
	 **/
	public function __construct() {
		$opts = array(
			'host'          => 'localhost',
			'user'          => '',
			'pass'          => '',
			'port'          => 21,
			'mode'        	=> 'passive',
			'path'			=> '/',
			'timeout'		=> 10
		);
		$this->options = array_merge($this->options, $opts); 
	}
	
	/*********************************************************************/
	/*                        INIT AND CONFIGURE                         */
	/*********************************************************************/
	
	/**
	 * Prepare FTP connection
	 * Connect to remote server and check if credentials are correct, if so, store the connection id in $ftp_conn
	 *
	 * @return bool
	 * @author Dmitry (dio) Levashov
	 * @author Cem (DiscoFever)
	 **/
	protected function init() {

		if (!$this->options['host'] 
		||  !$this->options['user'] 
		||  !$this->options['pass'] 
		||  !$this->options['port']
		||  !$this->options['path']) {
			return $this->setError('Required options undefined.');
		}
		
		if (!function_exists('ftp_connect')) {
			return $this->setError('FTP extension not loaded..');
		}
		// normalize root path
		$this->root = $this->options['path'] = $this->_normpath($this->options['path']);
		
		if (empty($this->options['alias'])) {
			$this->options['alias'] = $this->root == '/' ? 'FTP folder' : basename($this->root);
		}

		// $this->rootName = $this->options['alias'];

		return $this->connect();
	}


	/**
	 * Configure after successfull mount.
	 *
	 * @return void
	 * @author Dmitry (dio) Levashov
	 **/
	protected function configure() {
		parent::configure();
		
	}
	
	/**
	 * Connect to ftp server
	 *
	 * @return bool
	 * @author Dmitry (dio) Levashov
	 **/
	protected function connect() {
		if (!($this->connect = ftp_connect($this->options['host'], $this->options['port'], $this->options['timeout']))) {
			return $this->setError('Unable to connect to FTP server '.$this->options['host']);
		}
		if (!ftp_login($this->connect, $this->options['user'], $this->options['pass'])) {
			$this->umount();
			return $this->setError('Unable to login into '.$this->options['host']);
		}
		
		// switch off extended passive mode - may be usefull for some servers
		@ftp_exec($this->connect, 'epsv4 off' );
		// enter passive mode if required
		ftp_pasv($this->connect, $this->options['mode'] == 'passive');

		// enter root folder
		if (!ftp_chdir($this->connect, $this->root) 
		|| $this->root != ftp_pwd($this->connect)) {
			$this->umount();
			return $this->setError('Unable to open root folder.');
		}
		
		// check for MLST support
		$features = ftp_raw($this->connect, 'FEAT');
		if (!is_array($features)) {
			$this->umount();
			return $this->setError('Server does not support command FEAT. wtf? 0_o');
		}

		foreach ($features as $feat) {
			if (strpos(trim($feat), 'MLST') === 0) {
				return true;
			}
		}
		
		return $this->setError('Server does not support command MLST. wtf? 0_o');
	}
	
	/*********************************************************************/
	/*                               FS API                              */
	/*********************************************************************/

	/**
	 * Close opened connection
	 *
	 * @return void
	 * @author Dmitry (dio) Levashov
	 **/
	public function umount() {
		$this->connect && @ftp_close($this->connect);
	}

	/**
	 * undocumented function
	 *
	 * @return void
	 * @author Dmitry Levashov
	 **/
	protected function stat($path) {
		if (!isset($this->cache[$path])) {
			$root = $this->root == $path;

			$this->cache[$path] = $this->parseMLST(ftp_raw($this->connect, 'MLST '.$path));
			$this->cache[$path]['name'] = $root ? $this->rootName : basename($path);
			$this->cache[$path]['hash'] = $this->encode($path);
			if (!$root) {
				$this->cache[$path]['phash'] = $this->encode(dirname($path));
			} else {
				$this->cache[$path]['volumeid'] = $this->id;
			}
			// dirty hack
			// @todo - fix in parent class to avoid such hacks
			$this->cache[$path]['read']  = $this->attr($path, 'read');
			$this->cache[$path]['write'] = $this->attr($path, 'write');
			$this->cache[$path]['locked'] = $this->attr($path, 'locked');
			$this->cache[$path]['hidden'] = $this->attr($path, 'hidden');
			// echo $path;
			// debug($this->cache[$path]);
		}
		
		
		return $this->cache[$path];
	}


	/**
	 * Parse MLST response and return file stat array or false
	 *
	 * @param  array $raw MLST response
	 * @return object|false
	 * @author Dmitry (dio) Levashov
	 **/
	protected function parseMLST($raw) {
		if (substr(trim($raw[0]), 0, 1) != 2) {
			return array();
		}
		
		$parts = explode(';', trim($raw[1]));
		array_pop($parts);
		$parts = array_map('strtolower', $parts);
		$stat  = array();
		
		foreach ($parts as $part) {

			list($key, $val) = explode('=', $part);
			
			switch ($key) {
				case 'type':
					$stat['mime'] = strpos($val, 'dir') !== false ? 'directory' : $this->mimetype($path);
					break;
					
				case 'size':
					if ($stat['mime'] != 'directory') {
						$stat['size'] = $val;
					}
					break;
					
					
				case 'modify':
					$ts = mktime(substr($val, 8, 2), substr($val, 10, 2), substr($val, 12, 2), substr($val, 4, 2), substr($val, 6, 2), substr($val, 0, 4));
					$stat['ts'] = $ts;
					$stat['date'] = $this->formatDate($ts);
					break;
					
				case 'unix.mode':
					$stat['read']  = 1;
					$stat['write'] = 1;
					break;
					
				case 'perm':
					$val = strtolower($val);
					$stat['read']  = (int)preg_match('/e|l|r/', $val);
					$stat['write'] = (int)preg_match('/w|m|c/', $val);
					if (!preg_match('/f|d/', $val)) {
						$stat['locked'] = 1;
					}
					break;
			}
		}
		
		// debug($stat);
		return $stat;
	}

	/**
	 * undocumented function
	 *
	 * @return void
	 * @author Dmitry Levashov
	 **/
	protected function parseRaw($raw, $path) {
		$info = preg_split("/\s+/", $raw, 9);
		
		
		if (count($info) < 9) {
			return false;
		}
		
		if (!isset($this->ftpOsUnix)) {
			$this->ftpOsUnix = !preg_match('/\d/', substr($info[0], 0, 1));
		}
		
		$file = $path.'/'.$info[8];
		$name = $info[8];
		$stat = array(
			'name' => $name,
			'hash' => $this->encode($path.'/'.$name),
			'mime' => substr($info[0], 0, 1) == 'd' ? 'directory' : $this->mimetype($name)
		);
		return $stat;
	}

	/**
	 * undocumented function
	 *
	 * @return void
	 **/
	protected function chmod($chmod) {
		$trans = array('-' => '0', 'r' => '4', 'w' => '2', 'x' => '1');
	    $chmod = substr(strtr($chmod, $trans), 1);
	    $array = str_split($chmod, 3);
	    return array_sum(str_split($array[0])) . array_sum(str_split($array[1])) . array_sum(str_split($array[2]));
	}


	/*********************** paths/urls *************************/
	
	/**
	 * Return parent directory path
	 *
	 * @param  string  $path  file path
	 * @return string
	 * @author Dmitry (dio) Levashov
	 **/
	protected function _dirname($path) {
		return dirname($path);
	}

	/**
	 * Return file name
	 *
	 * @param  string  $path  file path
	 * @return string
	 * @author Dmitry (dio) Levashov
	 **/
	protected function _basename($path) {
		return basename($path);
	}

	/**
	 * Join dir name and file name and retur full path
	 *
	 * @param  string  $dir
	 * @param  string  $name
	 * @return string
	 * @author Dmitry (dio) Levashov
	 **/
	protected function _joinPath($dir, $name) {
		return $dir.DIRECTORY_SEPARATOR.$name;
	}
	
	/**
	 * Return normalized path, this works the same as os.path.normpath() in Python
	 *
	 * @param  string  $path  path
	 * @return string
	 * @author Troex Nevelin
	 **/
	protected function _normpath($path) {
		if (empty($path)) {
			$path = '.';
		}
		// path must be start with /
		$path = preg_replace('|^\.\/?|', '/', $path);
		$path = preg_replace('/^([^\/])/', "/$1", $path);

		return parent::_normpath($path);
	}
	
	/**
	 * Return file path related to root dir
	 *
	 * @param  string  $path  file path
	 * @return string
	 * @author Dmitry (dio) Levashov
	 **/
	protected function _relpath($path) {
		return $path == $this->root ? '' : substr($path, strlen($this->root)+1);
	}
	
	/**
	 * Convert path related to root dir into real path
	 *
	 * @param  string  $path  file path
	 * @return string
	 * @author Dmitry (dio) Levashov
	 **/
	protected function _abspath($path) {
		return $path == $this->separator ? $this->root : $this->root.$this->separator.$path;
	}
	
	/**
	 * Return fake path started from root dir
	 *
	 * @param  string  $path  file path
	 * @return string
	 * @author Dmitry (dio) Levashov
	 **/
	protected function _path($path) {
		return $this->rootName.($path == $this->root ? '' : $this->separator.$this->_relpath($path));
	}
	
	/**
	 * Return true if $path is children of $parent
	 *
	 * @param  string  $path    path to check
	 * @param  string  $parent  parent path
	 * @return bool
	 * @author Dmitry (dio) Levashov
	 **/
	protected function _inpath($path, $parent) {
		return $path == $parent || strpos($path, $parent.$this->separator) === 0;
	}
	
	
	/***************** file stat ********************/
	/**
	 * Return true if path is dir and has at least one childs directory
	 *
	 * @param  string  $path  dir path
	 * @return bool
	 * @author Dmitry (dio) Levashov
	 **/
	protected function _subdirs($path) {
		return false;
	}
	
	/**
	 * Return object width and height
	 * Ususaly used for images, but can be realize for video etc...
	 *
	 * @param  string  $path  file path
	 * @param  string  $mime  file mime type
	 * @return string
	 * @author Dmitry (dio) Levashov
	 **/
	protected function _dimensions($path, $mime) {
		return false;
	}
	
	/******************** file/dir content *********************/
		
	/**
	 * Return files list in directory.
	 *
	 * @param  string  $path  dir path
	 * @return array
	 * @author Dmitry (dio) Levashov
	 * @author Cem (DiscoFever)
	 **/
	protected function _scandir($path) {
		$files = array();
		
		$list = ftp_rawlist($this->connect, $path);
		// debug($list);
		foreach ($list as $str) {
			// echo $str.'<br>';
			$stat = $this->parseRaw($str, $path);
			debug($stat);
		}
		// 
		// foreach (scandir($path) as $name) {
		// 	// echo $name.'<br>';
		// 	if ($name != '.' && $name != '..') {
		// 		$files[] = $path.$this->separator.$name;
		// 	}
		// }
		// return false;
		return $files;

	}
		
	/**
	 * Open file and return file pointer
	 *
	 * @param  string  $path  file path
	 * @param  bool    $write open file for writing
	 * @return resource|false
	 * @author Dmitry (dio) Levashov
	 **/
	protected function _fopen($path, $mode='rb') {
		die('Not yet implemented. (_fopen)');
		return @fopen($path, $mode);
	}
	
	/**
	 * Close opened file
	 *
	 * @param  resource  $fp  file pointer
	 * @return bool
	 * @author Dmitry (dio) Levashov
	 **/
	protected function _fclose($fp, $path='') {
		die('Not yet implemented.');
		return @fclose($fp);
	}
	
	/********************  file/dir manipulations *************************/
	
	/**
	 * Create dir
	 *
	 * @param  string  $path  parent dir path
	 * @param string  $name  new directory name
	 * @return bool
	 * @author Dmitry (dio) Levashov
	 **/
	protected function _mkdir($path, $name) {
		$path = $path.DIRECTORY_SEPARATOR.$name;
		
		if ($path == '' || (!($this->ftp_conn)))
		{
			return false;
		}

		$result = @ftp_mkdir($this->ftp_conn, $path);

		if ($result === false)
		{
			$this->setError('Unable to create remote directory.');
			return false;
		}

		/* TODO : implement for ftp */
		/*
		if (@mkdir($path)) {
			@chmod($path, $this->options['dirMode']);
			return true;
		}
		*/
		return true;
	}
	
	/**
	 * Create file
	 *
	 * @param  string  $path  parent dir path
	 * @param string  $name  new file name
	 * @return bool
	 * @author Dmitry (dio) Levashov
	 **/
	protected function _mkfile($path, $name) {
		die('Not yet implemented. (_mkfile)');
		$path = $path.DIRECTORY_SEPARATOR.$name;
		
		if (($fp = @fopen($path, 'w'))) {
			@fclose($fp);
			@chmod($path, $this->options['fileMode']);
			return true;
		}
		return false;
	}
	
	/**
	 * Create symlink
	 *
	 * @param  string  $target  link target
	 * @param  string  $path    symlink path
	 * @return bool
	 * @author Dmitry (dio) Levashov
	 **/
	protected function _symlink($target, $path, $name='') {
		die('Not yet implemented. (_symlink)');
		if (!$name) {
			$name = basename($path);
		}
		return @symlink('.'.DIRECTORY_SEPARATOR.$this->_relpath($target), $path.DIRECTORY_SEPARATOR.$name);
	}
	
	/**
	 * Copy file into another file
	 *
	 * @param  string  $source     source file path
	 * @param  string  $targetDir  target directory path
	 * @param  string  $name       new file name
	 * @return bool
	 * @author Dmitry (dio) Levashov
	 **/
	protected function _copy($source, $targetDir, $name='') {
		die('Not yet implemented. (_copy)');
		$target = $targetDir.DIRECTORY_SEPARATOR.($name ? $name : basename($source));
		return copy($source, $target);
	}
	
	/**
	 * Move file into another parent dir
	 *
	 * @param  string  $source  source file path
	 * @param  string  $target  target dir path
	 * @param  string  $name    file name
	 * @return bool
	 * @author Dmitry (dio) Levashov
	 **/
	protected function _move($source, $targetDir, $name='') {
		die('Not yet implemented. (_move)');
		$target = $targetDir.DIRECTORY_SEPARATOR.($name ? $name : basename($source));
		return @rename($source, $target);
	}
		
	/**
	 * Remove file
	 *
	 * @param  string  $path  file path
	 * @return bool
	 * @author Dmitry (dio) Levashov
	 **/
	protected function _unlink($path) {
		if ($path == '' || (!($this->ftp_conn)))
		{
			return false;
		}
		
		$result = @ftp_delete($this->ftp_conn, $path);

		if ($result === false)
		{
			return $this->setError('Unable to delete remote file');
			return false;
		}
		return true;
	}

	/**
	 * Remove dir
	 *
	 * @param  string  $path  dir path
	 * @return bool
	 * @author Dmitry (dio) Levashov
	 **/
	protected function _rmdir($path) {
		die('Not yet implemented. (_rmdir)');
		return @rmdir($path);
	}
	
	/**
	 * Create new file and write into it from file pointer.
	 * Return new file path or false on error.
	 *
	 * @param  resource  $fp   file pointer
	 * @param  string    $dir  target dir path
	 * @param  string    $name file name
	 * @return bool|string
	 * @author Dmitry (dio) Levashov
	 **/
	protected function _save($fp, $dir, $name, $mime, $w, $h) {
		die('Not yet implemented. (_save)');
		$path = $dir.DIRECTORY_SEPARATOR.$name;

		if (!($target = @fopen($path, 'wb'))) {
			return false;
		}

		while (!feof($fp)) {
			fwrite($target, fread($fp, 8192));
		}
		fclose($target);
		@chmod($path, $this->options['fileMode']);
		clearstatcache();
		return $path;
	}
	
	/**
	 * Get file contents
	 *
	 * @param  string  $path  file path
	 * @return string|false
	 * @author Dmitry (dio) Levashov
	 **/
	protected function _getContents($path) {
		ie('Not yet implemented. (_getContents)');
		return file_get_contents($path);
	}
	
	/**
	 * Write a string to a file
	 *
	 * @param  string  $path     file path
	 * @param  string  $content  new file content
	 * @return bool
	 * @author Dmitry (dio) Levashov
	 **/
	protected function _filePutContents($path, $content) {
		die('Not yet implemented. (_filePutContents)');
		if (@file_put_contents($path, $content, LOCK_EX) !== false) {
			clearstatcache();
			return true;
		}
		return false;
	}

	/**
	 * Detect available archivers
	 *
	 * @return void
	 **/
	protected function _checkArchivers() {
		// die('Not yet implemented. (_checkArchivers)');
		return array();
	}

	/**
	 * Unpack archive
	 *
	 * @param  string  $path  archive path
	 * @param  array   $arc   archiver command and arguments (same as in $this->archivers)
	 * @return true
	 * @return void
	 * @author Dmitry (dio) Levashov
	 * @author Alexey Sukhotin
	 **/
	protected function _unpack($path, $arc) {
		die('Not yet implemented. (_unpack)');
		return false;
	}

	/**
	 * Recursive symlinks search
	 *
	 * @param  string  $path  file/dir path
	 * @return bool
	 * @author Dmitry (dio) Levashov
	 **/
	protected function _findSymlinks($path) {
		die('Not yet implemented. (_findSymlinks)');
		if (is_link($path)) {
			return true;
		}
		if (is_dir($path)) {
			foreach (scandir($path) as $name) {
				if ($name != '.' && $name != '..') {
					$p = $path.DIRECTORY_SEPARATOR.$name;
					if (is_link($p)) {
						return true;
					}
					if (is_dir($p) && $this->_findSymlinks($p)) {
						return true;
					} elseif (is_file($p)) {
						$this->archiveSize += filesize($p);
					}
				}
			}
		} else {
			$this->archiveSize += filesize($path);
		}
		
		return false;
	}

	/**
	 * Extract files from archive
	 *
	 * @param  string  $path  archive path
	 * @param  array   $arc   archiver command and arguments (same as in $this->archivers)
	 * @return true
	 * @author Dmitry (dio) Levashov, 
	 * @author Alexey Sukhotin
	 **/
	protected function _extract($path, $arc) {
		die('Not yet implemented. (_extract)');
		
	}
	
	/**
	 * Create archive and return its path
	 *
	 * @param  string  $dir    target dir
	 * @param  array   $files  files names list
	 * @param  string  $name   archive name
	 * @param  array   $arc    archiver options
	 * @return string|bool
	 * @author Dmitry (dio) Levashov, 
	 * @author Alexey Sukhotin
	 **/
	protected function _archive($dir, $files, $name, $arc) {
		die('Not yet implemented. (_archive)');
		return false;
	}
	
} // END class 


?>
		