<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class TestController extends AbstractController
{
    #[Route('/test/currency', name: 'test_currency')]
    #[IsGranted('ROLE_USER')]
    public function testCurrency(): Response
    {
        return $this->render('test/currency.html.twig');
    }
}
