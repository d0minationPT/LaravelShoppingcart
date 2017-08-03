<?php

namespace Gloudemans\Shoppingcart;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Session\SessionManager;
use Illuminate\Database\DatabaseManager;
use Illuminate\Contracts\Events\Dispatcher;
use Gloudemans\Shoppingcart\Contracts\Buyable;
use Gloudemans\Shoppingcart\Exceptions\UnknownModelException;
use Gloudemans\Shoppingcart\Exceptions\InvalidRowIDException;
use Gloudemans\Shoppingcart\Exceptions\CartAlreadyStoredException;
use Gloudemans\Shoppingcart\Exceptions\InvalidConditionException;
use Illuminate\Support\Facades\Auth;

class Cart
{
    const DEFAULT_INSTANCE = 'default';

    /**
     * Instance of the session manager.
     *
     * @var \Illuminate\Session\SessionManager
     */
    private $session;

    /**
     * Instance of the event dispatcher.
     * 
     * @var \Illuminate\Contracts\Events\Dispatcher
     */
    private $events;

    /**
     * Holds the current cart instance.
     *
     * @var string
     */
    private $instance;

    
    /**
     * Cart constructor.
     *
     * @param \Illuminate\Session\SessionManager      $session
     * @param \Illuminate\Contracts\Events\Dispatcher $events
     */
    public function __construct(SessionManager $session, Dispatcher $events)
    {
        $this->session = $session;
        $this->events = $events;

        $this->instance(self::DEFAULT_INSTANCE);
        $this->sessionKeyCartConditions = $this->instance.'_conditions';
    }

    /**
     * Set the current cart instance.
     *
     * @param string|null $instance
     * @return \Gloudemans\Shoppingcart\Cart
     */
    public function instance($instance = null)
    {
        $instance = $instance ?: self::DEFAULT_INSTANCE;

        $this->instance = sprintf('%s.%s', 'cart', $instance);
        $this->sessionKeyCartConditions = $this->instance.'_conditions';
        return $this;
    }

    /**
     * Get the current cart instance.
     *
     * @return string
     */
    public function currentInstance()
    {
        return str_replace('cart.', '', $this->instance);
    }

    /**
     * Add an item to the cart.
     *
     * @param mixed     $id
     * @param mixed     $name
     * @param int|float $qty
     * @param float     $price
     * @param array     $options
     * @return \Gloudemans\Shoppingcart\CartItem
     */
    public function add($id, $name = null, $qty = null, $price = null, array $options = [], $conditions = [])
    {
        if (Formatter::isMulti($id)) {
            return array_map(function ($item) {
                return $this->add($item);
            }, $id);
        }
        $cartItem = $this->createCartItem($id, $name, $qty, $price, $options, $conditions);
        $content = $this->getContent();

        if ($content->has($cartItem->rowId)) {
            $cartItem->qty += $content->get($cartItem->rowId)->qty;
        }

        $content->put($cartItem->rowId, $cartItem);
        
        $this->events->fire('cart.added', $cartItem);
        
        $this->session->put($this->instance, $content);
        
        $this->events->fire('cart.saved', $this);
        
        return $cartItem;
    }
   
    /**
     * Update the cart item with the given rowId.
     *
     * @param string $rowId
     * @param mixed  $qty
     * @return \Gloudemans\Shoppingcart\CartItem
     */
    public function update($rowId, $qty)
    {
        $cartItem = $this->get($rowId);

        if ($qty instanceof Buyable) {
            $cartItem->updateFromBuyable($qty);
        } elseif (is_array($qty)) {
            $cartItem->updateFromArray($qty);
        } else {
            $cartItem->qty = $qty;
        }

        $content = $this->getContent();

        if ($rowId !== $cartItem->rowId) {
            $content->pull($rowId);

            if ($content->has($cartItem->rowId)) {
                $existingCartItem = $this->get($cartItem->rowId);
                $cartItem->setQuantity($existingCartItem->qty + $cartItem->qty);
            }
        }

        if ($cartItem->qty <= 0) {
            $this->remove($cartItem->rowId);
            return;
        } else {
            $content->put($cartItem->rowId, $cartItem);
        }

        $this->events->fire('cart.updated', $cartItem);

        $this->session->put($this->instance, $content);

        $this->events->fire('cart.saved', $this);
        
        return $cartItem;
    }

