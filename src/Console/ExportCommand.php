<?php
/*
 * @package     YML Export Plugin
 * @version     __DEPLOY_VERSION__
 * @author      Delo Design - delo-design.ru
 * @copyright   Copyright (c) 2023 Delo Design. All rights reserved.
 * @license     GNU/GPL license: https://www.gnu.org/copyleft/gpl.html
 * @link        https://delo-design.ru/
 */

namespace Joomla\Plugin\RadicalMart\YML\Console;

\defined('_JEXEC') or die;

use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Filesystem\File;
use Joomla\CMS\MVC\Factory\MVCFactoryAwareTrait;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\RadicalMart\Site\Helper\RouteHelper;
use Joomla\Console\Command\AbstractCommand;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Filesystem\Folder;
use Joomla\Filesystem\Path;
use Joomla\Registry\Registry;
use Joomla\Utilities\ArrayHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ExportCommand extends AbstractCommand
{
	use MVCFactoryAwareTrait;
	use DatabaseAwareTrait;

	/**
	 * The default command name
	 *
	 * @var    string|null
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	protected static $defaultName = 'radicalmart:export:yml';

	/**
	 * The output to command style.
	 *
	 * @var   SymfonyStyle
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	protected SymfonyStyle $ioStyle;

	/**
	 *  The input to inject into the command.
	 *
	 * @var   InputInterface
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	protected InputInterface $cliInput;

	/**
	 * Последний id товара.
	 *
	 * @var   int
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected int $last_id = 0;

	/**
	 * Текущий YML object.
	 *
	 * @var   \SimpleXMLElement|null
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected ?\SimpleXMLElement $yml = null;

	/**
	 * Индекс текущего YML файла.
	 *
	 * @var   int;
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected int $yml_index = 1;

	/**
	 * Категории которые были добавлены в текущий yml.
	 *
	 * @var   array|null
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected ?array $yml_categories = null;

	/**
	 *
	 * @var   array|null
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected ?array $_categories = null;

	/**
	 *
	 * @var   array|null
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected ?array $_fields = null;

	/**
	 * Текущие кол-во товаров в yml.
	 *
	 * @var   int|null
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected ?int $yml_products_count = null;

	/**
	 * Максимум товаров в yml.
	 *
	 * @var   int|null
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected ?int $yml_products_limit = null;

	/**
	 * Internal function to execute the command.
	 *
	 * @param   InputInterface   $input   The input to inject into the command.
	 * @param   OutputInterface  $output  The output to inject into the command.
	 *
	 * @throws Exception
	 *
	 * @return  integer  The command exit code.
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	protected function doExecute(InputInterface $input, OutputInterface $output): int
	{
		// Configure the Symfony output helper
		$this->configureSymfonyIO($input, $output);

		$limit = $input->getOption('products_limit');

		$this->yml_products_limit = (!empty($limit)) ? (int) $limit : 0;

		$io = $this->ioStyle;

		try
		{
			$this->createYML();
		}
		catch (Exception $e)
		{
			try
			{
				$io->progressFinish();
			}
			catch (\Throwable $er)
			{

			}

			$io->error($e->getMessage());
			if (JDEBUG)
			{
				echo $e->getTraceAsString() . PHP_EOL;
			}
		}


		return 0;
	}

	/**
	 * Основной метод.
	 *
	 * @throws Exception
	 * @return void
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected function createYML(): void
	{
		// Считаем кол-во товаров для счетчика
		$io = $this->ioStyle;
		$io->title('Create YML file');
		$io->progressStart(1);
		$io->progressFinish();

		$db    = $this->getDatabase();
		$query = $db->getQuery(true)
			->select('COUNT(p.id)')
			->from($db->quoteName('#__radicalmart_products', 'p'))
			->where('p.state = 1');
		$total = $db->setQuery($query)->loadResult();

		$io = $this->ioStyle;
		$io->title('add Product');
		$io->progressStart($total);

		$this->recursiveAddProductToYML();
		$io->progressFinish();
	}

	/**
	 * Метод для рекурсивного добавления товара в yml файл
	 *
	 * @throws Exception
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected function recursiveAddProductToYML(): void
	{
		// Каждый раз проверяем yml
		$this->checkYML();

		$db      = $this->getDatabase();
		$query   = $db->getQuery(true)
			->select(['p.id', 'p.title', 'p.language', 'p.alias', 'p.prices', 'p.category', 'p.categories', 'p.media', 'p.fulltext', 'p.fields']) // Можно сразу с нужными столбцами, но не зведочка
			->from($db->quoteName('#__radicalmart_products', 'p'))
			->where($db->quoteName('p.state') . ' = 1')
			->where($db->quoteName('p.id') . ' > :last_id')
			->bind(':last_id', $this->last_id, ParameterType::INTEGER);
		$product = $db->setQuery($query, 0, 1)->loadObject();

		// Если товаров уже нет закрываем yml
		if (empty($product) || empty($product->id))
		{
			$this->createYMLFile();

			return;
		}

		$this->last_id = (int) $product->id;
		$this->yml_products_count++;

		// Получаем категории товара
		$categories = explode(',', $product->categories);

		$onlyCategories = [116, 121, 123, 124];
		$findCategory   = false;

		// Добавляем в массив только нужные
		$excludesCategories = [];
		foreach ($categories as $catid)
		{
			$catid = (int) $catid;
			if (in_array($catid, $excludesCategories))
			{
				continue;
			}

			if (in_array($catid, $onlyCategories))
			{
				$findCategory = $catid;

				break;
			}
		}

		if (empty($findCategory))
		{
			$findCategory = (int) $product->category;
		}

		if (!in_array($findCategory, $this->yml_categories))
		{
			$this->yml_categories[] = $findCategory;
		}
		// Получаем нужные поля по аналогии с категориями

		// Добавляем товар в yml в offers
		$path = str_replace(Uri::root(true), '', Uri::root());

		$product->prices = (new Registry($product->prices))->toArray();
		$product->price  = $product->prices['rub'];

		$product->fields = (new Registry($product->fields))->toArray();

		$offer = $this->yml->shop->offers->addChild('offer');
		$offer->addAttribute('id', $product->id);
		$offer->addChild('currencyId', $product->price['currency']);
		$offer->addChild('price', $product->price['base']);
		$offer->addChild('name', $product->title);

		$url = RouteHelper::getProductRoute($product->id . ':' . $product->alias, $product->category, $product->language);
		$url = Route::link('site', $url);
		$url = str_replace(Uri::root(true), '', $url);
		$url = trim($url, '/');
		$offer->addChild('url', $path . $url);

		$fieldAlies = [];
		foreach ($product->fields as $pf => $value)
		{
			if (empty($product->fields[$pf]))
			{
				unset($product->fields[$pf]);

				continue;
			}

			$fieldAlies[] = $pf;
		}

		$fields = $this->getFieldsData($fieldAlies);

		foreach ($product->fields as $pf => $value)
		{
			$field = (isset($fields[$pf])) ? $fields[$pf] : false;
			if (!$field)
			{
				continue;
			}

			$receivedValue = null;
			if ($field->fieldType === 'text')
			{
				$receivedValue = $value;
			}
			elseif ($field->fieldType === 'list')
			{
				if (isset($field->options[$value]))
				{
					$receivedValue = $field->options[$value];
				}
			}
			elseif ($field->fieldType === 'list_m' || $field->fieldType === 'checkboxes')
			{
				$paramValue = [];
				foreach ($value as $fvalue)
				{
					if (isset($field->options[$fvalue]))
					{
						$paramValue[] = $field->options[$fvalue];
					}
				}
				$receivedValue = implode(', ', $paramValue);
			}
			elseif ($field->fieldType === 'textarea')
			{
				$pregValue     = preg_replace("/\r|\n/", " ", $value);
				$receivedValue = $pregValue;
			}
			elseif ($field->fieldType === 'editor')
			{
				$stringValue    = preg_replace("(<[<>]+>)", ' ', $value);
				$removingTags   = strip_tags($stringValue);
				$removingSpaces = preg_replace("/\r|\n/", " ", $removingTags);
				$receivedValue  = $removingSpaces;
			}

			if (empty($receivedValue))
			{
				continue;
			}

			if ($pf === 'zavod')
			{
				$offer->addChild('vendor', $receivedValue);
			}
			else
			{
				$param = $offer->addChild('param', $receivedValue);
				$param->addAttribute('name', $field->title);
			}
		}

		$offer->addChild('categoryId', $findCategory);

		$product->media = (new Registry($product->media))->toArray();

		foreach ($product->media['gallery'] as $key => $value)
		{
			if ($value['type'] === 'image')
			{
				$trimValue = trim($value['src'], '/');
				$offer->addChild('picture', $path . $trimValue);
			}
		}

		if (!empty($product->fulltext) || !empty($product->fields['nazvanie-produkta']))
		{
			$description  = $offer->addChild('description');
			$contentNode  = dom_import_simplexml($description);
			$contentOwner = $contentNode->ownerDocument;

			if (!empty($product->fulltext))
			{
				$contentNode->appendChild($contentOwner->createCDATASection($product->fulltext));
			}
			else
			{
				$contentNode->appendChild($contentOwner->createCDATASection($product->fields['nazvanie-produkta']));
			}
		}

		if ($this->yml_products_limit > 0 && $this->yml_products_count === $this->yml_products_limit)
		{
			$this->createYMLFile();
		}

		// Оичщаем раму
		$db->disconnect();

		// Запускаем рекурсию
		$this->recursiveAddProductToYML();
	}

	/**5
	 * Метод для проверки текущего yml
	 *
	 * @return void
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected function checkYML(): void
	{
		// Сначла проверяем существует ли объект yml
		if ($this->yml === null)
		{
			// Создаем XML ОБЪЕКТ
			$this->yml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><yml_catalog/>');
			$this->yml->addAttribute('date', Factory::getDate()->format(\DateTimeInterface::RFC3339));

			$path = str_replace(Uri::root(true), '', Uri::root());
			// Тут же добавляем данные магазина
			$shop = $this->yml->addChild('shop');
			$shop->addChild('name', 'Region Zoloto');
			$shop->addChild('company', 'Region-zoloto');
			$shop->addChild('url', $path);

			$shop->addChild('categories');
			// Добавляем туда офреы
			$shop->addChild('offers');

			// Очищаем категории
			$this->yml_categories = [];

			$this->yml_products_count = 0;
		}
	}

	/**
	 * Метод для записи в yml файл и очистки yml объекта.
	 *
	 * @return void
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected function createYMLFile(): void
	{
		// Добавляем категории
		$this->addCategories();

		// Записываем yml в файл
		$folder = Path::clean(JPATH_ROOT . '/yml');
		// Очищаем директорию
		if ($this->yml_index === 1 && is_dir($folder))
		{
			Folder::delete($folder);
		}

		if (!is_dir($folder))
		{
			Folder::create($folder);
		}

		// Записываем в файл
		$filename = Path::clean($folder . '/export_' . $this->yml_index . '.xml');
		if (is_file($filename))
		{
			File::delete($filename);
		}
		file_put_contents($filename, $this->yml->asXML());

		// Очищаем YML и сдвигаем индекс
		$this->yml = null;
		$this->yml_index++;
	}

	/**
	 * @return void
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected function addCategories(): void
	{
		$add = [];
		foreach ($this->yml_categories as $pk)
		{
			$path = $this->getCategoriesTree($pk);

			$categories = $this->getCategoriesData($path);

			foreach ($categories as $category)
			{
				// Проверяем не добавили ли уже этого родителя
				if (in_array((int) $category->id, $add))
				{
					continue;
				}

				$add[] = $category->id;

				$cat = $this->yml->shop->categories->addChild('category', $category->title);
				$cat->addAttribute('id', $category->id);

				if ($category->parent_id > 1)
				{
					$cat->addAttribute('parentId', $category->parent_id);
				}
			}
		}
	}

	/**
	 *  Сюда добавляем получение категорий с кеширование результата в раму и очиской если в раме больше 50 штук
	 *
	 * @param $pks
	 *
	 * @return array
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected function getCategoriesData($pks): array
	{
		if ($this->_categories === null || count($this->_categories) >= 50) $this->_categories = [];

		// Prepare ids
		$categories = [];
		if (!is_array($pks))
		{
			$pks = array_unique(ArrayHelper::toInteger(explode(',', $pks)));
		}
		if (empty($pks))
		{
			return $categories;
		}

		// Check loaded categories
		$get = [];
		foreach ($pks as $pk)
		{
			if (isset($this->_categories[$pk]))
			{
				$categories[$pk] = $this->_categories[$pk];
			}
			else
			{
				$get[] = $pk;
			}
		}

		// Get categories
		if (!empty($get))
		{
			$db    = $this->getDatabase();
			$query = $db->getQuery(true)
				->select(['c.id', 'c.title', 'c.parent_id'])
				->from($db->quoteName('#__radicalmart_categories', 'c'))
				->where($db->quoteName('alias') . ' <> ' . $db->quote('root'))
				->whereIn('c.id', $get);
			if ($rows = $db->setQuery($query)->loadObjectList())
			{
				foreach ($rows as $row)
				{
					$this->_categories[$row->id] = $row;
					$categories[$row->id]        = $row;
				}
			}
		}

		return $categories;
	}

	/**
	 * @param   string|string[]  $pks
	 *
	 * @return array|object[]
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected function getFieldsData($pks): array
	{
		if ($this->_fields === null || count($this->_fields) >= 50) $this->_fields = [];

		$fields = [];
		if (!is_array($pks))
		{
			$pks = array_unique((explode(',', $pks)));
		}
		if (empty($pks))
		{
			return $fields;
		}

		$get = [];
		foreach ($pks as $pk)
		{
			if (isset($this->_fields[$pk]))
			{
				$fields[$pk] = $this->_fields[$pk];
			}
			else
			{
				$get[] = $pk;
			}
		}

		if (!empty($get))
		{
			$db    = $this->getDatabase();
			$query = $db->getQuery(true)
				->select(['alias', 'options', 'params', 'title'])
				->from($db->quoteName('#__radicalmart_fields'))
				->whereIn($db->quoteName('alias'), $get, ParameterType::STRING);
			if ($rows = $db->setQuery($query)->loadObjectList('alias'))
			{
				foreach ($rows as $alias => $row)
				{
					$item          = new \stdClass();
					$item->title   = $row->title;
					$item->options = [];
					foreach ((new Registry($row->options))->toArray() as $option)
					{
						$item->options[$option['value']] = $option['text'];
					}
					$row->params     = new Registry($row->params);
					$item->fieldType = $row->params->get('type', 'text');

					if (empty($item->fieldType))
					{
						continue;
					}

					if ($item->fieldType === 'list' && (int) $row->params->get('multiple', 0) === 1)
					{
						$item->fieldType = 'list_m';
					}

					$this->_fields[$alias] = $item;
					$fields[$alias]        = $item;
				}
			}
		}

		return $fields;
	}

	/**
	 * @param   int  $pk
	 *
	 * @return array
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected function getCategoriesTree(int $pk = 0): array
	{
		if (empty($pk))
		{
			return [];
		}

		$db       = $this->getDatabase();
		$query    = $db->getQuery(true)
			->select('sub.id')
			->from($db->quoteName('#__radicalmart_categories', 'sub'))
			->innerJoin($db->quoteName('#__radicalmart_categories', 'this') .
				' ON sub.lft <= this.lft AND sub.rgt >= this.rgt')
			->where($db->quoteName('this.id') . ' = :this_id')
			->bind(':this_id', $pk, ParameterType::INTEGER);
		$result   = ArrayHelper::toInteger($db->setQuery($query)->loadColumn());
		$result[] = $pk;

		return array_unique($result);
	}

	/**
	 * Configure the command.
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected function configure(): void
	{
		$this->setDescription('Creates a product yml file for Yandex Market');

		$this->addOption('products_limit', null, InputOption::VALUE_OPTIONAL,
			'Product limit in file . 0 - unlimited . Default = 0');

		$help = "<info>%command.name%</info> Creates a product yml file for Yandex Market.
    \nUsage: <info>php %command.full_name% [flags]</info>";

		$this->setHelp($help);
	}

	/**
	 * Configure the IO.
	 *
	 * @param   InputInterface   $input   The input to inject into the command.
	 * @param   OutputInterface  $output  The output to inject into the command.
	 *
	 * @since __DEPLOY_VERSION__
	 */
	private function configureSymfonyIO(InputInterface $input, OutputInterface $output)
	{
		$this->cliInput = $input;
		$this->ioStyle  = new SymfonyStyle($input, $output);
	}
}