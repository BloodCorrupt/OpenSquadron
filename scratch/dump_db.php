<?php
use App\Kernel;
use App\Entity\Message;
use App\Entity\Subscriber;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();
$container = $kernel->getContainer();
$em = $container->get('doctrine.orm.entity_manager');

echo "=== SUBSCRIBERS ===\n";
$subs = $em->getRepository(Subscriber::class)->findAll();
foreach ($subs as $sub) {
    echo sprintf(
        "ID: %d | Name: %s | Phone: %s | CreatedAt: %s | UpdatedAt: %s\n",
        $sub->getId(),
        $sub->getName(),
        $sub->getPhoneNumber(),
        $sub->getCreatedAt() ? $sub->getCreatedAt()->format('Y-m-d H:i:s T') : 'N/A',
        $sub->getUpdatedAt() ? $sub->getUpdatedAt()->format('Y-m-d H:i:s T') : 'N/A'
    );
}

echo "\n=== MESSAGES ===\n";
$msgs = $em->getRepository(Message::class)->findBy([], ['id' => 'DESC'], 10);
foreach ($msgs as $msg) {
    echo sprintf(
        "ID: %d | SubName: %s | Dir: %s | Type: %s | Content: %s | Timestamp: %s\n",
        $msg->getId(),
        $msg->getSubscriber()->getName(),
        $msg->getDirection(),
        $msg->getType(),
        $msg->getContent(),
        $msg->getTimestamp() ? $msg->getTimestamp()->format('Y-m-d H:i:s T') : 'N/A'
    );
}
