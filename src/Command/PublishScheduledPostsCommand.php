<?php

namespace App\Command;

use App\Entity\FacebookConnection;
use App\Entity\InstagramConnection;
use App\Service\FacebookService;
use App\Service\InstagramService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:publish-scheduled-posts',
    description: 'Publishes scheduled Facebook and Instagram posts that are due.',
)]
class PublishScheduledPostsCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FacebookService $facebookService,
        private InstagramService $instagramService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $nowStr = $now->format('Y-m-d H:i:s');

        $io->info("Checking for scheduled posts due before or at {$nowStr} UTC...");

        // 1. Process Facebook Connections
        $facebookConnections = $this->entityManager->getRepository(FacebookConnection::class)->findAll();
        $fbCount = 0;
        foreach ($facebookConnections as $connection) {
            $cache = $connection->getPostsCache() ?: [];
            $posts = $cache['posts'] ?? [];
            $updated = false;

            foreach ($posts as $idx => $post) {
                if (($post['status'] ?? '') === 'scheduled' && !empty($post['scheduledAt'])) {
                    if ($post['scheduledAt'] <= $nowStr) {
                        $io->comment("Publishing Facebook post {$post['id']}...");
                        try {
                            $type = $post['type'] ?? 'multimedia';
                            $message = $post['message'] ?? '';
                            $link = $post['link'] ?? '';
                            $mediaType = $post['mediaType'] ?? 'none';
                            $mediaUrl = $post['mediaUrl'] ?? '';
                            $ctaType = $post['ctaType'] ?? '';
                            $slides = $post['slides'] ?? [];

                            $result = null;
                            if ($type === 'multimedia') {
                                if ($mediaType === 'image' && $mediaUrl !== '') {
                                    $result = $this->facebookService->publishPhotoPost($connection, $mediaUrl, $message);
                                } elseif ($mediaType === 'video' && $mediaUrl !== '') {
                                    $result = $this->facebookService->publishVideoPost($connection, $mediaUrl, $message);
                                } else {
                                    $result = $this->facebookService->publishFeedPost($connection, $message, $link);
                                }
                            } elseif ($type === 'cta') {
                                $result = $this->facebookService->publishCtaPost($connection, $message, $link, $ctaType);
                            } elseif ($type === 'carousel') {
                                $result = $this->facebookService->publishCarouselPost($connection, $message, $slides);
                            }

                            $post['status'] = 'published';
                            $post['errorMessage'] = null;
                            if ($result && isset($result['id'])) {
                                $post['fbPostId'] = $result['id'];
                            } elseif ($result && isset($result['post_id'])) {
                                $post['fbPostId'] = $result['post_id'];
                            }
                            $fbCount++;
                        } catch (\Exception $e) {
                            $post['status'] = 'failed';
                            $post['errorMessage'] = $e->getMessage();
                            $io->error("Failed to publish Facebook post {$post['id']}: " . $e->getMessage());
                        }
                        $posts[$idx] = $post;
                        $updated = true;
                    }
                }
            }

            if ($updated) {
                $cache['posts'] = $posts;
                $cache['updatedAt'] = time();
                $connection->setPostsCache($cache);
                $this->entityManager->persist($connection);
            }
        }

        // 2. Process Instagram Connections
        $instagramConnections = $this->entityManager->getRepository(InstagramConnection::class)->findAll();
        $igCount = 0;
        foreach ($instagramConnections as $connection) {
            $cache = $connection->getPostsCache() ?: [];
            $posts = $cache['posts'] ?? [];
            $updated = false;

            foreach ($posts as $idx => $post) {
                if (($post['status'] ?? '') === 'scheduled' && !empty($post['scheduledAt'])) {
                    if ($post['scheduledAt'] <= $nowStr) {
                        $io->comment("Publishing Instagram post {$post['id']}...");
                        try {
                            $type = $post['type'] ?? 'multimedia';
                            $message = $post['message'] ?? '';
                            $link = $post['link'] ?? '';
                            $mediaType = $post['mediaType'] ?? 'none';
                            $mediaUrl = $post['mediaUrl'] ?? '';
                            $ctaType = $post['ctaType'] ?? '';
                            $slides = $post['slides'] ?? [];

                            $result = null;
                            if ($type === 'multimedia') {
                                if ($mediaType === 'image' && $mediaUrl !== '') {
                                    $result = $this->instagramService->publishPhotoPost($connection, $mediaUrl, $message);
                                } elseif ($mediaType === 'video' && $mediaUrl !== '') {
                                    $result = $this->instagramService->publishVideoPost($connection, $mediaUrl, $message);
                                } else {
                                    $result = $this->instagramService->publishFeedPost($connection, $message, $link);
                                }
                            } elseif ($type === 'cta') {
                                $result = $this->instagramService->publishCtaPost($connection, $message, $link, $ctaType);
                            } elseif ($type === 'carousel') {
                                $result = $this->instagramService->publishCarouselPost($connection, $message, $slides);
                            }

                            $post['status'] = 'published';
                            $post['errorMessage'] = null;
                            if ($result && isset($result['id'])) {
                                $post['fbPostId'] = $result['id'];
                            } elseif ($result && isset($result['post_id'])) {
                                $post['fbPostId'] = $result['post_id'];
                            }
                            $igCount++;
                        } catch (\Exception $e) {
                            $post['status'] = 'failed';
                            $post['errorMessage'] = $e->getMessage();
                            $io->error("Failed to publish Instagram post {$post['id']}: " . $e->getMessage());
                        }
                        $posts[$idx] = $post;
                        $updated = true;
                    }
                }
            }

            if ($updated) {
                $cache['posts'] = $posts;
                $cache['updatedAt'] = time();
                $connection->setPostsCache($cache);
                $this->entityManager->persist($connection);
            }
        }

        $this->entityManager->flush();

        $io->success("Successfully published {$fbCount} Facebook posts and {$igCount} Instagram posts!");

        return Command::SUCCESS;
    }
}
