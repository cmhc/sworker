<?php
use cmhc\sworker\sworkerd;
require __DIR__ . '/vendor/autoload.php';

class test extends sworkerd
{
	public function __construct()
	{
		parent::__construct();
	}

	public function worker()
	{
		file_put_contents(__DIR__ . "/test_content", "running\n",  FILE_APPEND);
		sleep(1);
	}
}

(new test())->start();