<?php

$file = __DIR__ . '/src/Controller/InstagramBotManagerController.php';
$content = file_get_contents($file);

// 1. listPosts
$content = preg_replace(
    "/\\\$refresh = filter_var\(\\\$request->query->get\('refresh'\), FILTER_VALIDATE_BOOLEAN\);\s+\\\$dir = __DIR__ \. '\/\.\.\/\.\.\/var\/Instagram_posts';\s+if \(!is_dir\(\\\$dir\)\) \{\s+mkdir\(\\\$dir, 0777, true\);\s+\}\s+\\\$file = \\\$dir \. \"\/conn_\\\{\\\$connectionId\\\}\.json\";\s+\\\$posts = \[\];\s+\\\$cacheExists = file_exists\(\\\$file\);\s+if \(\\\$cacheExists\) \{\s+\\\$posts = json_decode\(file_get_contents\(\\\$file\), true\) \?: \[\];\s+\}\s+\\\$needsRefresh = !\\\$cacheExists \|\| empty\(\\\$posts\) \|\| \(time\(\) - filemtime\(\\\$file\)\ > 300\);\s+if \(\\\$refresh \|\| \\\$needsRefresh\) \{\s+\\\$connection = \\\$em->getRepository\(InstagramConnection::class\)->find\(\\\$connectionId\);/s",
    "\$refresh = filter_var(\$request->query->get('refresh'), FILTER_VALIDATE_BOOLEAN);\n\n        \$connection = \$em->getRepository(InstagramConnection::class)->find(\$connectionId);\n        if (!\$connection) {\n            return new JsonResponse(['success' => false, 'error' => 'Connection not found.'], 404);\n        }\n\n        \$cache = \$connection->getPostsCache() ?: [];\n        \$posts = \$cache['posts'] ?? [];\n        \$lastUpdated = \$cache['updatedAt'] ?? 0;\n\n        \$needsRefresh = empty(\$posts) || (time() - \$lastUpdated > 300);\n\n        if (\$refresh || \$needsRefresh) {",
    $content,
    1,
    $count
);
$content = str_replace(
    "                    \$posts = \$syncedPosts;\n                    file_put_contents(\$file, json_encode(\$posts, JSON_PRETTY_PRINT));\n                } catch (\Exception \$e) {\n                    return new JsonResponse([",
    "                    \$posts = \$syncedPosts;\n                    \$connection->setPostsCache(['updatedAt' => time(), 'posts' => \$posts]);\n                    \$em->flush();\n                } catch (\Exception \$e) {\n                    return new JsonResponse([",
    $content
);
$content = str_replace(
    "        // Attach DB-based comment automation settings to posts\n        \$connection = \$em->getRepository(InstagramConnection::class)->find(\$connectionId);\n        if (\$connection) {\n            \$automationRepo = \$em->getRepository(\App\Entity\InstagramCommentAutomation::class);",
    "        // Attach DB-based comment automation settings to posts\n        if (\$connection) {\n            \$automationRepo = \$em->getRepository(\App\Entity\InstagramCommentAutomation::class);",
    $content
);

// 2. savePost
$content = preg_replace(
    "/\\\$dir = __DIR__ \. '\/\.\.\/\.\.\/var\/Instagram_posts';\s+if \(!is_dir\(\\\$dir\)\) \{\s+mkdir\(\\\$dir, 0777, true\);\s+\}\s+\\\$file = \\\$dir \. \"\/conn_\\\{\\\$connectionId\\\}\.json\";\s+\\\$posts = \[\];\s+if \(file_exists\(\\\$file\)\) \{\s+\\\$posts = json_decode\(file_get_contents\(\\\$file\), true\) \?: \[\];\s+\}/s",
    "\$cache = \$connection->getPostsCache() ?: [];\n        \$posts = \$cache['posts'] ?? [];",
    $content,
    1,
    $count
);
$content = str_replace(
    "file_put_contents(\$file, json_encode(\$posts, JSON_PRETTY_PRINT));\n\n        return new JsonResponse(['success' => true, 'post' => \$post]);\n    }\n\n    #[Route('/Instagram-bot-manager/posts/publish/{id}'",
    "\$cache['posts'] = \$posts;\n        \$cache['updatedAt'] = time();\n        \$connection->setPostsCache(\$cache);\n        \$em->flush();\n\n        return new JsonResponse(['success' => true, 'post' => \$post]);\n    }\n\n    #[Route('/Instagram-bot-manager/posts/publish/{id}'",
    $content
);

// 3. publishPost
$content = preg_replace(
    "/\\\$dir = __DIR__ \. '\/\.\.\/\.\.\/var\/Instagram_posts';\s+\\\$file = \\\$dir \. \"\/conn_\\\{\\\$connectionId\\\}\.json\";\s+if \(!file_exists\(\\\$file\)\) \{\s+return new JsonResponse\(\['success' => false, 'error' => 'Post log not found\.'\], 404\);\s+\}\s+\\\$posts = json_decode\(file_get_contents\(\\\$file\), true\) \?: \[\];/s",
    "\$cache = \$connection->getPostsCache() ?: [];\n        \$posts = \$cache['posts'] ?? [];\n        if (empty(\$posts)) {\n            return new JsonResponse(['success' => false, 'error' => 'Post log not found.'], 404);\n        }",
    $content,
    1,
    $count
);
$content = str_replace(
    "file_put_contents(\$file, json_encode(\$posts, JSON_PRETTY_PRINT));\n\n        return new JsonResponse(['success' => true, 'post' => \$post]);\n    }\n\n    #[Route('/Instagram-bot-manager/posts/delete/{id}'",
    "\$cache['posts'] = \$posts;\n        \$cache['updatedAt'] = time();\n        \$connection->setPostsCache(\$cache);\n        \$em->flush();\n\n        return new JsonResponse(['success' => true, 'post' => \$post]);\n    }\n\n    #[Route('/Instagram-bot-manager/posts/delete/{id}'",
    $content
);