    /**
     * Remove the cart item with the given rowId from the cart.
     *
     * @param string $rowId
     * @return void
     */
    public function remove($rowId)
    {
        $cartItem = $this->get($rowId);

        $content = $this->getContent();

        $content->pull($cartItem->rowId);

        $this->events->fire('cart.removed', $cartItem);

        $this->session->put($this->instance, $content);
    }

    /**
     * Get a cart item from the cart by its rowId.
     *
     * @param string $rowId
     * @return \Gloudemans\Shoppingcart\CartItem
     */
    public function get($rowId)
    {
        $content = $this->getContent();
        
        if ( ! $content->has($rowId)){
            throw new InvalidRowIDException("The cart does not contain rowId {$rowId}.");
        }
        return $content->get($rowId);
    }

    /**
     * Destroy the current cart instance.
     *
     * @return void
     */
    public function destroy()
    {
        $this->session->remove($this->instance);
    }

    /**
     * Get the content of the cart.
     *
     * @return \Illuminate\Support\Collection
     */
    public function content()
    {
        if (is_null($this->session->get($this->instance))) {
            return new Collection([]);
        }

        return $this->session->get($this->instance);
    }

    /**
     * Get the number of items in the cart.
     *
     * @return int|float
     */
    public function count()
    {
        $content = $this->getContent();

        return $content->sum('qty');
    }

    /**
     * the new total in which conditions are already applied
     *
     * @return float
     */
    public function total($formatted = false)
    {
        
        $cart = $this->getContent();
        
        $subtotal = $cart->sum(function($item)
        {
            return $item->getPriceSumWithConditions();
        });
        
        $newTotal = 0.00;
        $process = 0;
        $conditions = $this->getConditions();
        // if no conditions were added, just return the sub total
        if( ! $conditions->count() ){
            $newTotal = $subtotal;
        }else{
            
            $conditions->each(function($cond) use ($subtotal, &$newTotal, &$process) {
                if( $cond->getTarget() === CartCondition::TARGET_TOTAL ){
                    $toBeCalculated = $process > 0 ? $newTotal : $subtotal;
                    $newTotal = $cond->applyCondition($toBeCalculated);
                    $process++;
                }
            });
            if($process === 0){
                $newTotal = $subtotal;
            }
        }
        return Formatter::numberFormat($newTotal, $formatted);
    }
    
    /**
     * the tax in which conditions are already applied
     *
     * @return float
     */
    public function tax($formatted = false)
    {
        $tax = 0.00;
        foreach($this->getContent() as $cartItem){
            $tax += $cartItem->getPriceSumWithConditions() - $cartItem->getPriceSum();
        }
        return Formatter::numberFormat($tax, $formatted);
    }

    
        /**
     * get cart sub total
     * @param bool $formatted
     * @return float
     */
    public function subtotal($formatted = false)
    {
        $cart = $this->getContent();
        
        $sum = $cart->sum(function($item)
        {
            return $item->getPriceSumWithoutVAT();
        });
        return Formatter::numberFormat($sum, $formatted);
    }

    /**
     * Search the cart content for a cart item matching the given search closure.
     *
     * @param \Closure $search
     * @return \Illuminate\Support\Collection
     */
    public function search(Closure $search)
    {
        $content = $this->getContent();

        return $content->filter($search);
    }

    /**
     * Associate the cart item with the given rowId with the given model.
     *
     * @param string $rowId
     * @param mixed  $model
     * @return void
     */
    public function associate($rowId, $model)
    {
        if(is_string($model) && ! class_exists($model)) {
            throw new UnknownModelException("The supplied model {$model} does not exist.");
        }

        $cartItem = $this->get($rowId);

        $cartItem->associate($model);

        $content = $this->getContent();

        $content->put($cartItem->rowId, $cartItem);

        $this->session->put($this->instance, $content);
    }
    

