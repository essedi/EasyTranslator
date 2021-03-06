<?php

namespace Essedi\EasyTranslation\EventSubscriber;

use Essedi\EasyTranslation\Entity\Translation;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\Common\Annotations\Reader;
use Symfony\Component\Form\Exception\InvalidConfigurationException;
use Essedi\EasyTranslation\Annotation\Translatable;
use Doctrine\Common\Util\ClassUtils;
use Essedi\EasyTranslation\Entity\FieldTranslation;
use Symfony\Component\HttpFoundation\RequestStack;

class TranslatableSubscriber implements EventSubscriberInterface
{

    /**
     *
     * @var Reader 
     */
    private $annotationReader;

    /**
     * @var RequestStack 
     */
    protected $requestStack;

    /**
     * Default Lang sets on config
     * @var string 
     */
    private $defLang;

    public function __construct(
            Reader $annotationReader,
            RequestStack $requestStack,
            string $defLang)
    {
        $this->annotationReader = $annotationReader;
        $this->requestStack     = $requestStack;
        $this->defLang          = $defLang;
    }

    public static function getSubscribedEvents()
    {
        return [
            'postLoad',
            'prePersist',
            'preUpdate'
        ];
    }

    /**
     * Run when charge entity data from database
     */
    public function postLoad(LifecycleEventArgs $args)
    {
        $entity        = $args->getEntity();
        $entityManager = $args->getEntityManager();
        $locale        = $this->requestStack->getCurrentRequest() ? $this->requestStack->getCurrentRequest()->getLocale() : $this->defLang; //get default lang

        $class           = ClassUtils::getClass($entity);
        $reflectedEntity = new \ReflectionClass($class);
        $res             = $this->annotationReader->getClassAnnotation($reflectedEntity, Translatable::class);

        //the clas has been marked as Translatable
        if ($res && $entity instanceof Translation)
        {
            //getting all class translations
            $mappedTranslations = $entity->getTranslations($locale);
            //the container locale
            $locales            = array_keys($mappedTranslations);
            $locales            = count($locales) ? $locales : [$locale];

            if (!in_array($this->defLang, $locales))
            {
                $locales[] = $this->defLang;
            }

            $classProperties = $entity->getTranslatableAnnotations();

            $mappedTranslationsUpdated = false;
            foreach ($locales as $currentLocale)
            {
                foreach ($classProperties as $currentProperty => $annotation)
                {
                    $setterMethod = $reflectedEntity->getMethod("set" . ucfirst($currentProperty));
                    if (isset($mappedTranslations[$currentLocale]) && isset($mappedTranslations[$currentLocale][$currentProperty]))
                    {
                        $fieldTransEntity = $mappedTranslations[$currentLocale][$currentProperty];
                        if ($fieldTransEntity->getFieldType() != $annotation->type)
                        {
                            $fieldTransEntity->setFieldType($annotation->type);
                            $mappedTranslationsUpdated = true;
                        }
                        if ($currentLocale == $locale)
                        {
                            $setterMethod->invoke($entity, $fieldTransEntity->getFieldValue());
                        }
                    }
                    else
                    {
                        if (!isset($mappedTranslations[$currentLocale]))
                        {
                            $mappedTranslations[$currentLocale] = [];
                            $mappedTranslationsUpdated          = true;
                        }
                        if (!isset($mappedTranslations[$currentLocale][$currentProperty]))
                        {
                            $ftran = new FieldTranslation();
                            $ftran->setFieldName($currentProperty);
                            $ftran->setLocale($currentLocale);
                            $ftran->setFieldType($annotation->type);
                            $ftran->setFieldLabel($annotation->label);

                            $mappedTranslations[$currentLocale][$currentProperty] = $ftran;
                            $mappedTranslationsUpdated                            = true;
                        }
                        if ($currentLocale == $locale)
                        {
                            $setterMethod->invoke($entity, "");
                        }
                    }
                }
            }
            if ($mappedTranslationsUpdated)
            {
                $entity->setTranslations($mappedTranslations, $args);
                $entityManager->persist($entity);
                $entityManager->flush();
            }
        }
        else if ($res)
        {
            throw new InvalidConfigurationException("The $class is annotated as Translatable but does not extends the Translation abstract class");
        }
    }

    public function prePersist(LifecycleEventArgs $args)
    {
        $entity = $args->getEntity();
        $em     = $args->getEntityManager();
        $locale = $this->requestStack->getCurrentRequest() ? $this->requestStack->getCurrentRequest()->getLocale() : $this->defLang;
        //the clas has been marked as Translatable
        if ($entity && $entity instanceof Translation)
        {
            $tFields = $entity->getTranslatableFields();
//            die(var_dump($tFields));
            foreach ($tFields as $fieldName)
            {
                $getter = "get" . ucfirst($fieldName);
                if (method_exists($entity, $getter))
                {
                    $value = $entity->$getter();
                    $trans = $entity->getTranslation($fieldName, $locale);
                    if ($trans && $trans->getFieldValue() !== $value)
                    {
                        $trans->setFieldValue($value);
                    }
                }
            }
        }
    }

    public function preUpdate(PreUpdateEventArgs $args)
    {
        $entity = $args->getEntity();
        $em     = $args->getEntityManager();
        $locale = $this->requestStack->getCurrentRequest() ? $this->requestStack->getCurrentRequest()->getLocale() : $this->defLang;

        //the clas has been marked as Translatable
        if ($entity && $entity instanceof Translation)
        {
            $tFields = $entity->getTranslatableFields();
            foreach (array_keys($args->getEntityChangeSet()) as $fieldName)
            {
                $value = $args->getNewValue($fieldName);
                if (array_search($fieldName, $tFields) !== false)
                {
                    $trans = $entity->getTranslation($fieldName, $locale);
                    if ($trans && $trans->getFieldValue() !== $value)
                    {
                        $trans->setFieldValue($value);
                        $em->persist($trans);
                        $em->flush($trans);
                    }
                }
            }
        }
    }

}
