<?php
use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();
$container = $kernel->getContainer();
$em = $container->get('doctrine.orm.entity_manager');

$conn = $em->getConnection();
$stmt = $conn->executeQuery('SHOW COLUMNS FROM whatsapp_connection');
$columns = $stmt->fetchAllAssociative();

print_r($columns);
