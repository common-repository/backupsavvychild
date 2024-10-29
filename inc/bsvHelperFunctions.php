<?php


class bsvHelperFunctions {
	public $files = array();
	public $directories = array();
	/**
	 * Discovery retrieves folder and file names, useful for other file/folder operations
	 *
	 * @param   $path
	 *
	 * @return  void
	 * @since   1.0
	 */
	public function discovery($path)
	{
		$this->directories = array();
		$this->files       = array();
		if (is_file($path)) {
			$this->files[] = $path;
			return;
		}
		if (is_dir($path)) {
		} else {
			return;
		}
		$this->directories[] = $path;
		$objects = new RecursiveIteratorIterator (
			new RecursiveDirectoryIterator($path),
			RecursiveIteratorIterator::SELF_FIRST);
		foreach ($objects as $name => $object) {
			if (is_file($name)) {
				$this->files[] = $name;
			} elseif (is_dir($name)) {
				if (basename($name) == '.' || basename($name) == '..') {
				} else {
					$this->directories[] = $name;
				}
			}
		}
		return;
	}
}