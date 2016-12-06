<?php 

namespace Illuminate\Database\Eloquent;

class SyncAttributes
{

  public $keys = [];
  public $attributes = [];

  public function __construct($keys, array $attributes = []) {

    $this->keys       = (array)$keys;
    $this->attributes = $attributes;
  }

  public static function make($keys, array $attributes = []) {

    return new self($keys, $attributes);
  }


  public function makeUnique($keysToUse) {

//TODO: user arr::makeHashKey
    $unique = '';
    foreach (array_keys($keysToUse) as $no => $key) {
      $unique .= $key . (isset($this->keys[ $keysToUse[ $key ] ]) ? $this->keys[ $keysToUse[ $key ] ] : $this->keys[ $no ]);
    }

    return $unique;
  }

  /**
   * Convert the instance to an array.
   *
   * @return array
   */
  public function toArray() {

    return ['keys' => $this->keys, 'attributes' => $this->attributes];
  }

  /**
   * Convert the instance to JSON.
   *
   * @param  int $options
   *
   * @return string
   */
  public function toJson($options = 0) {

    return json_encode($this->toArray(), $options);
  }
}