- [Home](https://github.com/zencap/state-machine/blob/master/readme.md)
- [Library](https://github.com/zencap/state-machine/blob/master/src/StateMachine/readme.md)
- [Bundle](https://github.com/zencap/state-machine/blob/master/src/StateMachineBundle/Resources/doc/index.md)

## Scope
- SF configuration support for yml files
- Doctrine integrations
  - Wrapping transition into one database transaction
  - Persistent history
- User integration, blameable behavior
- Twig extension with useful filters
- Rendering controller

## Installation

### Step 1: Download Statemachine using composer

Add this to composer.json

``` javascript
    "zencap/state-machine":"master"
```
and
``` javascript
"repositories": [
    {
      "type": "package",
      "package": {
        "name": "zencap/state-machine",
        "version": "master",
        "source": {
          "type": "git",
          "url": "https://github.com/zencap/state-machine.git",
          "reference": "dev"
        },
        "autoload": {
          "psr-4": {
            "StateMachine\\": "src/StateMachine",
            "StateMachineBundle\\": "src/StateMachineBundle"
          }
        }
      }
    }
```

then run
``` bash
$ composer update zencap/state-machine
```

### Step 2: Enable the bundle

Enable the bundle in the kernel:

``` php
<?php
// app/AppKernel.php

public function registerBundles()
{
    $bundles = array(
        // ...
        new StateMachineBundle\StateMachineBundle(),
    );
}
```
### Step 3: Config

``` yaml
#app/config/config/yml
state_machine:
    state_machines:
        bank_transaction:
            object:
                class: AppBundle\Entity\BankTransaction
                property: state
            history_class: AppBundle\Entity\BankTransactionHistory
            states:
              new: { type: initial } #intial state is required
              exported: ~
              bank_processing: ~
              failed: ~
              succeeded: ~
            on_init: { callback: StateMachineBundle\Tests\Listeners\MockListener, method: onInit } #only triggered in first state set
            transitions:
              - { from: [new], to: [ exported ], event: "export" }
              - { from: [exported], to: [ bank_processing ], event: "transfer to bank"}
              - { from: [bank_processing], to: [ succeeded ], event: "transaction confirm"}
              - { from: [succeeded, bank_processing], to: [ failed ], event: "fail" }
            guards:
              - { from: "new", to: "exported", callback: app.test_callback, method: onGuardSuccess }
              - { from: "new", to: "exported", callback: StateMachineBundle\Tests\Listeners\MockListener, method: simpleCallback }
            pre_transitions:
              #...
            post_transitions:
              #...

```

### Step 4: Entity

The stateful entity should implement
`StateMachine\StateMachine\StatefulInterface`, You can use `StateMachine\Traits\StatefulTrait`for ready made implementation as example below

``` php
namespace AppBundle\Entity;

use StateMachine\StateMachine\StatefulInterface;
use StateMachine\Traits\StatefulTrait;

/**
 * BankTransaction
 *
 * @ORM\Table(name="bank_transactions")
 * @ORM\Entity
 */
class BankTransaction implements StatefulInterface
{
    use StatefulTrait;
...
}
```

### Step 5: History class

You need to create `BankTransactionHistory` as valid doctrine entity, this entity will hold the history for the configured statemachine

Example below

``` php
<?php

namespace AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use StateMachineBundle\Entity\History as BaseHistory;

/**
 * @ORM\Entity()
 * @ORM\Table("state_machine_history_bank_transaction")
 */
class BankTransactionHistory extends BaseHistory
{
    /**
     * @var integer
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }
}


```

and update doctrine schema
## Usage


``` php
$em = $this->getDoctrine()->getManager();
$bankTransaction = new BankTransaction();

$em->persist($bankTransaction);

//checks if transition is possible
$bankTransaction->getStateMachine()->canTransitionTo("exported"));
//move to certain state
$bankTransaction->getStateMachine()->transitionTo("exported"));
//check if reached certain state
$bankTransaction->getStateMachine()->hasReached("exported")); //returns true
$bankTransaction->getStateMachine()->hasReached("succeeded")); //returns false
//or by trigger event
$bankTransaction->getStateMachine()->trigger("export"));
//returns current state
$bankTransaction->getStateMachine()->getCurrentState();
//returns allowed transitions
$bankTransaction->getStateMachine()->getAllowedTransitions();
//returns allowed events
$bankTransaction->getStateMachine()->getAllowedEvents();

//History methods
//returns collection of configured history class
$bankTransaction->getStateMachine()->getHistory();

//returns last state change
$bankTransaction->getStateMachine()->getLastStateChange();
```

## Blameable

In order to track which user modified stateful objects history class must implement `StateMachineBundle\Model\BlameableStateChangeInterface`
Same history class but with blameable behavior
``` php
<?php

namespace AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use StateMachineBundle\Entity\History as BaseHistory;
use StateMachineBundle\Model\BlameableStateChangeInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @ORM\Entity()
 * @ORM\Table("state_machine_history_bank_transaction")
 */
class BankTransactionHistory extends BaseHistory implements BlameableStateChangeInterface
{
    /**
     * @var integer
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var UserInterface
     * @ORM\ManyToOne(targetEntity="User")
     * @ORM\JoinColumn(name="user_id", referencedColumnName="id")
     */
    private $user;

    /**
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param UserInterface $user
     */
    public function setUser(UserInterface $user)
    {
        $this->user = $user;
    }

    /**
     * @return UserInterface
     */
    public function getUser()
    {
        return $this->user;
    }
}
```
and update doctrine schema

Now with every state change the user_id will be recorded

## Rendering

### Display one statemachine
In order to render the Graph for specific stateful object object in twig
use the below filter directly in template where you want to display the graph

`{{ object|renderGraph }}`

this will automatically highlight the current state.

### Display all statemachine definitions

in `app/config/routing.yml` add the following:

``` yaml
_state_machine:
    prefix: /statemachines  #change to what you need
    resource: "@StateMachineBundle/Resources/config/routing.yml"
```

in order to override template

- #### Option 1

by changing
``` yaml
state_machines:
    template_layout: StateMachineBundle::layout.html.twig
```

- #### Option 2

Override the templates in `app/Resources/Zencap/StateMachineBundle/views/layout.html.twig` and `app/Resources/Zencap/StateMachineBundle/views/state_machines.html.twig`


## No transaction

By default after state change the object is persisted and flushed, if that's not convenient, you can move from state to another without flusing, by passing `flush` option to false

``` php
$bankTransaction->getStateMachine()->canTransitionTo("exported"), ["transaction"=> false]);

$bankTransaction->getStateMachine()->transitionTo("exported"), ["transaction"=> false]);
```

## Moving to different state in transition callback

### Given transitions:

`T1`: `A` to `B`
`T2`: `A` to `C`


During pre-transition callbacks for `T1` , a decision to be made to move t `C` instead of `B`



``` php

//inside pre-transition callback
$transitionEvent->setTargetState('C');

After setting target state, only pre-transitions (considered as exit action for state `A`) for `T1` will be executed in addition to all  `T2` callbacks


### Disable the statemachine
``` php

StateMachineBundle\Subscriber\LifeCycleEventsSubscriber::$enabled=false;

```

## Transition lifecycle

![SM Flow]
(https://github.com/zencap/state-machine/blob/master/src/StateMachineBundle/Resources/doc/sm_flow.png)

## Configuration reference

``` yaml
state_machine:
    transition_class: StateMachine\Transition\Transition
    state_accessor: statemachine.state_accessor
    history_manager: statemachine.history_manager
    template_layout: StateMachineBundle::layout.html.twig
    db_driver: orm
    on_init: { callback: ~, method: ~ } #init callback while setting the first state
    profiler: false #enable logging + profiler
    state_machines:
        state_machine_name:  #required
            object:
                class: ~  #required the stateful entity
                property: ~ #required the class property that represent states
            history_class: ~ #required
            states: [] #required
            transitions: [] #required { from: ~, to: ~, event: ~}
            guards:[] #optional  #{ transition: ~ ,callback: ~, method: ~}
            pre_transitions:[] #optional { from: ~, to: ~ ,callback: ~, method: ~}
            post_transitions:[] #optional { from: ~ , to: ~, callback: ~, method: ~}
            post_commits:[] #optional { from: ~ , to: ~, callback: ~, method: ~}


```


##TODO

- Add versionable statemachine possible in StateMachine factory.
- Configuration rendering properties for GraphicZ.
