<?php
require __DIR__ . '/sworkerd.php';

use cmhc\sworker\sworkerd;

class test extends sworkerd
{
	public function __construct()
	{
		$this->addOpt('n:');
		parent::__construct();

		$option = $this->getOption();
		if (!isset($option['n'])) {
			$option['n'] = 1;
		}
		for($i=0; $i<$option['n']; $i++) {
			$this->addWorker('writeFile');
		}
	}

	public function writeFileInit()
	{

	}

	public function writeFile()
	{
		file_put_contents(__DIR__ . "/test_content", "running\n",  FILE_APPEND);
		sleep(1);
	}
}

(new test())->start();