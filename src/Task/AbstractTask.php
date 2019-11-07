<?php

namespace SunValley\TaskManager\Task;

use SunValley\TaskManager\Exception\TaskOptionValidationException;
use SunValley\TaskManager\TaskInterface;
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

    public function serialize()
    {
        return json_encode(
            [
                'id'      => $this->getId(),
                'options' => $this->getOptions(),
            ]
        );
    }

    public function unserialize($serialized)
    {
        $data = json_decode($serialized, true);
        if (!isset($data['id'])) {
            throw new \RuntimeException('Serialization failure. Cannot find `id` index of serialized task.');
        }

        $this->id      = $data['id'];
        $this->options = $data['options'] ?? [];
    }

    /**
     * Return options resolver to limit arguments
     *
     * @return OptionsResolver
     */
    abstract function buildOptionsResolver(): OptionsResolver;

}