    /**
     * Store an the current instance of the cart.
     *
     * @param mixed $identifier
     * @return void
     */
    public function store($identifier)
    {
        $content = $this->getContent();

        if ($this->storedCartWithIdentifierExists($identifier)) {
            throw new CartAlreadyStoredException("A cart with identifier {$identifier} was already stored.");
        }

        $this->getConnection()->table($this->getTableName())->insert([
            'identifier' => $identifier,
            'instance' => $this->currentInstance(),
            'content' => serialize($content),
            'user_id' => Auth::id()
        ]);

        $this->events->fire('cart.stored');
    }
    
     /**
     * Save the current instance of the cart.
     *
     * @param mixed $identifier
     * @return void
     */
    public function save($identifier)
    {
        $content = $this->getContent();

        if($this->storedCartWithIdentifierExists($identifier)) {
            $this->getConnection()->table($this->getTableName())
                    ->where('identifier', $identifier)
                    ->update([
                        'instance' => $this->currentInstance(),
                        'content' => serialize($content)
                    ]);
        }else{
            $this->getConnection()->table($this->getTableName())->insert([
                'identifier' => $identifier,
                'instance' => $this->currentInstance(),
                'content' => serialize($content),
                'user_id' => Auth::id()
            ]);
        }

        $this->events->fire('cart.stored');
    }

    /**
     * Restore the cart with the given identifier.
     *
     * @param mixed $identifier
     * @return void
     */
    public function restore($identifier, bool $delete = false)
    {
        if( ! $this->storedCartWithIdentifierExists($identifier)) {
            return;
        }

        $stored = $this->getConnection()->table($this->getTableName())
            ->where('identifier', $identifier)->first();

        $storedContent = unserialize($stored->content);

        $currentInstance = $this->currentInstance();

        $this->instance($stored->instance);

        $content = $this->getContent();

        foreach ($storedContent as $cartItem) {
            $content->put($cartItem->rowId, $cartItem);
        }

        $this->events->fire('cart.restored');

        $this->session->put($this->instance, $content);

        $this->instance($currentInstance);
        if($delete){
            $this->getConnection()->table($this->getTableName())
                ->where('identifier', $identifier)->delete();
        }
    }
    
     /**
     * Get the cart with the given identifier from the DB.
     *
     * @param mixed $identifier
     * @return void
     */
    public function getFromDB($identifier)
    {
        if( ! $this->storedCartWithIdentifierExists($identifier)) {
            return;
        }

        $stored = $this->getConnection()->table($this->getTableName())
            ->where('identifier', $identifier)->first();

        $storedContent = unserialize($stored->content);

        $currentInstance = $this->currentInstance();

        $this->instance($stored->instance);

        $content = $this->getContent();

        foreach ($storedContent as $cartItem) {
            $content->put($cartItem->rowId, $cartItem);
        }

        $this->instance($currentInstance);

        return $this;
    }

    /**
     * Magic method to make accessing the total, tax and subtotal properties possible.
     *
     * @param string $attribute
     * @return float|null
     */
    
    public function __get($attribute)
    {
        if($attribute === 'total') {
            return $this->total();
        }

        if($attribute === 'subtotal') {
            return $this->subtotal();
        }

        return null;
    }
    
    
    /**
     * Get the carts content, if there is no cart content set yet, return a new empty Collection
     *
     * @return \Illuminate\Support\Collection
     */
    protected function getContent()
    {
        $content = $this->session->has($this->instance)
            ? $this->session->get($this->instance)
            : new Collection;

        return $content;
    }

