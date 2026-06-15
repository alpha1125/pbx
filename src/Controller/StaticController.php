<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StaticController extends AbstractController
{
    #[Route('/privacy', name: 'app_static')]
    public function index(): Response
    {
        return $this->render('static/privacy.html.twig', [
            'controller_name' => 'StaticController',
        ]);
    }
}
