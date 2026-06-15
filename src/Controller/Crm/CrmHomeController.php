<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CrmHomeController extends AbstractController
{
    #[Route('/crm', name: 'crm_home', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->redirectToRoute('crm_property_index');
    }
}
