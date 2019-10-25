<?php

namespace SunValley\TaskManager;

use SunValley\TaskManager\Exception\TaskOptionValidationException;
use Symfony\Component\OptionsResolver\OptionsResolver;

abstract class AbstractTask implements TaskInterface
{

    /** @var string */
    protected $id;

    /** @var array */
    protected $options;

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
     * Return options resolver to limit arguments
     *
     * @return OptionsResolver
     */
    abstract function getOptionsResolver(): OptionsResolver;
}