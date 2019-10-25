<?php

namespace SunValley\TaskManager;

use SunValley\TaskManager\Exception\TaskOptionValidationException;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Class AbstractTask makes an easy abstraction for implementing a task and also makes sure that the class is
 * serializable
 *
 * @package SunValley\TaskManager
 */
abstract class AbstractTask implements TaskInterface
{

    /** @var string */
    protected $id;

    /** @var array */
    protected $options;

    /** @var OptionsResolver */
    protected $optionsResolver;

    /**
     * AbstractTask constructor.
     *
     * @param string $id
     * @param array  $options
     *
     * @throws TaskOptionValidationException
     */
    public function __construct(string $id, array $options = [])
    {
        $this->id = $id;
        $this->setOptions($options);
    }

    /** @inheritDoc */
    public function getId(): string
    {
        return $this->id;
    }

    /** @inheritDoc */
    public function setOptions(array $options): void
    {
        try {
            $this->options = $this->getOptionsResolver()->resolve($options);

            serialize($this);
        } catch (\Exception $e) {
            throw new TaskOptionValidationException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /** @inheritDoc */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Returns options resolver
     *
     * @return OptionsResolver
     */
    public function getOptionsResolver(): OptionsResolver
    {
        if ($this->optionsResolver === null) {
            $this->optionsResolver = $this->buildOptionsResolver();
        }

        return $this->optionsResolver;
    }

    /**
     * Return options resolver to limit arguments
     *
     * @return OptionsResolver
     */
    abstract function buildOptionsResolver(): OptionsResolver;

    public function __sleep()
    {
        return [
            'id',
            'options',
        ];
    }
}