    /**
     * Create a new CartItem from the supplied attributes.
     *
     * @param mixed     $id
     * @param mixed     $name
     * @param int|float $qty
     * @param float     $price
     * @param array     $options
     * @return \Gloudemans\Shoppingcart\CartItem
     */
    private function createCartItem($id, $name, $qty, $price, array $options, array $conditions)
    {
        if ($id instanceof Buyable) {
            $cartItem = CartItem::fromBuyable($id, $qty ?: []);
            $cartItem->setQuantity($name ?: 1);
            $cartItem->associate($id);
        } elseif (is_array($id)) {
            $cartItem = CartItem::fromArray($id);
            $cartItem->setQuantity($id['qty']);
        } else {
            $cartItem = CartItem::fromAttributes($id, $name, $price, $options, $conditions);
            $cartItem->setQuantity($qty);
        }
        
        return $cartItem;
    }

    

    /**
     * @param $identifier
     * @return bool
     */
    private function storedCartWithIdentifierExists($identifier)
    {
        return $this->getConnection()->table($this->getTableName())->where('identifier', $identifier)->exists();
    }

    /**
     * Get the database connection.
     *
     * @return \Illuminate\Database\Connection
     */
    private function getConnection()
    {
        $connectionName = $this->getConnectionName();

        return app(DatabaseManager::class)->connection($connectionName);
    }

    /**
     * Get the database table name.
     *
     * @return string
     */
    private function getTableName()
    {
        return config('cart.database.table', 'shoppingcart');
    }

    /**
     * Get the database connection name.
     *
     * @return string
     */
    private function getConnectionName()
    {
        $connection = config('cart.database.connection');

        return is_null($connection) ? config('database.default') : $connection;
    }

    
    
     /**
     * add a condition on the cart
     *
     * @param CartCondition|array $condition
     * @return $this
     * @throws InvalidConditionException
     */
    public function condition($condition)
    {
        if( is_array($condition) ){
            foreach($condition as $c){
                $this->condition($c);
            }
            return $this;
        }
        if( ! $condition instanceof CartCondition ){
            throw new InvalidConditionException('Argument 1 must be an instance of '.CartCondition::class);
        }
        $conditions = $this->getConditions();
        // Check if order has been applied
        if($condition->order === 0) {
            $last = $conditions->last();
            $condition->setOrder(!is_null($last) ? $last->order + 1 : 1);
        }
        $conditions->put($condition->name, $condition);
        $conditions = $conditions->sortBy(function ($condition, $key) {
            return $condition->order;
        });
        $this->saveConditions($conditions);
        return $this;
    }
     /**
     * get conditions applied on the cart
     *
     * @return CartConditionCollection
     */
    public function getConditions()
    {
        return new CartConditionCollection($this->session->get($this->sessionKeyCartConditions));
    }
    /**
     * get condition applied on the cart by its name
     *
     * @param $conditionName
     * @return CartCondition
     */
    public function getCondition($conditionName)
    {
        return $this->getConditions()->get($conditionName);
    }
    /**
    * Get all the condition filtered by Type
    * Please Note that this will only return condition added on cart bases, not those conditions added
    * specifically on an per item bases
    *
    * @param $type
    * @return CartConditionCollection
    */
    public function getConditionsByType($type)
    {
        return $this->getConditions()->filter(function(CartCondition $condition) use ($type)
        {
            return $condition->type == $type;
        });
    }
    /**
     * Remove all the condition with the $type specified
     * Please Note that this will only remove condition added on cart bases, not those conditions added
     * specifically on an per item bases
     *
     * @param $type
     * @return $this
     */
    public function removeConditionsByType($type)
    {
        $this->getConditionsByType($type)->each(function($condition)
        {
            $this->removeCartCondition($condition->name);
        });
    }
    /**
     * save the cart conditions
     *
     * @param $conditions
     */
    protected function saveConditions($conditions)
    {
        $this->session->put($this->sessionKeyCartConditions, $conditions);
        $this->events->fire('cart.saved', $this);
    }
        /**
     * add condition on an existing item on the cart
     *
     * @param int|string $rowId
     * @param CartCondition $itemCondition
     * @return $this
     */
    public function addItemCondition($rowId, CartCondition $itemCondition)
    {
        if( $product = $this->get($rowId) )
        {
            // we need to copy first to a temporary variable to hold the conditions
            // to avoid hitting this error "Indirect modification of overloaded element of Darryldecode\Cart\ItemCollection has no effect"
            // this is due to laravel Collection instance that implements Array Access
            // // see link for more info: http://stackoverflow.com/questions/20053269/indirect-modification-of-overloaded-element-of-splfixedarray-has-no-effect
            $itemConditionTempHolder = $product->conditions ?: [];
            if( is_array($itemConditionTempHolder) )
            {
                array_push($itemConditionTempHolder, $itemCondition);
            }
            else
            {
                $itemConditionTempHolder->put($itemCondition->name, $itemCondition);
            }
            
            $this->update($rowId, [
                'conditions' => $itemConditionTempHolder // the newly updated conditions
            ]);
        }
        return $this;
    }
    
    
    /**
     * removes a condition on a cart by condition name,
     * this can only remove conditions that are added on cart bases not conditions that are added on an item/product.
     * If you wish to remove a condition that has been added for a specific item/product, you may
     * use the removeItemCondition(itemId, conditionName) method instead.
     *
     * @param $conditionName
     * @return void
     */
    public function removeCartCondition($conditionName)
    {
        $conditions = $this->getConditions();
        $conditions->pull($conditionName);
        $this->saveConditions($conditions);
    }
    
