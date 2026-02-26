<?php

namespace App\Utils\Functions;

class FormatString
{
    public function formatName(string $name = "Sem Nome"): array
    {
        $name = mb_convert_encoding($name, 'UTF-8', 'auto');

        $nameParts  = explode(" ", $name);
        $firstName  = array_shift($nameParts);
        $lastName   = !empty($nameParts) ? array_pop($nameParts) : '';
        $middleName = implode(" ", $nameParts);

        return [
            'full_name'         => $name,
            'first_name'        => $firstName,
            'last_name'         => empty($middleName) ? $firstName : $middleName,
            'abbreviated_name'  => !empty($middleName) ? "{$firstName} {$lastName}" : $firstName,
            'initials'          => mb_strtoupper(mb_substr($firstName, 0, 1) . mb_substr($lastName, 0, 1))
        ];
    }

}