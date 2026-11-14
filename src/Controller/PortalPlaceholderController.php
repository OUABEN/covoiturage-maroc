<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PortalPlaceholderController extends AbstractController
{
    #[Route('/admin', name: 'admin', methods: ['GET'])]
    public function admin(): Response
    {
        return new Response('Administration', Response::HTTP_OK);
    }
}
