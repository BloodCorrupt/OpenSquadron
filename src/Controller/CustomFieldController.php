<?php

namespace App\Controller;

use App\Entity\CustomField;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class CustomFieldController extends AbstractController
{
    #[Route('/settings/custom-fields', name: 'app_custom_field_index', methods: ['GET'])]
    public function index(EntityManagerInterface $em): Response
    {
        $customFields = $em->getRepository(CustomField::class)->findBy([], ['id' => 'DESC']);

        return $this->render('custom_fields/index.html.twig', [
            'customFields' => $customFields,
        ]);
    }

    #[Route('/settings/custom-fields/json', name: 'app_custom_field_json', methods: ['GET'])]
    public function listJson(EntityManagerInterface $em): JsonResponse
    {
        $customFields = $em->getRepository(CustomField::class)->findBy([], ['id' => 'DESC']);
        
        $data = array_map(static fn (CustomField $cf) => [
            'id' => $cf->getId(),
            'name' => $cf->getName(),
            'type' => $cf->getType(),
            'description' => $cf->getDescription(),
            'defaultValue' => $cf->getDefaultValue(),
        ], $customFields);

        return new JsonResponse($data);
    }

    #[Route('/settings/custom-fields/save', name: 'app_custom_field_save', methods: ['POST'])]
    public function save(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $id = $request->request->get('id');
        $name = trim($request->request->get('name', ''));
        $type = strtoupper(trim($request->request->get('type', 'TEXT')));
        $description = trim($request->request->get('description', ''));
        $defaultValue = trim($request->request->get('defaultValue', ''));

        if ($name === '') {
            return new JsonResponse(['success' => false, 'error' => 'Variable Field Name is required.'], 400);
        }

        // Validate type
        $allowedTypes = ['TEXT', 'NUMBER', 'EMAIL', 'PHONE', 'BOOLEAN'];
        if (!in_array($type, $allowedTypes, true)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid data type.'], 400);
        }

        if ($id) {
            $customField = $em->getRepository(CustomField::class)->find($id);
            if (!$customField) {
                return new JsonResponse(['success' => false, 'error' => 'Custom Field not found.'], 404);
            }
        } else {
            // Check uniqueness of name per workspace
            $existing = $em->getRepository(CustomField::class)->findOneBy(['name' => strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', str_replace(' ', '_', $name)))]);
            if ($existing) {
                return new JsonResponse(['success' => false, 'error' => 'A custom field with this name already exists in this workspace.'], 400);
            }

            $customField = new CustomField();
            $customField->setOwner($this->getUser());
        }

        $customField->setName($name);
        $customField->setType($type);
        $customField->setDescription($description ?: null);
        $customField->setDefaultValue($defaultValue ?: null);

        if (!$id) {
            $em->persist($customField);
        }

        $em->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Custom Field saved successfully.',
            'data' => [
                'id' => $customField->getId(),
                'name' => $customField->getName(),
                'type' => $customField->getType(),
                'description' => $customField->getDescription(),
                'defaultValue' => $customField->getDefaultValue(),
            ]
        ]);
    }

    #[Route('/settings/custom-fields/delete/{id}', name: 'app_custom_field_delete', methods: ['POST'])]
    public function delete(int $id, EntityManagerInterface $em): JsonResponse
    {
        $customField = $em->getRepository(CustomField::class)->find($id);
        if (!$customField) {
            return new JsonResponse(['success' => false, 'error' => 'Custom Field not found.'], 404);
        }

        $em->remove($customField);
        $em->flush();

        return new JsonResponse(['success' => true, 'message' => 'Custom Field deleted successfully.']);
    }
}
