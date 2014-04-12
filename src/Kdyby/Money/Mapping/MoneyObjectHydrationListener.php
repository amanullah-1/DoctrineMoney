<?php

/**
 * This file is part of the Kdyby (http://www.kdyby.org)
 *
 * Copyright (c) 2008 Filip Procházka (filip@prochazka.su)
 *
 * For the full copyright and license information, please view the file license.txt that was distributed with this source code.
 */

namespace Kdyby\Money\Mapping;

use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\Cache\CacheProvider;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Kdyby;
use Kdyby\Money\MetadataException;
use Nette;
use Nette\Utils\Json;



/**
 * @author Filip Procházka <filip@prochazka.su>
 */
class MoneyObjectHydrationListener extends Nette\Object implements Kdyby\Events\Subscriber
{

	/**
	 * @var \Doctrine\Common\Cache\CacheProvider
	 */
	private $cache;

	/**
	 * @var \Doctrine\ORM\EntityManager
	 */
	private $entityManager;

	/**
	 * @var \Doctrine\Common\Annotations\Reader
	 */
	private $annotationReader;



	public function __construct(CacheProvider $cache, Reader $annotationReader, EntityManager $entityManager)
	{
		$this->cache = $cache;
		$this->cache->setNamespace(get_called_class());
		$this->entityManager = $entityManager;
		$this->annotationReader = $annotationReader;
	}



	public function getSubscribedEvents()
	{
		return array(
			Events::loadClassMetadata
		);
	}



	public function postLoad($entity, LifecycleEventArgs $args)
	{
		if (!$fieldsMap = $this->getEntityMoneyFields($entity)) {
			return;
		}

		$class = $this->entityManager->getClassMetadata(get_class($entity));

		foreach ($fieldsMap as $moneyField => $currencyAssociation) {
			$currency = $class->getFieldValue($entity, $currencyAssociation);
			if (!$currency instanceof Kdyby\Money\Currency) {
				$class->setFieldValue($entity, $moneyField, NULL); // sorry bro!
				continue;
			}

			$amount = $class->getFieldValue($entity, $moneyField);
			$money = new Kdyby\Money\Money($amount, $currency);
			$class->setFieldValue($entity, $moneyField, $money);
		}
	}



	public function loadClassMetadata(LoadClassMetadataEventArgs $args)
	{
		$class = $args->getClassMetadata();
		if (!$class instanceof ClassMetadata || $class->isMappedSuperclass) {
			return;
		}

		if ($this->getEntityMoneyFields($class->newInstance(), $class)) {
			$class->addEntityListener(Events::postLoad, get_called_class(), Events::postLoad);
		}
	}



	private function getEntityMoneyFields($entity, ClassMetadata $class = NULL)
	{
		$class = $class ?: $this->entityManager->getClassMetadata(get_class($entity));

		if ($this->cache->contains($class->getName())) {
			return Json::decode($this->cache->fetch($class->getName()));
		}

		$moneyFields = array();

		foreach ($class->getFieldNames() as $fieldName) {
			$mapping = $class->getFieldMapping($fieldName);
			if ($mapping['type'] !== Kdyby\Money\Types\Money::MONEY) {
				continue;
			}

			$property = $class->getReflectionClass()->getProperty($fieldName);
			$column = $this->annotationReader->getPropertyAnnotation($property, 'Doctrine\ORM\Mapping\Column');

			if (empty($column->options['currency'])) {
				if ($class->hasAssociation('currency')) {
					$column->options['currency'] = 'currency'; // default association name

				} else {
					throw MetadataException::missingCurrencyReference($property);
				}
			}

			if (!$class->hasAssociation($column->options['currency'])) {
				throw MetadataException::invalidCurrencyReference($property);
			}

			$moneyFields[$fieldName] = $column->options['currency'];
		}

		$this->cache->save($class->getName(), Json::encode($moneyFields));

		return $moneyFields;
	}

}