    /**
     * check if an item has condition
     *
     * @param $item
     * @return bool
     */
    protected function itemHasConditions($item)
    {
        if( ! isset($item->conditions) ) return false;
        if( is_array($item->conditions) )
        {
            return count($item->conditions) > 0;
        }
        $conditionInstance = CartCondition::class;
        if( $item->conditions instanceof $conditionInstance ){
            return true;
        }
        return false;
    }
    
    /**
     * remove a condition that has been applied on an item that is already on the cart
     *
     * @param $rowId
     * @param $conditionName
     * @return bool
     */
    public function removeItemCondition($rowId, $conditionName)
    {
        if( ! $item = $this->getContent()->get($rowId) )
        {
            return false;
        }
        if( $this->itemHasConditions($item) )
        {
            // NOTE:
            // we do it this way, we get first conditions and store
            // it in a temp variable $originalConditions, then we will modify the array there
            // and after modification we will store it again on $item->conditions
            // This is because of ArrayAccess implementation
            // see link for more info: http://stackoverflow.com/questions/20053269/indirect-modification-of-overloaded-element-of-splfixedarray-has-no-effect
            $tempConditionsHolder = $item->conditions;
            // if the item's conditions is in array format
            // we will iterate through all of it and check if the name matches
            // to the given name the user wants to remove, if so, remove it
            if( is_array($tempConditionsHolder) )
            {
                foreach($tempConditionsHolder as $k => $condition)
                {
                    if( $condition->name == $conditionName )
                    {
                        unset($tempConditionsHolder[$k]);
                    }
                }
                $item->conditions = $tempConditionsHolder;
            }
            // if the item condition is not an array, we will check if it is
            // an instance of a Condition, if so, we will check if the name matches
            // on the given condition name the user wants to remove, if so,
            // lets just make $item->conditions an empty array as there's just 1 condition on it anyway
            else
            {
                $conditionInstance = CartCondition::class;
                if ($item->conditions instanceof $conditionInstance){
                    if ($tempConditionsHolder->name == $conditionName){
                        $item->conditions = [];
                    }
                }
            }
        }
        $this->update($rowId, [
            'conditions' => $item->conditions
        ]);
        return true;
    }
    /**
     * remove all conditions that has been applied on an item that is already on the cart
     *
     * @param $rowId
     * @return bool
     */
    public function clearItemConditions($rowId)
    {
        if( ! $item = $this->getContent()->get($rowId) )
        {
            return false;
        }
        $this->update($rowId, [
            'conditions' => []
        ]);
        return true;
    }
    /**
     * clears all conditions on a cart,
     * this does not remove conditions that has been added specifically to an item/product.
     * If you wish to remove a specific condition to a product, you may use the method: removeItemCondition($rowId, $conditionName)
     *
     * @return void
     */
    public function clearCartConditions()
    {
        $this->session->put(
            $this->sessionKeyCartConditions,
            []
        );
    }
    

}
