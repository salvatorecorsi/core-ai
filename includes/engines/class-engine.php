<?php

abstract class AI_Engine {

    protected $model;
    protected $api_key;

    public function __construct($model, $api_key) {
        $this->model = $model;
        $this->api_key = $api_key;
    }

    abstract public function chat(array $messages, array $options = []);

    abstract public static function detect($model);
}
