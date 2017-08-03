<?php

namespace Gloudemans\Shoppingcart;

use Illuminate\Contracts\Support\Arrayable;
use Gloudemans\Shoppingcart\Contracts\Buyable;
use Illuminate\Contracts\Support\Jsonable;

class CartItem implements Arrayable, Jsonable
{
    /**
     * The rowID of the cart item.
     *
     * @var string
     */
    public $rowId;

    /**
     * The ID of the cart item.
     *
     * @var int|string
     */
    public $id;

    /**
     * The quantity for this cart item.
     *
     * @var int|float
     */
    public $qty;

    /**
     * The name of the cart item.
     *
     * @var string
     */
    public $name;

    /**
     * The price without TAX of the cart item.
     *
     * @var float
     */
    public $price;

    /**
     * The options for this cart item.
     *
     * @var array
     */
    public $options;
    
    /**
     * The options for this cart item.
     *
     * @var array
     */
    public $conditions;

    /**
     * The FQN of the associated model.
     *
     * @var string|null
     */
    private $associatedModel = null;

    /**
     * CartItem constructor.
     *
     * @param int|string $id
     * @param string     $name
     * @param float      $price
     * @param array      $options
     */
    public function __construct($id, $name, $price, array $options = [], array $conditions = [])
    {
        if(empty($id)) {
            throw new \InvalidArgumentException('Please supply a valid identifier.');
        }
        if(empty($name)) {
            throw new \InvalidArgumentException('Please supply a valid name.');
        }
        if(strlen($price) < 0 || ! is_numeric($price)) {
            throw new \InvalidArgumentException('Please supply a valid price.');
        }

        $this->id       = $id;
        $this->name     = $name;
        $this->price    = floatval($price);
        $this->options  = new CartItemOptions($options);
        if(!empty($conditions)){            
            $this->conditions = new CartConditionCollection($conditions);
        }
        $this->rowId = $this->generateRowId($id, $options);
    }

    /**
     * Returns the formatted price without TAX.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return string
     */
    public function price($formatted = false, $decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        return Formatter::numberFormat($this->subtotal(), true, $decimals, $decimalPoint, $thousandSeperator);
    }
    

    /**
     * Returns the formatted subtotal.
     * Subtotal is price for whole CartItem without TAX
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return string
     */
    public function subtotal($formatted = false, $decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        return Formatter::numberFormat($this->getPriceSumWithoutVAT(), $formatted, $decimals, $decimalPoint, $thousandSeperator);
    }
    
   /**
     * the new total in which conditions are already applied
     *
     * @return float
     */
    public function total($formatted = false, $decimals = null, $decimalPoint = null, $thousandSeperator = null){
      
        return Formatter::numberFormat($this->getPriceWithConditions(), $formatted, $decimals, $decimalPoint, $thousandSeperator);
    }


    /**
     * Set the quantity for this cart item.
     *
     * @param int|float $qty
     */
    public function setQuantity($qty)
    {
        if(empty($qty) || ! is_numeric($qty))
            throw new \InvalidArgumentException('Please supply a valid quantity.');

        $this->qty = $qty;
    }

    /**
     * Update the cart item from a Buyable.
     *
     * @param \Gloudemans\Shoppingcart\Contracts\Buyable $item
     * @return void
     */
    public function updateFromBuyable(Buyable $item)
    {
        $this->id       = $item->getBuyableIdentifier($this->options);
        $this->name     = $item->getBuyableDescription($this->options);
        $this->price    = $item->getBuyablePrice($this->options);
    }

    /**
     * Update the cart item from an array.
     *
     * @param array $attributes
     * @return void
     */
    public function updateFromArray(array $attributes)
    {
        $this->id       = array_get($attributes, 'id', $this->id);
        $this->qty      = array_get($attributes, 'qty', $this->qty);
        $this->name     = array_get($attributes, 'name', $this->name);
        $this->price    = array_get($attributes, 'price', $this->price);
        $this->options  = new CartItemOptions(array_get($attributes, 'options', $this->options));
        $this->conditions  = new CartConditionCollection(array_get($attributes, 'conditions', $this->conditions));
        $this->rowId = $this->generateRowId($this->id, $this->options->all());
    }

    /**
     * Associate the cart item with the given model.
     *
     * @param mixed $model
     * @return \Gloudemans\Shoppingcart\CartItem
     */
    public function associate($model)
    {
        $this->associatedModel = is_string($model) ? $model : get_class($model);
        
        return $this;
    }

   

    /**
     * Get an attribute from the cart item or get the associated model.
     *
     * @param string $attribute
     * @return mixed
     */
    public function __get($attribute)
    {
        
        if(property_exists($this, $attribute)) {
            return $this->{$attribute};
        }

   
        
        if($attribute === 'subtotal') {
            return $this->subtotal(false);
        }
        
        if($attribute === 'total') {
            return $this->total(false);
        }

       
        if($attribute === 'model') {
            return with(new $this->associatedModel)->find($this->id);
        }

        return null;
    }
    
