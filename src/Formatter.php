<?php namespace Gloudemans\Shoppingcart;

class Formatter {

    /**
     * normalize price
     *
     * @param $price
     * @return float
     */
    public static function normalizePrice($price)
    {
        return (is_string($price)) ? floatval($price) : $price;
    }

    /**
     * Check if the item is a multidimensional array or an array of Buyables.
     *
     * @param mixed $item
     * @return bool
     */
    public static function isMulti($item)
    {
        if ( ! is_array($item)) return false;

        return is_array(head($item)) || head($item) instanceof Buyable;
    }

    /**
     * check if variable is set and has value, return a default value
     *
     * @param $var
     * @param bool|mixed $default
     * @return bool|mixed
     */
    public static function issetAndHasValueOrAssignDefault(&$var, $default = false)
    {
        if( (isset($var)) && ($var!='') ) return $var;

        return $default;
    }

    /**
     * Get the Formated number
     *
     * @param $value
     * @param $formatted
     * @param $decimals
     * @param $decimalPoint
     * @param $thousandSeperator
     * @return string
     */
    private function numberFormat($value, $formatted = true, $decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        if(!$formatted){
            return $value;
        }
        if(is_null($decimals)){
            $decimals = is_null(config('cart.format.decimals')) ? 2 : config('cart.format.decimals');
        }
        if(is_null($decimalPoint)){
            $decimalPoint = is_null(config('cart.format.decimal_point')) ? '.' : config('cart.format.decimal_point');
        }
        if(is_null($thousandSeperator)){
            $thousandSeperator = is_null(config('cart.format.thousand_seperator')) ? ',' : config('cart.format.thousand_seperator');
        }

        return number_format($value, $decimals, $decimalPoint, $thousandSeperator);
    }
}