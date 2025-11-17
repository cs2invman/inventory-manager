<?php

namespace App\Controller;

use App\Entity\DiscordConfig;
use App\Entity\DiscordWebhook;
use App\Form\DiscordConfigFormType;
use App\Form\DiscordWebhookFormType;
use App\Repository\DiscordConfigRepository;
use App\Repository\DiscordNotificationRepository;
use App\Repository\DiscordUserRepository;
use App\Repository\DiscordWebhookRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/discord')]
#[IsGranted('ROLE_ADMIN')]
class DiscordAdminController extends AbstractController
{
    public function __construct(
        private readonly DiscordConfigRepository $configRepository,
        private readonly DiscordWebhookRepository $webhookRepository,
        private readonly DiscordUserRepository $userRepository,
        private readonly DiscordNotificationRepository $notificationRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    #[Route('', name: 'discord_admin_index', methods: ['GET'])]
    public function index(): Response
    {
        // Fetch all Discord configurations
        $configs = $this->configRepository->findAll();

        // Fetch all webhooks
        $webhooks = $this->webhookRepository->findAll();

        // Fetch all linked Discord users (with their associated User)
        $discordUsers = $this->userRepository->findAll();

        // Fetch last 10 notifications
        $recentNotifications = $this->notificationRepository->createQueryBuilder('n')
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        // Create forms
        $configForm = $this->createForm(DiscordConfigFormType::class, null, ['configs' => $configs]);
        $webhookForm = $this->createForm(DiscordWebhookFormType::class);

        return $this->render('discord_admin/index.html.twig', [
            'configs' => $configs,
            'webhooks' => $webhooks,
            'discordUsers' => $discordUsers,
            'recentNotifications' => $recentNotifications,
            'configForm' => $configForm->createView(),
            'webhookForm' => $webhookForm->createView(),
        ]);
    }

    #[Route('/config', name: 'discord_admin_config_save', methods: ['POST'])]
    public function saveConfig(Request $request): Response
    {
        $configs = $this->configRepository->findAll();
        $form = $this->createForm(DiscordConfigFormType::class, null, ['configs' => $configs]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            // Update each config based on form data
            foreach ($configs as $config) {
                $key = $config->getConfigKey();
                if (isset($data['config_' . $config->getId()])) {
                    $configData = $data['config_' . $config->getId()];
                    $config->setConfigValue($configData['value'] ?? null);
                    $config->setIsEnabled($configData['enabled'] ?? false);
                }
            }

            $this->entityManager->flush();
            $this->addFlash('success', 'Discord configuration saved successfully');
        } else {
            $this->addFlash('error', 'Failed to save Discord configuration');
        }

        return $this->redirectToRoute('discord_admin_index');
    }

    #[Route('/webhook', name: 'discord_admin_webhook_create', methods: ['POST'])]
    public function createWebhook(Request $request): Response
    {
        $webhook = new DiscordWebhook();
        $form = $this->createForm(DiscordWebhookFormType::class, $webhook);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($webhook);
            $this->entityManager->flush();

            $this->addFlash('success', 'Webhook created successfully');
        } else {
            $this->addFlash('error', 'Failed to create webhook. Please check your input.');
        }

        return $this->redirectToRoute('discord_admin_index');
    }

    #[Route('/webhook/{id}', name: 'discord_admin_webhook_update', methods: ['POST'])]
    public function updateWebhook(Request $request, DiscordWebhook $webhook): Response
    {
        $form = $this->createForm(DiscordWebhookFormType::class, $webhook);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->addFlash('success', 'Webhook updated successfully');
        } else {
            $this->addFlash('error', 'Failed to update webhook. Please check your input.');
        }

        return $this->redirectToRoute('discord_admin_index');
    }

    #[Route('/webhook/{id}/delete', name: 'discord_admin_webhook_delete', methods: ['POST'])]
    public function deleteWebhook(DiscordWebhook $webhook): Response
    {
        $this->entityManager->remove($webhook);
        $this->entityManager->flush();

        $this->addFlash('success', 'Webhook deleted successfully');

        return $this->redirectToRoute('discord_admin_index');
    }

    #[Route('/webhook/{id}/toggle', name: 'discord_admin_webhook_toggle', methods: ['POST'])]
    public function toggleWebhook(DiscordWebhook $webhook): Response
    {
        $webhook->setIsEnabled(!$webhook->getIsEnabled());
        $this->entityManager->flush();

        $status = $webhook->getIsEnabled() ? 'enabled' : 'disabled';
        $this->addFlash('success', sprintf('Webhook %s successfully', $status));

        return $this->redirectToRoute('discord_admin_index');
    }

    #[Route('/users/{id}/verify', name: 'discord_admin_user_verify', methods: ['POST'])]
    public function verifyUser(int $id): Response
    {
        $discordUser = $this->userRepository->find($id);

        if (!$discordUser) {
            throw $this->createNotFoundException('Discord user not found');
        }

        $discordUser->setIsVerified(true);
        $discordUser->setVerifiedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->addFlash('success', 'Discord user verified successfully');

        return $this->redirectToRoute('discord_admin_index');
    }

    #[Route('/users/{id}/unverify', name: 'discord_admin_user_unverify', methods: ['POST'])]
    public function unverifyUser(int $id): Response
    {
        $discordUser = $this->userRepository->find($id);

        if (!$discordUser) {
            throw $this->createNotFoundException('Discord user not found');
        }

        $discordUser->setIsVerified(false);
        $discordUser->setVerifiedAt(null);
        $this->entityManager->flush();

        $this->addFlash('success', 'Discord user unverified');

        return $this->redirectToRoute('discord_admin_index');
    }

    #[Route('/users/{id}/delete', name: 'discord_admin_user_delete', methods: ['POST'])]
    public function deleteUser(int $id): Response
    {
        $discordUser = $this->userRepository->find($id);

        if (!$discordUser) {
            throw $this->createNotFoundException('Discord user not found');
        }

        $this->entityManager->remove($discordUser);
        $this->entityManager->flush();

        $this->addFlash('success', 'Discord link removed');

        return $this->redirectToRoute('discord_admin_index');
    }
}
