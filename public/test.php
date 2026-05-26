<?php
require __DIR__.'/../vendor/autoload.php';

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

(new Dotenv())->bootEnv(__DIR__.'/../.env');
$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();

$container = $kernel->getContainer();
$em = $container->get('doctrine.orm.entity_manager');

$subscriber = $em->getRepository(\App\Entity\Subscriber::class)->find(2);
if (!$subscriber) {
    echo "Subscriber 2 not found.\n";
    exit;
}

$channel = $subscriber->getChannel();
echo "Channel: '$channel'\n";

$waConn = $subscriber->getWhatsAppConnection();
if ($waConn) {
    echo "WA Conn ID: " . $waConn->getId() . "\n";
    $owner = $waConn->getOwner();
    if ($owner) {
        echo "WA Owner ID: " . $owner->getId() . "\n";
    } else {
        echo "WA Owner is NULL\n";
    }
} else {
    echo "WA Conn is NULL\n";
}
