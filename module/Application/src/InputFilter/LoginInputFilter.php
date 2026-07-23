<?php

namespace Application\InputFilter;

use Laminas\InputFilter\InputFilter;
use Laminas\Validator\EmailAddress;
use Laminas\Validator\NotEmpty;
use Laminas\Validator\StringLength;

class LoginInputFilter extends InputFilter {
    public function __construct() {
        $this->add([
            'name' => 'email',
            'required' => true,
            'filters' => [
                ['name' => 'StringTrim'],
                ['name' => 'StripTags'],
            ],
            'validators' => [
                [
                    'name' => NotEmpty::class,
                    'options' => [
                        'message' => 'Por favor, preencha o e-mail.'
                    ],
                    'break_chain_on_failure' => true,
                ],
                [
                    'name' => EmailAddress::class,
                    'options' => [
                        'message' => 'Formato de e-mail inválido.'
                    ]
                ]
            ]
        ]);

        $this->add([
            'name' => 'password',
            'required' => true,
            'validators' => [
                [
                    'name' => NotEmpty::class,
                    'options' => [
                        'message' => 'Por favor, preencha a senha.'
                    ]
                ]
            ]
        ]);
    }
}
