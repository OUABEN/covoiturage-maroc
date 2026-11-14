<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class RegistrationController extends AbstractController
{
    #[Route('/inscription', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em,
    ): Response {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $plainPassword */
            $plainPassword = (string) $form->get('plainPassword')->getData();
            $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));
            $user->setRoles(['ROLE_USER']);

            $em->persist($user);
            $em->flush();

            $this->addFlash('success', 'Votre compte a été créé avec succès. Vous pouvez maintenant vous connecter (en tant que passager et conducteur).');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
