<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\UserCreateFormType;
use App\Form\UserEditFormType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/users')]
#[IsGranted('ROLE_ADMIN')]
class UserAdminController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly FormFactoryInterface $formFactory,
        private readonly Security $security,
    ) {
    }

    #[Route('', name: 'user_admin_index', methods: ['GET'])]
    public function index(): Response
    {
        // Fetch all users with item counts
        $usersData = $this->userRepository->findAllWithItemCounts();

        // Create user create form
        $createForm = $this->createForm(UserCreateFormType::class);

        return $this->render('user_admin/index.html.twig', [
            'usersData' => $usersData,
            'createForm' => $createForm->createView(),
        ]);
    }

    #[Route('/create', name: 'user_admin_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $form = $this->createForm(UserCreateFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            // Check email uniqueness
            $existingUser = $this->userRepository->findOneBy(['email' => $data['email']]);
            if ($existingUser) {
                $this->addFlash('error', sprintf('User with email %s already exists', $data['email']));
                return $this->redirectToRoute('user_admin_index');
            }

            // Generate random password
            $password = $this->generateRandomPassword();

            // Create user
            $user = new User();
            $user->setEmail($data['email']);
            $user->setFirstName($data['firstName']);
            $user->setLastName($data['lastName']);
            $user->setIsActive(true);

            // Set roles
            $roles = ['ROLE_USER'];
            if ($data['isAdmin'] ?? false) {
                $roles[] = 'ROLE_ADMIN';
            }
            $user->setRoles($roles);

            // Hash password
            $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
            $user->setPassword($hashedPassword);

            // Save user
            $this->entityManager->persist($user);
            $this->entityManager->flush();

            $this->addFlash('success', sprintf(
                'User %s created successfully! Temporary password: %s',
                $user->getEmail(),
                $password
            ));
        } else {
            $this->addFlash('error', 'Failed to create user. Please check the form for errors.');
        }

        return $this->redirectToRoute('user_admin_index');
    }

    #[Route('/{id}/edit', name: 'user_admin_edit', methods: ['POST'])]
    public function edit(Request $request, User $user): Response
    {
        $currentUser = $this->security->getUser();

        $form = $this->createForm(UserEditFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            // Check email uniqueness (excluding current user)
            $existingUser = $this->userRepository->createQueryBuilder('u')
                ->where('u.email = :email')
                ->andWhere('u.id != :id')
                ->setParameter('email', $data->getEmail())
                ->setParameter('id', $user->getId())
                ->getQuery()
                ->getOneOrNullResult();

            if ($existingUser) {
                $this->addFlash('error', sprintf('User with email %s already exists', $data->getEmail()));
                return $this->redirectToRoute('user_admin_index');
            }

            // Self-protection: Cannot remove own ROLE_ADMIN
            $isAdmin = $form->get('isAdmin')->getData();
            if ($user->getId() === $currentUser->getId() && !$isAdmin && in_array('ROLE_ADMIN', $user->getRoles(), true)) {
                $this->addFlash('error', 'Cannot remove your own admin role');
                return $this->redirectToRoute('user_admin_index');
            }

            // Update roles
            $roles = ['ROLE_USER'];
            if ($isAdmin) {
                $roles[] = 'ROLE_ADMIN';
            }
            $user->setRoles($roles);

            $this->entityManager->flush();

            $this->addFlash('success', sprintf('User %s updated successfully', $user->getEmail()));
        } else {
            $this->addFlash('error', 'Failed to update user');
        }

        return $this->redirectToRoute('user_admin_index');
    }

    #[Route('/{id}/password', name: 'user_admin_password', methods: ['POST'])]
    public function changePassword(User $user): Response
    {
        // Generate new random password
        $password = $this->generateRandomPassword();

        // Hash password
        $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);

        $this->entityManager->flush();

        $this->addFlash('success', sprintf(
            'Password changed for %s. New password: %s',
            $user->getEmail(),
            $password
        ));

        return $this->redirectToRoute('user_admin_index');
    }

    #[Route('/{id}/toggle', name: 'user_admin_toggle', methods: ['POST'])]
    public function toggleActive(User $user): Response
    {
        $currentUser = $this->security->getUser();

        // Self-protection: Cannot disable own account
        if ($user->getId() === $currentUser->getId()) {
            $this->addFlash('error', 'Cannot disable your own account');
            return $this->redirectToRoute('user_admin_index');
        }

        // Toggle active status
        $user->setIsActive(!$user->isActive());
        $this->entityManager->flush();

        $status = $user->isActive() ? 'enabled' : 'disabled';
        $this->addFlash('success', sprintf('User %s %s', $user->getEmail(), $status));

        return $this->redirectToRoute('user_admin_index');
    }

    /**
     * Generate a cryptographically secure random 24-character alphanumeric password
     */
    private function generateRandomPassword(): string
    {
        $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $password = '';
        $max = strlen($chars) - 1;

        for ($i = 0; $i < 24; $i++) {
            $password .= $chars[random_int(0, $max)];
        }

        return $password;
    }
}
