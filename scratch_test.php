<?php

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/.env');

$kernel = new Kernel('dev', true);
$kernel->boot();

$container = $kernel->getContainer();
$entityManager = $container->get('doctrine.orm.entity_manager');
$tenantContext = new \App\Service\TenantContext($entityManager);

$owner = $entityManager->getRepository(App\Entity\Admin::class)->find(1);
if ($owner) {
    $tenantContext->setCurrentOwner($owner);
    $entityManager->clear();
    
    $connection = $entityManager->getRepository(App\Entity\FacebookConnection::class)->findOneBy(['owner' => $owner]);
    $aiSetting = $entityManager->getRepository(App\Entity\AiSetting::class)->findOneBy(['owner' => $owner, 'isActive' => true]);
    
    if ($connection && $aiSetting) {
        echo "Found Connection ID: " . $connection->getId() . "\n";
        echo "Found AI Setting ID: " . $aiSetting->getId() . " (Provider: " . $aiSetting->getProvider() . ")\n";
        
        $aiAgentService = $container->get(App\Service\AiAgentService::class);
        echo "Calling generateResponse...\n";
        $response = $aiAgentService->generateResponse("How much does it cost?", $aiSetting, $connection);
        echo "Response: " . var_export($response, true) . "\n";
    } else {
        echo "Connection or AI Setting not found.\n";
    }
} else {
    echo "Owner ID = 1 not found\n";
}
