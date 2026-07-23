<?php

namespace Application\InputFilter;

use Laminas\InputFilter\InputFilter;
use Laminas\Validator\EmailAddress;
use Laminas\Validator\NotEmpty;
use Laminas\Validator\StringLength;
use Laminas\Validator\Identical;

class RegisterInputFilter extends InputFilter {
    public function __construct() {
        $this->add([
            'name' => 'username',
            'required' => true,
            'filters' => [
                ['name' => 'StringTrim'],
                ['name' => 'StripTags'],
            ],
            'validators' => [
                [
                    'name' => NotEmpty::class,
                    'options' => [
                        'message' => 'Por favor, preencha o nome de usuário.'
                    ],
                    'break_chain_on_failure' => true,
                ],
                [
                    'name' => StringLength::class,
                    'options' => [
                        'min' => 3,
                        'message' => 'O nome de usuário deve ter pelo menos 3 caracteres.'
                    ]
                ]
            ]
        ]);

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
                    ],
                    'break_chain_on_failure' => true,
                ],
                [
                    'name' => StringLength::class,
                    'options' => [
                        'min' => 6,
                        'message' => 'A senha deve ter pelo menos 6 caracteres.'
                    ]
                ]
            ]
        ]);

        $this->add([
            'name' => 'password_confirm',
            'required' => true,
            'validators' => [
                [
                    'name' => NotEmpty::class,
                    'options' => [
                        'message' => 'Por favor, confirme a sua senha.'
                    ],
                    'break_chain_on_failure' => true,
                ],
                [
                    'name' => Identical::class,
                    'options' => [
                        'token' => 'password',
                        'message' => 'As senhas não coincidem.'
                    ]
                ]
            ]
        ]);
    }
}
