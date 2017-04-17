<?php

namespace Gloudemans\Shoppingcart;

use Illuminate\Contracts\Support\Arrayable;

class CartCondition
{
    const TYPE_TAX = 'tax',
          TYPE_DISCOUNT = 'discount',
          TYPE_FEE = 'fee',
            
          TARGET_SUBTOTAL = 1,
          TARGET_ITEM = 3;
           
          
    /**
     * A unique name that defines the condition.
     *
     * @var string
     */
    public $name;
    /**
     * The type of the cart condition.
     *
     * @var int
     */
    public $type;
    /**
     * The target of the cart condition.
     *
     * @var int
     */
    private $target;
    /**
     * The value of the cart condition.
     *
     * @var float
     */
    public $value;
    /**
     * The order of the cart condition.
     *
     * @var int
     * 
     */
    public $order;
    
    public function __construct($name, $type, $target, $value, $order = 0) {
       $this->name = $name;
       $this->type = $type;
       $this->setTarget($target);
       $this->value = $value;
       $this->order = $order;
    }
    
    /**
     * Set the order to apply this condition. If no argument order is applied we return 0 as
     * indicator that no assignment has been made
     * @param int $order
     * @return Integer
     */
    public function setOrder($order = 1)
    {
        $this->order = is_numeric($this->order) ? (int)$this->order : 0;
    }
    
    /**
     * Sets the target for this condition
     * @param int $target
     * @return Integer
     */
    public function setTarget($target)
    {
        if($target !== self::TARGET_ITEM && $target !== self::TARGET_SUBTOTAL){
            throw new Exceptions\InvalidConditionException('Invalid target');
        }
        $this->target = $target;
    }
    
    /**
     * Gets the target for this condition
     * * @return Integer
     */
    public function getTarget(){
        return $this->target;
    }
    
   
    
     /**
     * apply condition to total or subtotal
     *
     * @param $totalOrSubTotalOrPrice
     * @return float
     */
    public function applyCondition($totalOrSubTotalOrPrice)
    {
        return $this->apply($totalOrSubTotalOrPrice, $this->value);
    }

    /**
     * get the calculated value of this condition supplied by the subtotal|price
     *
     * @param $totalOrSubTotalOrPrice
     * @return mixed
     */
    public function getCalculatedValue($totalOrSubTotalOrPrice)
    {
        $this->apply($totalOrSubTotalOrPrice, $this->value);

        return $this->parsedRawValue;
    }

    /**
     * apply condition
     *
     * @param $totalOrSubTotalOrPrice
     * @param $conditionValue
     * @return float
     */
    protected function apply($totalOrSubTotalOrPrice, $conditionValue)
    {
        // if value has a percentage sign on it, we will get first
        // its percentage then we will evaluate again if the value
        // has a minus or plus sign so we can decide what to do with the
        // percentage, whether to add or subtract it to the total/subtotal/price
        // if we can't find any plus/minus sign, we will assume it as plus sign
        if( $this->valueIsPercentage($conditionValue) )
        {
            if( $this->valueIsToBeSubtracted($conditionValue) )
            {
                $value = self::normalizePrice( $this->cleanValue($conditionValue) );

                $this->parsedRawValue = $totalOrSubTotalOrPrice * ($value / 100);

                $result = floatval($totalOrSubTotalOrPrice - $this->parsedRawValue);
            }
            else if ( $this->valueIsToBeAdded($conditionValue) )
            {
                $value = self::normalizePrice( $this->cleanValue($conditionValue) );

                $this->parsedRawValue = $totalOrSubTotalOrPrice * ($value / 100);

                $result = floatval($totalOrSubTotalOrPrice + $this->parsedRawValue);
            }
            else
            {
                $value = self::normalizePrice($conditionValue);

                $this->parsedRawValue = $totalOrSubTotalOrPrice * ($value / 100);

                $result = floatval($totalOrSubTotalOrPrice + $this->parsedRawValue);
            }
        }

        // if the value has no percent sign on it, the operation will not be a percentage
        // next is we will check if it has a minus/plus sign so then we can just deduct it to total/subtotal/price
        else
        {
            if( $this->valueIsToBeSubtracted($conditionValue) )
            {
                $this->parsedRawValue = self::normalizePrice( $this->cleanValue($conditionValue) );

                $result = floatval($totalOrSubTotalOrPrice - $this->parsedRawValue);
            }
            else if ( $this->valueIsToBeAdded($conditionValue) )
            {
                $this->parsedRawValue = self::normalizePrice( $this->cleanValue($conditionValue) );

                $result = floatval($totalOrSubTotalOrPrice + $this->parsedRawValue);
            }
            else
            {
                $this->parsedRawValue = self::normalizePrice($conditionValue);

                $result = floatval($totalOrSubTotalOrPrice + $this->parsedRawValue);
            }
        }

        // Do not allow items with negative prices.
        return $result < 0 ? 0.00 : $result;
    }

    /**
     * check if value is a percentage
     *
     * @param $value
     * @return bool
     */
    protected function valueIsPercentage($value)
    {
        return (preg_match('/%/', $value) == 1);
    }

    /**
     * check if value is a subtract
     *
     * @param $value
     * @return bool
     */
    protected function valueIsToBeSubtracted($value)
    {
        return (preg_match('/\-/', $value) == 1);
    }

    /**
     * check if value is to be added
     *
     * @param $value
     * @return bool
     */
    protected function valueIsToBeAdded($value)
    {
        return (preg_match('/\+/', $value) == 1);
    }

    /**
     * removes some arithmetic signs (%,+,-) only
     *
     * @param $value
     * @return mixed
     */
    protected function cleanValue($value)
    {
        return str_replace(array('%','-','+'),'',$value);
    }
    
    public static function normalizePrice($price)
    {
        return (is_string($price)) ? floatval($price) : $price;
    }
    
}