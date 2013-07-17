<?php
class UserController extends AbstractController
{
	private static $modules = [];
	public static function getAvailableModules()
	{
		if (!empty(self::$modules))
		{
			return self::$modules;
		}
		$dir = implode(DIRECTORY_SEPARATOR, ['src', 'Controllers', 'UserControllerModules']);
		$classNames = ReflectionHelper::loadClasses($dir);
		self::$modules = $classNames;
		return $classNames;
	}

	public static function parseRequest($url, &$controllerContext)
	{
		$userRegex = '[0-9a-zA-Z_-]{2,}';

		$urlParts = [];
		foreach (self::getAvailableModules() as $className)
		{
			$urlParts = array_merge($urlParts, $className::getUrlParts());
		}
		$modulesRegex = implode('|', array_map(function($urlPart)
		{
			if (empty($urlPart))
			{
				return '';
			}
			return '/' . $urlPart;
		}, $urlParts));

		$mediaParts = array_map(['Media', 'toString'], Media::getConstList());
		$mediaRegex = implode('|', $mediaParts);

		$regex =
			'^/?' .
			'(' . $userRegex . ')' .
			'(' . $modulesRegex . ')' .
			'(,(' . $mediaRegex . '))?' .
			'/?$';

		if (!preg_match('#' . $regex . '#', $url, $matches))
		{
			return false;
		}

		$controllerContext->userName = $matches[1];
		$media = !empty($matches[4]) ? $matches[4] : 'anime';
		switch ($media)
		{
			case 'anime': $controllerContext->media = Media::Anime; break;
			case 'manga': $controllerContext->media = Media::Manga; break;
			default: throw new BadMediaException();
		}
		$rawModule = ltrim($matches[2], '/') ?: 'profile';
		$controllerContext->rawModule = $rawModule;
		foreach (self::getAvailableModules() as $module)
		{
			if (in_array($rawModule, $module::getUrlParts()))
			{
				$controllerContext->module = $module;
			}
		}
		assert(!empty($controllerContext->module));
		return true;
	}

	public static function work($controllerContext, &$viewContext)
	{
		$viewContext->viewName = 'user-' . $controllerContext->rawModule;
		$viewContext->module = $controllerContext->module;
		$viewContext->userName = $controllerContext->userName;
		$viewContext->media = $controllerContext->media;

		$pdo = Database::getPDO();
		$stmt = $pdo->prepare('SELECT * FROM users WHERE LOWER(name) = LOWER(?)');
		$stmt->Execute([$viewContext->userName]);
		$result = $stmt->fetch();
		if (empty($result))
		{
			#todo:
			throw new Exception('user doesn\'t exist in db, but he just got enqueued');
		}
		$viewContext->userId = $result->user_id;


		$queue = new Queue(Config::$userQueuePath);
		$queue->enqueue($controllerContext->userName);

		assert(!empty($controllerContext->module));
		$module = $controllerContext->module;
		$module::work($viewContext);
	}
}