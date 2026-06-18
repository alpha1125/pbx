<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Property;

interface PropertyHealthCalculatorInterface
{
    /**
     * @return array{score:int,category:string,factors:list<array{key:string,label:string,impact:int}>}
     */
    public function calculate(Property $property): array;
}
