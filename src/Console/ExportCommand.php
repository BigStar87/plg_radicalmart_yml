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

use Joomla\CMS\Factory;
use Joomla\CMS\Filesystem\File;
use Joomla\CMS\MVC\Factory\MVCFactoryAwareTrait;
use Joomla\Console\Command\AbstractCommand;
use Joomla\Database\DatabaseAwareTrait;
use Symfony\Component\Console\Input\InputInterface;
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
	 * @since __DEPLOY_VERSION__
	 */
	protected SymfonyStyle $ioStyle;

	/**
	 *  The input to inject into the command.
	 *
	 * @var   InputInterface
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected InputInterface $cliInput;

	/**
	 * Internal function to execute the command.
	 *
	 * @param   InputInterface   $input   The input to inject into the command.
	 * @param   OutputInterface  $output  The output to inject into the command.
	 *
	 * @throws \Exception
	 *
	 * @return  integer  The command exit code.
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	protected function doExecute(InputInterface $input, OutputInterface $output): int
	{
		// Configure the Symfony output helper
		$this->configureSymfonyIO($input, $output);
		$io = $this->ioStyle;
		$io->title('Create yml object');
		$io->progressStart(1);

		$yml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><yml_catalog/>');
		$yml->addAttribute('date', Factory::getDate()->format(\DateTimeInterface::RFC3339));

		$shop = $yml->addChild('shop');
		$shop->addChild('name', 'BestSeller');
		$shop->addChild('company', 'Tne Best inc.');
		$shop->addChild('url', 'http://best.seller.ru');

		$myCategories = [
			1   => [
				'id'       => 1,
				'name'     => 'Бытовая техника',
				'parentId' => null
			],
			10  => [
				'id'       => 10,
				'name'     => 'Мелкая техника для кухни',
				'parentId' => 1
			],
			101 => [
				'id'       => 101,
				'name'     => 'Сэндвичницы и приборы для выпечки',
				'parentId' => 10
			]
		];

		$myOffers = [
			901299 => [
				'name'                  => 'Мороженица Brand 3811',
				'vendor'                => 'Brand',
				'vendorCode'            => 'A1234567B',
				'categoryId'            => 10,
				'picture'               => 'http://best.seller.ru/img/model_12345.jpg',
				'manufacturer_warranty' => 'true',
				'country_of_origin'     => 'Китай',
				'barcode'               => 4601546021298,
				'params'                => [
					'param' => 'Цвет',
					'data'  => 'белый'
				],
				'weight'                => 3.6,
				'dimensions'            => '20.1/20.5/22.5',
				'service-life-days'     => 'P2Y',
				'comment-life-days'     => 'Использовать при температуре не ниже -10 градусов.',
				'warranty-days'         => 'P1Y',
				'comment-warranty'      => 'Гарантия не распространяется на механические повреждения покрытия чаши.'
			],
			123467 => [
				'name'                  => 'Сэндвичница Brand A1234567B',
				'vendor'                => 'Brand',
				'vendorCode'            => 'A1234567B',
				'categoryId'            => 101,
				'picture'               => 'http://best.seller.ru/img/device56789.jpg',
				'manufacturer_warranty' => 'true',
				'country_of_origin'     => 'Россия',
				'barcode'               => 9876543210,
				'params'                => [
					'param' => 'Мощность',
					'data'  => '750 Вт'
				],
				'weight'                => 1.03,
				'dimensions'            => '20.800/23.500/9.000'
			]
		];

		$categoriesShop = $shop->addChild('categories');

		foreach ($myCategories as $id => $data)
		{

			$categoryCategoriesShop = $categoriesShop->addChild('category', $data['name']);
			$categoryCategoriesShop->addAttribute('id', $data['id']);

			if (!empty($data['parentId']))
			{
				$categoryCategoriesShop->addAttribute('parentId', $data['parentId']);
			}

		}

		$offersShop = $shop->addChild('offers');

		foreach ($myOffers as $id => $data)
		{

			$offer = $offersShop->addChild('offer');
			$offer->addAttribute('id', $id);
			$offer->addChild('name', $data['name']);
			$offer->addChild('vendor', $data['vendor']);
			$offer->addChild('vendorCode', $data['vendorCode']);
			$offer->addChild('categoryId', $data['categoryId']);
			$offer->addChild('picture', $data['picture']);
			$offer->addChild('manufacturer_warranty', $data['manufacturer_warranty']);
			$offer->addChild('country_of_origin', $data['country_of_origin']);
			$offer->addChild('barcode', $data['barcode']);
			$paramOffer = $offer->addChild('param', $data['params']['data']);
			$paramOffer->addAttribute('name', $data['params']['param']);
			$offer->addChild('weight', $data['weight']);
			$offer->addChild('dimensions', $data['dimensions']);

			if (!empty($data['service-life-days']))
			{
				$offer->addChild('service-life-days', $data['service-life-days']);
			}

			if (!empty($data['comment-life-days']))
			{
				$offer->addChild('comment-life-days', $data['comment-life-days']);
			}

			if (!empty($data['warranty-days']))
			{
				$offer->addChild('warranty-days', $data['warranty-days']);
			}

			if (!empty($data['comment-warranty']))
			{
				$offer->addChild('comment-warranty', $data['comment-warranty']);
			}
		}

		$io->progressAdvance();
		$io->progressFinish();

		$io->title('Insert yml to file');
		$io->progressStart(1);

		$filename = JPATH_ROOT . '/test.xml';
		if (File::exists($filename))
		{
			File::delete($filename);
		}
		file_put_contents($filename, $yml->asXML());
		$io->progressAdvance();
		$io->progressFinish();

		return 0;
	}

	/**
	 * Configure the command.
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected function configure(): void
	{
		$this->setDescription('Creates a yml feed for Yandex Market');
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