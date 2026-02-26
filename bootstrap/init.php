<?php

	# | -------------------------
	# | Init sessions
	# | -------------------------
	if (session_status() === PHP_SESSION_NONE) {
		session_start();
	}

	# | -------------------------
	# | Init autoload
	# | -------------------------
	require_once __DIR__ . '/../vendor/autoload.php';

	use App\Core\Core;
	use App\Http\Route;
	use App\Utils\Construct\{GenJwt};
	use App\Utils\Functions\Layout;

	# | -------------------------
	# | Load .env file
	# | -------------------------
	$dotenv  = Dotenv\Dotenv::createImmutable(__DIR__ . '/../', '.env');
	$dotenv->load();

	# | -------------------------
	# | Init TimeZone
	# | -------------------------
	date_default_timezone_set($_ENV['APP_TIMEZONE']);

	# | -------------------------
	# | Init Class Hub
	# | -------------------------
	require_once __DIR__ . "/../src/routes/".Layout::getSubdomainHost()."_main.php";

	# | -------------------------
	# | JWT Validation
	# | -------------------------
	$jwt  = new GenJwt;
	$jwt->validateJwt();

	# | -------------------------
	# | Dispatch
	# | -------------------------
	Core::dispatch(Route::routes());