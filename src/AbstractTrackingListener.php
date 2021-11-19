<?php

namespace Gedmo;

use Doctrine\Common\EventArgs;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\Event\LoadClassMetadataEventArgs;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\NotifyPropertyChanged;
use Gedmo\Exception\UnexpectedValueException;
use Gedmo\Mapping\Event\AdapterInterface;
use Gedmo\Mapping\MappedEventSubscriber;

/**
 * The AbstractTrackingListener provides generic functions for all listeners.
 *
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
abstract class AbstractTrackingListener extends MappedEventSubscriber
{
    /**
     * Specifies the list of events to listen on.
     *
     * @return string[]
     */
    public function getSubscribedEvents()
    {
        return [
            'prePersist',
            'onFlush',
            'loadClassMetadata',
        ];
    }

    /**
     * Maps additional metadata for the object.
     *
     * @param LoadClassMetadataEventArgs $eventArgs
     *
     * @return void
     */
    public function loadClassMetadata(EventArgs $eventArgs)
    {
        $ea = $this->getEventAdapter($eventArgs);
        $this->loadMetadataForObjectClass($ea->getObjectManager(), $eventArgs->getClassMetadata());
    }

    /**
     * Processes object updates when the manager is flushed.
     *
     * @return void
     */
    public function onFlush(EventArgs $args)
    {
        $ea = $this->getEventAdapter($args);
        $om = $ea->getObjectManager();
        $uow = $om->getUnitOfWork();
        // check all scheduled updates
        $all = array_merge($ea->getScheduledObjectInsertions($uow), $ea->getScheduledObjectUpdates($uow));
        foreach ($all as $object) {
            $meta = $om->getClassMetadata(get_class($object));
            if (!$config = $this->getConfiguration($om, $meta->name)) {
                continue;
            }
            $changeSet = $ea->getObjectChangeSet($uow, $object);
            $needChanges = false;

            if ($uow->isScheduledForInsert($object) && isset($config['create'])) {
                foreach ($config['create'] as $field) {
                    // Field can not exist in change set, i.e. when persisting an embedded object without a parent
                    $new = array_key_exists($field, $changeSet) ? $changeSet[$field][1] : false;
                    if (null === $new) { // let manual values
                        $needChanges = true;
                        $this->updateField($object, $ea, $meta, $field);
                    }
                }
            }

            if (isset($config['update'])) {
                foreach ($config['update'] as $field) {
                    $isInsertAndNull = $uow->isScheduledForInsert($object)
                        && array_key_exists($field, $changeSet)
                        && null === $changeSet[$field][1];
                    if (!isset($changeSet[$field]) || $isInsertAndNull) { // let manual values
                        $needChanges = true;
                        $this->updateField($object, $ea, $meta, $field);
                    }
                }
            }

            if (!$uow->isScheduledForInsert($object) && isset($config['change'])) {
                foreach ($config['change'] as $options) {
                    if (isset($changeSet[$options['field']])) {
                        continue; // value was set manually
                    }

                    if (!is_array($options['trackedField'])) {
                        $singleField = true;
                        $trackedFields = [$options['trackedField']];
                    } else {
                        $singleField = false;
                        $trackedFields = $options['trackedField'];
                    }

                    foreach ($trackedFields as $trackedField) {
                        $trackedChild = null;
                        $tracked = null;
                        $parts = explode('.', $trackedField);
                        if (isset($parts[1])) {
                            $tracked = $parts[0];
                            $trackedChild = $parts[1];
                        }

                        if (!isset($tracked) || array_key_exists($trackedField, $changeSet)) {
                            $tracked = $trackedField;
                            $trackedChild = null;
                        }

                        if (isset($changeSet[$tracked])) {
                            $changes = $changeSet[$tracked];
                            if (isset($trackedChild)) {
                                $changingObject = $changes[1];
                                if (!is_object($changingObject)) {
                                    throw new UnexpectedValueException("Field - [{$tracked}] is expected to be object in class - {$meta->name}");
                                }
                                $objectMeta = $om->getClassMetadata(get_class($changingObject));
                                $om->initializeObject($changingObject);
                                $value = $objectMeta->getReflectionProperty($trackedChild)->getValue($changingObject);
                            } else {
                                $value = $changes[1];
                            }

                            if (($singleField && in_array($value, (array) $options['value'])) || null === $options['value']) {
                                $needChanges = true;
                                $this->updateField($object, $ea, $meta, $options['field']);
                            }
                        }
                    }
                }
            }

            if ($needChanges) {
                $ea->recomputeSingleObjectChangeSet($uow, $meta, $object);
            }
        }
    }

    /**
     * Processes updates when an object is persisted in the manager.
     *
     * @return void
     */
    public function prePersist(EventArgs $args)
    {
        $ea = $this->getEventAdapter($args);
        $om = $ea->getObjectManager();
        $object = $ea->getObject();
        $meta = $om->getClassMetadata(get_class($object));
        if ($config = $this->getConfiguration($om, $meta->getName())) {
            if (isset($config['update'])) {
                foreach ($config['update'] as $field) {
                    if (null === $meta->getReflectionProperty($field)->getValue($object)) { // let manual values
                        $this->updateField($object, $ea, $meta, $field);
                    }
                }
            }
            if (isset($config['create'])) {
                foreach ($config['create'] as $field) {
                    if (null === $meta->getReflectionProperty($field)->getValue($object)) { // let manual values
                        $this->updateField($object, $ea, $meta, $field);
                    }
                }
            }
        }
    }

    /**
     * Get the value for an updated field.
     *
     * @param ClassMetadata    $meta
     * @param string           $field
     * @param AdapterInterface $eventAdapter
     */
    abstract protected function getFieldValue($meta, $field, $eventAdapter);

    /**
     * Updates a field.
     *
     * @param object           $object
     * @param AdapterInterface $eventAdapter
     * @param ClassMetadata    $meta
     * @param string           $field
     */
    protected function updateField($object, $eventAdapter, $meta, $field)
    {
        $property = $meta->getReflectionProperty($field);
        $oldValue = $property->getValue($object);
        $newValue = $this->getFieldValue($meta, $field, $eventAdapter);

        // if field value is reference, persist object
        if ($meta->hasAssociation($field) && is_object($newValue) && !$eventAdapter->getObjectManager()->contains($newValue)) {
            $uow = $eventAdapter->getObjectManager()->getUnitOfWork();

            // Check to persist only when the object isn't already managed, always persists for MongoDB
            if (!($uow instanceof UnitOfWork) || UnitOfWork::STATE_MANAGED !== $uow->getEntityState($newValue)) {
                $eventAdapter->getObjectManager()->persist($newValue);
            }
        }

        $property->setValue($object, $newValue);

        if ($object instanceof NotifyPropertyChanged) {
            $uow = $eventAdapter->getObjectManager()->getUnitOfWork();
            $uow->propertyChanged($object, $field, $oldValue, $newValue);
        }
    }
}
