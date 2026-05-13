<?php

namespace App\Controller;

use App\Entity\Department;
use App\Form\DepartmentType;
use App\Repository\DepartmentRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Knp\Component\Pager\PaginatorInterface;

#[Route('/departments')]
#[IsGranted('ROLE_ADMIN')]
class DepartmentController extends AbstractController
{
    #[Route('/', name: 'app_department_index')]
    public function index(Request $request, DepartmentRepository $repo, PaginatorInterface $paginator): Response
    {
        $qb = $repo->createQueryBuilder('d')->orderBy('d.id', 'DESC');

        $q = trim((string) $request->query->get('q', ''));
        if ($q !== '') {
            $qb->andWhere('LOWER(d.name) LIKE :q')->setParameter('q', '%' . mb_strtolower($q) . '%');
        }

        $pagination = $paginator->paginate($qb, $request->query->getInt('page', 1), 15);

        return $this->render('department/index.html.twig', [
            'departments' => $pagination,
            'pagination' => $pagination,
        ]);
    }

    #[Route('/new', name: 'app_department_new')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $department = new Department();
        $form = $this->createForm(DepartmentType::class, $department);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $errors = $this->validateDepartment($department);
            if (!empty($errors)) {
                foreach ($errors as $err) {
                    $this->addFlash('error', $err);
                }
                return $this->render('department/form.html.twig', [
                    'form' => $form->createView(),
                    'department' => $department,
                    'is_edit' => false,
                ]);
            }

            $em->persist($department);
            $em->flush();
            $this->addFlash('success', 'Department created successfully.');
            return $this->redirectToRoute('app_department_index');
        }

        return $this->render('department/form.html.twig', [
            'form' => $form->createView(),
            'department' => $department,
            'is_edit' => false,
        ]);
    }

    #[Route('/{id}', name: 'app_department_show', requirements: ['id' => '\d+'])]
    public function show(Department $department, UserRepository $userRepo, DepartmentRepository $deptRepo): Response
    {
        $members = $userRepo->findBy(['department' => $department]);

        // Employees not in this dept (for the Add Employee dropdown)
        $availableEmployees = $userRepo->createQueryBuilder('u')
            ->where('u.department != :dept OR u.department IS NULL')
            ->andWhere("u.role NOT IN (:excluded)")
            ->setParameter('dept', $department)
            ->setParameter('excluded', ['GIG_WORKER'])
            ->orderBy('u.last_name', 'ASC')
            ->getQuery()->getResult();

        $allDepts = $deptRepo->findBy([], ['name' => 'ASC'], 200);

        return $this->render('department/show.html.twig', [
            'department' => $department,
            'members' => $members,
            'available_employees' => $availableEmployees,
            'all_departments' => $allDepts,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_department_edit', requirements: ['id' => '\d+'])]
    public function edit(Request $request, Department $department, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(DepartmentType::class, $department);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $errors = $this->validateDepartment($department);
            if (!empty($errors)) {
                foreach ($errors as $err) {
                    $this->addFlash('error', $err);
                }
                return $this->render('department/form.html.twig', [
                    'form' => $form->createView(),
                    'department' => $department,
                    'is_edit' => true,
                ]);
            }

            $em->flush();
            $this->addFlash('success', 'Department updated successfully.');
            return $this->redirectToRoute('app_department_index');
        }

        return $this->render('department/form.html.twig', [
            'form' => $form->createView(),
            'department' => $department,
            'is_edit' => true,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_department_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, Department $department, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete-' . $department->getId(), (string) $request->request->get('_token'))) {
            $em->remove($department);
            $em->flush();
            $this->addFlash('success', 'Department deleted.');
        }

        return $this->redirectToRoute('app_department_index');
    }

    #[Route('/{id}/assign/{userId}', name: 'app_department_assign_employee', methods: ['POST'], requirements: ['id' => '\d+', 'userId' => '\d+'])]
    public function assignEmployee(Department $department, int $userId, UserRepository $userRepo, EntityManagerInterface $em, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('assign-' . $department->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_department_show', ['id' => $department->getId()]);
        }

        $user = $userRepo->find($userId);
        if ($user) {
            $user->setDepartment($department);
            $em->flush();
            $this->addFlash('success', $user->getFirst_name() . ' ' . $user->getLast_name() . ' added to ' . $department->getName() . '.');
        }

        return $this->redirectToRoute('app_department_show', ['id' => $department->getId()]);
    }

    #[Route('/{id}/remove/{userId}', name: 'app_department_remove_employee', methods: ['POST'], requirements: ['id' => '\d+', 'userId' => '\d+'])]
    public function removeEmployee(Department $department, int $userId, UserRepository $userRepo, EntityManagerInterface $em, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('remove-' . $department->getId() . '-' . $userId, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_department_show', ['id' => $department->getId()]);
        }

        $user = $userRepo->find($userId);
        if ($user && $user->getDepartment()?->getId() === $department->getId()) {
            $user->setDepartment(null);
            $em->flush();
            $this->addFlash('success', $user->getFirst_name() . ' ' . $user->getLast_name() . ' removed from ' . $department->getName() . '.');
        }

        return $this->redirectToRoute('app_department_show', ['id' => $department->getId()]);
    }

    #[Route('/{id}/move/{userId}', name: 'app_department_move_employee', methods: ['POST'], requirements: ['id' => '\d+', 'userId' => '\d+'])]
    public function moveEmployee(int $id, int $userId, UserRepository $userRepo, DepartmentRepository $deptRepo, EntityManagerInterface $em, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('move-' . $userId, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_department_show', ['id' => $id]);
        }

        $user = $userRepo->find($userId);
        $targetDeptId = (int) $request->request->get('target_department_id');
        $targetDept = $deptRepo->find($targetDeptId);

        if ($user && $targetDept) {
            $user->setDepartment($targetDept);
            $em->flush();
            $this->addFlash('success', $user->getFirst_name() . ' ' . $user->getLast_name() . ' moved to ' . $targetDept->getName() . '.');
        }

        return $this->redirectToRoute('app_department_show', ['id' => $id]);
    }

    /** @return string[] */
    private function validateDepartment(Department $department): array
    {
        $errors = [];

        $name = $department->getName();
        if (!$name || strlen(trim($name)) < 2) {
            $errors[] = 'Department name must be at least 2 characters.';
        }
        if ($name && strlen($name) > 100) {
            $errors[] = 'Department name cannot exceed 100 characters.';
        }

        if (!$department->getManager()) {
            $errors[] = 'Each department must have a manager.';
        }

        $budget = $department->getAllocatedBudget();
        if ($budget !== null && $budget < 0) {
            $errors[] = 'Budget must be a positive number.';
        }

        return $errors;
    }
}
