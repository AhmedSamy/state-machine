<?php

namespace StateMachine\State;

class State implements StateInterface
{
    private $type;

    private $name;

    private $transitions;

    private $transitionObjects;

    private $events;

    public function __construct($name, $type = StateInterface::TYPE_NORMAL)
    {
        $this->name = $name;
        $this->type = $type;
        $this->transitions = [];
    }

    public function isInitial()
    {
        return $this->type === StateInterface::TYPE_INITIAL;
    }

    public function isFinal()
    {
        return $this->type === StateInterface::TYPE_FINAL;
    }

    public function isNormal()
    {
        return $this->type === StateInterface::TYPE_NORMAL;
    }

    public function getType()
    {
        return $this->type;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getTransitions()
    {
        return $this->transitions;
    }

    public function setTransitions(array $transitions)
    {
        $this->transitions = $transitions;
    }

    public function getTransitionObjects()
    {
        return $this->transitionObjects;
    }

    public function setTransitionObjects(array $transitionObjects)
    {
        $this->transitionObjects = $transitionObjects;
    }

    public function getEvents()
    {
        return $this->events;
    }

    public function setEvents(array $events)
    {
        $this->events = $events;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->name;
    }
}