    /**
     * get the single price in which conditions are already applied
     * @param bool $formatted
     * @return mixed|null
     */
    public function getPriceWithConditions()
    {
        $originalPrice = $this->price;
        $newPrice = 0.00;
        $processed = 0;
        if( $this->hasConditions() ){
            foreach($this->conditions as $condition){
                if( $condition->getTarget() === CartCondition::TARGET_ITEM ){
                    $toBeCalculated = $processed > 0 ? $newPrice : $originalPrice;
                    $newPrice = $condition->applyCondition($toBeCalculated);
                    $processed++;
                }
            }
            return $processed === 0 ? $this->price : $newPrice;
        }
        return $originalPrice;
    }
    
    /**
     * get the single price in which conditions are already applied
     * @param bool $formatted
     * @return mixed|null
     */
    public function getPriceWithoutVAT(){
        $originalPrice = $this->price;
        $newPrice = 0.00;
        $processed = 0;
        if( $this->hasConditions() ){
            
            foreach($this->conditions as $condition){
                if( $condition->getTarget() === CartCondition::TARGET_ITEM && $condition->type !== CartCondition::TYPE_TAX){
                    
                    $toBeCalculated = $processed > 0 ? $newPrice : $originalPrice;
                    $newPrice = $condition->applyCondition($toBeCalculated);
                    $processed++;
                }
            }
            return $processed === 0 ? $this->price : $newPrice;
        }
        
        return $originalPrice;
    }
    /**
     * get the sum of price in which conditions are already applied
     * @param bool $formatted
     * @return mixed|null
     */
    public function getPriceSumWithConditions()
    {
        return $this->getPriceWithConditions() * $this->qty;
    }

    
    /**
     * get the sum of price in which conditions are already applied
     * @param bool $formatted
     * @return mixed|null
     */
    public function getPriceSumWithoutVAT()
    {
        return $this->getPriceWithoutVAT() * $this->qty;
    }
    /**
     * Create a new instance from a Buyable.
     *
     * @param \Gloudemans\Shoppingcart\Contracts\Buyable $item
     * @param array                                      $options
     * @return \Gloudemans\Shoppingcart\CartItem
     */
    public static function fromBuyable(Buyable $item, array $options = [], $conditions = []){
        return new self($item->getBuyableIdentifier($options), $item->getBuyableDescription($options), $item->getBuyablePrice($options), $options, $conditions);
    }

    /**
     * Create a new instance from the given array.
     *
     * @param array $attributes
     * @return \Gloudemans\Shoppingcart\CartItem
     */
    public static function fromArray(array $attributes)
    {
        $options = array_get($attributes, 'options', []);
        $conditions = array_get($attributes, 'conditions', []);
        return new self($attributes['id'], $attributes['name'], $attributes['price'], $options, $conditions);
    }

    /**
     * Create a new instance from the given attributes.
     *
     * @param int|string $id
     * @param string     $name
     * @param float      $price
     * @param array      $options
     * @return \Gloudemans\Shoppingcart\CartItem
     */
    public static function fromAttributes($id, $name, $price, array $options = [], array $conditions = [])
    {
        return new self($id, $name, $price, $options, $conditions);
    }

    /**
     * Generate a unique id for the cart item.
     *
     * @param string $id
     * @param array  $options
     * @return string
     */
    protected function generateRowId($id, array $options)
    {
        ksort($options);

        return md5($id . serialize($options));
    }

    /**
     * Get the instance as an array.
     *
     * @return array
     */
    public function toArray()
    {
        return [
            'rowId'    => $this->rowId,
            'id'       => $this->id,
            'name'     => $this->name,
            'qty'      => $this->qty,
            'price'    => $this->price,
            'options'  => $this->options,
            'conditions' => $this->conditions,
            'subtotal'  => $this->subtotal(),
            'total'     => $this->total(),
        ];
    }

    
    /**
     * Convert the object to its JSON representation.
     *
     * @param int $options
     * @return string
     */
    public function toJson($options = 0)
    {
        return json_encode($this->toArray(), $options);
    }

    /**
     * Get the formatted number.
     *
     * @param float  $value
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return string
     */
    public function getPriceSum()
    {
        return Formatter::numberFormat($this->price * $this->qty, false);
    }
   
    /**
     * check if item has conditions
     *
     * @return bool
     */
    public function hasConditions()
    {
        if( ! isset($this->conditions) ){
            return false;
        }
        if( is_array($this->conditions) )
        {
            return count($this->conditions) > 0;
        }
        $conditionInstance = CartConditionCollection::class;
        if( $this->conditions instanceof $conditionInstance ){
            return true;
        }
        return false;
    }
    
    
}
