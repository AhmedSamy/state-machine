<?php
namespace StateMachine\StateMachine;

use StateMachine\Accessor\StateAccessor;
use StateMachine\Accessor\StateAccessorInterface;
use StateMachine\Event\Events;
use StateMachine\Event\TransitionEvent;
use StateMachine\Exception\StateMachineException;
use StateMachine\State\State;
use StateMachine\State\StatefulInterface;
use StateMachine\State\StateInterface;
use StateMachine\Transition\Transition;
use StateMachine\Transition\TransitionInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Class StateMachine
 * @package StateMachine\StateMachine
 * Add logging support
 */
class StateMachine implements StateMachineInterface
{
    /** @var  string */
    private $class;

    /** @var StatefulInterface */
    private $object;

    /** @var StateAccessorInterface */
    private $stateAccessor;

    /** @var  StateInterface */
    private $currentState;

    /** @var  TransitionInterface[] */
    private $transitions;

    /** @var  array */
    private $states;

    /** @var bool */
    private $booted;

    /** @var  EventDispatcherInterface */
    private $eventDispatcher;

    /** @var  array */
    private $messages;

    /**
     * @param string                   $class
     * @param StateAccessorInterface   $stateAccessor
     * @param EventDispatcherInterface $eventDispatcher
     * @param StatefulInterface        $object
     */
    public function __construct(
        $class,
        StatefulInterface $object,
        EventDispatcherInterface $eventDispatcher,
        StateAccessorInterface $stateAccessor = null
    ) {
        $this->class = $class;
        $this->stateAccessor = $stateAccessor ?: new StateAccessor();
        $this->object = $object;
        $this->eventDispatcher = $eventDispatcher;
        $this->booted = false;
        $this->object->setStateMachine($this);
        $this->states = [];
        $this->transitions = [];
    }

    /**
     * {@inheritdoc}
     */
    public function boot()
    {
        if ($this->booted) {
            throw new StateMachineException("Statemachine is already booted");
        }
        if (null === $this->object) {
            throw new StateMachineException(
                sprintf("Cannot boot StateMachine without object, have you forgot to setObject()? ")
            );
        }
        if (get_class($this->object) !== $this->class) {
            throw new StateMachineException(
                sprintf(
                    "StateMachine expected object of class %s instead of %s",
                    $this->class,
                    get_class($this->object)
                )
            );
        }

        $state = $this->stateAccessor->getState($this->object);
        //no state found for the object it means it's new instance, set initial state
        if (null === $state || '' == $state) {
            $state = $this->getInitialState();
            if (null == $state) {
                throw new StateMachineException("No initial state is found");
            }
            $this->stateAccessor->setState($this->object, $state->getName());
        }

        $this->boundTransitionsToStates();
        $this->currentState = $state;
        $this->booted = true;
    }

    /**
     * {@inheritdoc}
     */
    public function getObject()
    {
        return $this->object;
    }

    /**
     * {@inheritdoc}
     */
    public function getCurrentState()
    {
        return $this->currentState;
    }