// 4. deletePost
$content = str_replace(
    "public function deletePost(string \$id, Request \$request): JsonResponse\n    {\n        \$connectionId = (int)\$request->request->get('connectionId');\n        if (!\$connectionId) {\n            return new JsonResponse(['success' => false, 'error' => 'Connection ID is required.'], 400);\n        }",
    "public function deletePost(string \$id, Request \$request, EntityManagerInterface \$em): JsonResponse\n    {\n        \$connectionId = (int)\$request->request->get('connectionId');\n        if (!\$connectionId) {\n            return new JsonResponse(['success' => false, 'error' => 'Connection ID is required.'], 400);\n        }\n\n        \$connection = \$em->getRepository(InstagramConnection::class)->find(\$connectionId);\n        if (!\$connection) {\n            return new JsonResponse(['success' => false, 'error' => 'Connection not found.'], 404);\n        }",
    $content
);
$content = preg_replace(
    "/\\\$dir = __DIR__ \. '\/\.\.\/\.\.\/var\/Instagram_posts';\s+\\\$file = \\\$dir \. \"\/conn_\\\{\\\$connectionId\\\}\.json\";\s+if \(!file_exists\(\\\$file\)\) \{\s+return new JsonResponse\(\['success' => false, 'error' => 'Post log not found\.'\], 404\);\s+\}\s+\\\$posts = json_decode\(file_get_contents\(\\\$file\), true\) \?: \[\];/s",
    "\$cache = \$connection->getPostsCache() ?: [];\n        \$posts = \$cache['posts'] ?? [];\n        if (empty(\$posts)) {\n            return new JsonResponse(['success' => false, 'error' => 'Post log not found.'], 404);\n        }",
    $content,
    1,
    $count
);
$content = str_replace(
    "file_put_contents(\$file, json_encode(\$updatedPosts, JSON_PRETTY_PRINT));\n\n        return new JsonResponse(['success' => true]);\n    }\n\n    #[Route('/Instagram-bot-manager/posts/save-comment-settings'",
    "\$cache['posts'] = \$updatedPosts;\n        \$cache['updatedAt'] = time();\n        \$connection->setPostsCache(\$cache);\n        \$em->flush();\n\n        return new JsonResponse(['success' => true]);\n    }\n\n    #[Route('/Instagram-bot-manager/posts/save-comment-settings'",
    $content
);

// 5. addPostById
$content = str_replace(
    "public function addPostById(Request \$request): JsonResponse\n    {\n        \$connectionId = (int)\$request->request->get('connectionId');\n        \$fbPostId = trim((string)\$request->request->get('fbPostId'));\n\n        if (!\$connectionId || empty(\$fbPostId)) {\n            return new JsonResponse(['success' => false, 'error' => 'Connection ID and Instagram Post ID are required.'], 400);\n        }",
    "public function addPostById(Request \$request, EntityManagerInterface \$em): JsonResponse\n    {\n        \$connectionId = (int)\$request->request->get('connectionId');\n        \$fbPostId = trim((string)\$request->request->get('fbPostId'));\n\n        if (!\$connectionId || empty(\$fbPostId)) {\n            return new JsonResponse(['success' => false, 'error' => 'Connection ID and Instagram Post ID are required.'], 400);\n        }\n\n        \$connection = \$em->getRepository(InstagramConnection::class)->find(\$connectionId);\n        if (!\$connection) {\n            return new JsonResponse(['success' => false, 'error' => 'Connection not found.'], 404);\n        }",
    $content
);
$content = preg_replace(
    "/\\\$dir = __DIR__ \. '\/\.\.\/\.\.\/var\/Instagram_posts';\s+if \(!is_dir\(\\\$dir\)\) \{\s+mkdir\(\\\$dir, 0777, true\);\s+\}\s+\\\$file = \\\$dir \. \"\/conn_\\\{\\\$connectionId\\\}\.json\";\s+\\\$posts = \[\];\s+if \(file_exists\(\\\$file\)\) \{\s+\\\$posts = json_decode\(file_get_contents\(\\\$file\), true\) \?: \[\];\s+\}/s",
    "\$cache = \$connection->getPostsCache() ?: [];\n        \$posts = \$cache['posts'] ?? [];",
    $content,
    1,
    $count
);
$content = str_replace(
    "file_put_contents(\$file, json_encode(\$posts, JSON_PRETTY_PRINT));\n\n        return new JsonResponse(['success' => true, 'post' => \$newPost]);\n    }\n\n    private function mergeSettings",
    "\$cache['posts'] = \$posts;\n        \$cache['updatedAt'] = time();\n        \$connection->setPostsCache(\$cache);\n        \$em->flush();\n\n        return new JsonResponse(['success' => true, 'post' => \$newPost]);\n    }\n\n    private function mergeSettings",
    $content
);

file_put_contents($file, $content);

echo "Done\n";