    /**
     * {@inheritdoc}
     */
    public function addTransition($from = null, $to = null)
    {
        if ($this->booted) {
            throw new StateMachineException("Cannot add more transitions to booted StateMachine");
        }
        if (!isset($this->states[$from])) {
            throw new StateMachineException(
                sprintf(
                    "State with name: %s is not found, states available are: %s",
                    $from,
                    implode(',', $this->states)
                )
            );
        }
        if (!isset($this->states[$to])) {
            throw new StateMachineException(
                sprintf(
                    "State with name: %s is not found, states available are: %s",
                    $to,
                    implode(',', $this->states)
                )
            );
        }

        $fromState = $this->states[$from];
        $toState = $this->states[$to];
        $transition = new Transition($fromState, $toState);
        $this->transitions[$transition->getName()] = $transition;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function addState($name, $type = StateInterface::TYPE_NORMAL)
    {
        $initialState = $this->getInitialState();
        if (StateInterface::TYPE_INITIAL == $type && $initialState instanceof StateInterface) {
            throw new StateMachineException(
                sprintf(
                    "Statemachine cannot have more than one initial state, current initial state is (%s)",
                    $initialState
                )
            );
        }
        $state = new State($name, $type);
        $this->states[$name] = $state;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function addGuard($transition, \Closure $callable)
    {
        if ($this->booted) {
            throw new StateMachineException("Cannot add more guards to booted StateMachine");
        }

        $this->validateTransition($transition);

        $this->eventDispatcher->addListener(Events::EVENT_ON_GUARD, $callable);
    }

    /**
     * {@inheritdoc}
     */
    public function addPreTransition($transition, \Closure $callable, $priority = 0)
    {
        if ($this->booted) {
            throw new StateMachineException("Cannot add pre-transition to booted StateMachine");
        }

        $this->validateTransition($transition);

        $this->eventDispatcher->addListener(Events::EVENT_PRE_TRANSITION, $callable);
    }

    /**
     * {@inheritdoc}
     */
    public function addPostTransition($transition, \Closure $callable, $priority = 0)
    {
        if ($this->booted) {
            throw new StateMachineException("Cannot add post-transition to booted StateMachine");
        }

        $this->validateTransition($transition);

        $this->eventDispatcher->addListener(Events::EVENT_POST_TRANSITION, $callable, $priority);
    }

    /**
     * {@inheritdoc}
     */
    public function getAllowedTransitions()
    {
        if (!$this->booted) {
            throw new StateMachineException("Statemachine is not booted");
        }

        return $this->currentState->getTransitions();
    }

    /**
     * {@inheritdoc}
     */
    public function canTransitionTo($state)
    {
        if (!$this->booted) {
            throw new StateMachineException("Statemachine is not booted");
        }

        return in_array($state, $this->currentState->getTransitions());
    }

    /**
     * {@inheritdoc}
     */
    public function transitionTo($state)
    {
        if (!$this->booted) {
            throw new StateMachineException("Statemachine is not booted");
        }

        if (!$this->canTransitionTo($state)) {
            throw new StateMachineException(
                sprintf(
                    "There's no transition defined from (%s) to (%s), allowed transitions to : [ %s ]",
                    $this->currentState->getName(),
                    $state,
                    implode(',', $this->currentState->getTransitions())
                )
            );
        }
        $transitionName = $this->currentState->getName().'_'.$state;
        $transition = $this->transitions[$transitionName];
        $transitionEvent = new TransitionEvent($this->object, $transition);

        //Execute guards
        /** @var TransitionEvent $transitionEvent */
        $transitionEvent = $this->eventDispatcher->dispatch(Events::EVENT_ON_GUARD, $transitionEvent);
        $this->messages = $transitionEvent->getMessages();

        if ($transitionEvent->isPropagationStopped()) {
            return false;
        }
        //Execute pre transitions
        $transitionEvent = $this->eventDispatcher->dispatch(Events::EVENT_PRE_TRANSITION, $transitionEvent);
        $this->messages = $transitionEvent->getMessages();

        if ($transitionEvent->isPropagationStopped()) {
            return false;
        }

        //change state
        $this->currentState = $this->states[$state];
        $this->stateAccessor->setState($this->object, $state);

        //Execute post transitions
        $this->eventDispatcher->dispatch(Events::EVENT_POST_TRANSITION, $transitionEvent);
        $this->messages = $transitionEvent->getMessages();

        return true;
    }

    /**
     * @return array
     */
    public function getMessages()
    {
        return $this->messages;
    }

    /**
     * Find the initial state in the state machine
     * @return StateInterface
     */
    private function getInitialState()
    {
        /** @var StateInterface $state */
        foreach ($this->states as $state) {
            if ($state->isInitial()) {
                return $state;
            }
        }
    }

    /**
     * Add transitions to states, triggered after booting
     */
    private function boundTransitionsToStates()
    {
        /** @var StateInterface $state */
        foreach ($this->states as $state) {
            $allowedTransitions = [];
            $allowedTransitionsObjects = [];
            /** @var TransitionInterface $transition */
            foreach ($this->transitions as $transition) {
                if ($transition->getFromState()->getName() == $state->getName()) {
                    $allowedTransitionsObjects[] = $transition;
                    $allowedTransitions [] = $transition->getToState()->getName();
                }
            }
            $state->setTransitions($allowedTransitions);
            $state->setTransitionObjects($allowedTransitionsObjects);
        }
    }

    /**
     * @param string $transitionName
     *
     * @throws StateMachineException
     */
    private function validateTransition($transitionName)
    {
        if (!isset($this->transitions[$transitionName])) {
            throw new StateMachineException(
                sprintf(
                    "Transition (%s) is not found, allowed transitions [%s]",
                    $transitionName,
                    implode(',', array_keys($this->transitions))
                )
            );
        }
    }
}