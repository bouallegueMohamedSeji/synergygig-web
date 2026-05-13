<?php

namespace App\Controller;

use App\Entity\TrainingCourse;
use App\Entity\TrainingCertificate;
use App\Entity\TrainingEnrollment;
use App\Form\TrainingCourseType;
use App\Repository\TrainingCourseRepository;
use App\Repository\TrainingEnrollmentRepository;
use App\Repository\TrainingCertificateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Knp\Component\Pager\PaginatorInterface;
use App\Service\AIService;
use App\Service\N8nWebhookService;
use App\Service\NotificationService;
use App\Repository\UserRepository;
use Dompdf\Dompdf;

#[Route('/training')]
#[IsGranted('ROLE_USER')]
class TrainingController extends AbstractController
{
    #[Route('/', name: 'app_training_index')]
    public function index(
        Request $request,
        TrainingCourseRepository $courseRepo,
        TrainingEnrollmentRepository $enrollRepo,
        TrainingCertificateRepository $certRepo,
        PaginatorInterface $paginator
    ): Response {
        $user = $this->getUser();
        $tab = $request->query->get('tab', 'dashboard');

        // ── Dashboard stats ──
        $totalCourses = $courseRepo->count(['status' => 'ACTIVE']);
        $myEnrollments = $user ? $enrollRepo->findBy(['user' => $user]) : [];
        $completedCount = 0;
        $totalProgress = 0;
        foreach ($myEnrollments as $e) {
            if ($e->getStatus() === 'COMPLETED') $completedCount++;
            $totalProgress += $e->getProgress() ?? 0;
        }
        $avgProgress = count($myEnrollments) > 0 ? round($totalProgress / count($myEnrollments)) : 0;
        $myCertificates = $user ? $certRepo->findBy(['user' => $user], ['issued_at' => 'DESC']) : [];
        $certificateByEnrollmentId = [];
        foreach ($myCertificates as $certificate) {
            $enrollmentId = $certificate->getEnrollment()?->getId();
            if ($enrollmentId) {
                $certificateByEnrollmentId[$enrollmentId] = $certificate;
            }
        }

        // ── Catalog (paginated) ──
        $qb = $courseRepo->createQueryBuilder('c')->orderBy('c.id', 'DESC');
        $q = $request->query->get('q');
        if ($q) {
            $qb->andWhere('LOWER(c.title) LIKE :q')->setParameter('q', '%' . mb_strtolower((string) $q) . '%');
        }
        $catFilter = $request->query->get('category');
        if ($catFilter) {
            $qb->andWhere('c.category = :cat')->setParameter('cat', $catFilter);
        }
        $diffFilter = $request->query->get('difficulty');
        if ($diffFilter) {
            $qb->andWhere('c.difficulty = :diff')->setParameter('diff', $diffFilter);
        }
        $pagination = $paginator->paginate($qb, $request->query->getInt('page', 1), 12);

        // Build enrollment lookup for current user
        $enrollmentMap = [];
        foreach ($myEnrollments as $e) {
            $courseId = $e->getCourse()?->getId();
            if ($courseId !== null) {
                $enrollmentMap[$courseId] = $e;
            }
        }

        // ── Recent enrollments for dashboard ──
        $recentEnrollments = $user ? $enrollRepo->findBy(['user' => $user], ['enrolled_at' => 'DESC'], 5) : [];

        // ── My Learning (sorted: IN_PROGRESS → ENROLLED → COMPLETED) ──
        $statusOrder = ['IN_PROGRESS' => 0, 'ENROLLED' => 1, 'COMPLETED' => 2, 'DROPPED' => 3];
        $learningList = array_filter($myEnrollments, fn($e) => $e->getStatus() !== 'DROPPED');
        usort($learningList, function ($a, $b) use ($statusOrder) {
            return ($statusOrder[$a->getStatus()] ?? 9) - ($statusOrder[$b->getStatus()] ?? 9);
        });

        // ── Certificates (HR/Admin see all, others see own) ──
        $allCertificates = $myCertificates;
        if ($this->isGranted('ROLE_HR')) {
            $allCertificates = $certRepo->findBy([], ['issued_at' => 'DESC'], 100);
        }

        // ── Manage tab courses (HR/Admin only) ──
        $manageCourses = $this->isGranted('ROLE_HR') ? $courseRepo->findBy([], ['id' => 'DESC'], 100) : [];

        return $this->render('training/index.html.twig', [
            'tab' => $tab,
            'courses' => $pagination,
            'pagination' => $pagination,
            'enrollmentMap' => $enrollmentMap,
            // Dashboard
            'totalCourses' => $totalCourses,
            'myEnrollmentCount' => count($myEnrollments),
            'completedCount' => $completedCount,
            'certCount' => count($myCertificates),
            'avgProgress' => $avgProgress,
            'recentEnrollments' => $recentEnrollments,
            'certificateByEnrollmentId' => $certificateByEnrollmentId,
            // My Learning
            'learningList' => $learningList,
            // Certificates
            'certificates' => $allCertificates,
            // Manage
            'manageCourses' => $manageCourses,
        ]);
    }

    #[Route('/{id}/enroll', name: 'app_training_enroll', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function enroll(
        TrainingCourse $course,
        Request $request,
        EntityManagerInterface $em,
        TrainingEnrollmentRepository $enrollRepo,
        NotificationService $notifier,
        N8nWebhookService $n8n
    ): Response {
        if (!$this->isCsrfTokenValid('enroll' . $course->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_training_index', ['tab' => 'catalog']);
        }

        /** @var \App\Entity\User|null $user */
        $user = $this->getUser();
        $existing = $enrollRepo->findOneBy(['course' => $course, 'user' => $user]);
        if ($existing && $existing->getStatus() !== 'DROPPED') {
            $this->addFlash('warning', 'You are already enrolled in this course.');
            return $this->redirectToRoute('app_training_index', ['tab' => 'catalog']);
        }

        // Check max participants
        if ($course->getMaxParticipants() > 0) {
            $currentCount = $enrollRepo->count(['course' => $course]);
            if ($currentCount >= $course->getMaxParticipants()) {
                $this->addFlash('danger', 'This course has reached maximum capacity.');
                return $this->redirectToRoute('app_training_index', ['tab' => 'catalog']);
            }
        }

        if ($existing && $existing->getStatus() === 'DROPPED') {
            $existing->setStatus('ENROLLED');
            $existing->setProgress(0);
            $existing->setScore(null);
            $existing->initEnrolledAt(new \DateTime());
            $existing->initCompletedAt(null);
        } else {
            $enrollment = new TrainingEnrollment();
            $enrollment->setCourse($course);
            $enrollment->setUser($user);
            $enrollment->setStatus('ENROLLED');
            $enrollment->setProgress(0);
            $enrollment->initEnrolledAt(new \DateTime());
            $em->persist($enrollment);
        }

        $em->flush();

        // Fire n8n webhook
        if ($user !== null) {
            $n8n->trainingEnrolled(
                (int) $user->getId(),
                $user->getFirstName() . ' ' . $user->getLastName(),
                (int) $course->getId(),
                (string) $course->getTitle()
            );
        }

        $this->addFlash('success', 'Successfully enrolled in "' . $course->getTitle() . '"!');
        return $this->redirectToRoute('app_training_index', ['tab' => 'learning']);
    }

    #[Route('/{id}/drop', name: 'app_training_drop', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function drop(
        TrainingCourse $course,
        Request $request,
        EntityManagerInterface $em,
        TrainingEnrollmentRepository $enrollRepo
    ): Response {
        if (!$this->isCsrfTokenValid('drop' . $course->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_training_index', ['tab' => 'learning']);
        }

        $enrollment = $enrollRepo->findOneBy(['course' => $course, 'user' => $this->getUser()]);
        if ($enrollment && $enrollment->getStatus() !== 'COMPLETED') {
            $enrollment->setStatus('DROPPED');
            $em->flush();
            $this->addFlash('success', 'You have dropped "' . $course->getTitle() . '".');
        }
        return $this->redirectToRoute('app_training_index', ['tab' => 'learning']);
    }

    #[Route('/new', name: 'app_training_new')]
    #[IsGranted('ROLE_HR')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $course = new TrainingCourse();
        $form = $this->createForm(TrainingCourseType::class, $course);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($course->getStartDate() && $course->getEndDate() && $course->getEndDate() < $course->getStartDate()) {
                $this->addFlash('error', 'End date must be on or after the start date.');
                return $this->render('training/form.html.twig', [
                    'form' => $form->createView(),
                    'is_edit' => false,
                ]);
            }
            $em->persist($course);
            $em->flush();
            $this->addFlash('success', 'Course created.');
            return $this->redirectToRoute('app_training_index');
        }

        return $this->render('training/form.html.twig', [
            'form' => $form->createView(),
            'is_edit' => false,
        ]);
    }

    #[Route('/{id}', name: 'app_training_show', requirements: ['id' => '\d+'])]
    public function show(
        TrainingCourse $course,
        TrainingEnrollmentRepository $enrollRepo,
        TrainingCertificateRepository $certRepo
    ): Response {
        $enrollments = $enrollRepo->findBy(['course' => $course]);
        $certificates = $certRepo->findBy(['course' => $course]);
        return $this->render('training/show.html.twig', [
            'course' => $course,
            'enrollments' => $enrollments,
            'certificates' => $certificates,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_training_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_HR')]
    public function edit(Request $request, TrainingCourse $course, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(TrainingCourseType::class, $course);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($course->getStartDate() && $course->getEndDate() && $course->getEndDate() < $course->getStartDate()) {
                $this->addFlash('error', 'End date must be on or after the start date.');
                return $this->render('training/form.html.twig', [
                    'form' => $form->createView(),
                    'is_edit' => true,
                    'course' => $course,
                ]);
            }
            $em->flush();
            $this->addFlash('success', 'Course updated.');
            return $this->redirectToRoute('app_training_index');
        }

        return $this->render('training/form.html.twig', [
            'form' => $form->createView(),
            'is_edit' => true,
            'course' => $course,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_training_delete', methods: ['POST'])]
    #[IsGranted('ROLE_HR')]
    public function delete(Request $request, TrainingCourse $course, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete' . $course->getId(), (string) $request->request->get('_token'))) {
            $em->remove($course);
            $em->flush();
            $this->addFlash('success', 'Course deleted.');
        }
        return $this->redirectToRoute('app_training_index');
    }

    // ── Quiz: start (AI-generated, course-specific — mirrors Java ZAIService.generateCourseQuiz) ──

    #[Route('/{id}/quiz', name: 'app_training_quiz', requirements: ['id' => '\d+'])]
    public function quiz(TrainingCourse $course, Request $request, AIService $ai): Response
    {
        $data = $this->generateCourseQuizData($course, $ai);

        if ($data === null) {
            $this->addFlash('warning', 'Could not generate quiz questions. Please try again.');
            return $this->redirectToRoute('app_training_show', ['id' => $course->getId()]);
        }

        ['questions' => $questions, 'correctAnswers' => $correctAnswers, 'timer' => $timer] = $data;

        // Format for quiz.html.twig (expects 'question' key) — must be defined BEFORE session.set
        $clientQuestions = array_map(fn($q) => [
            'index'    => $q['index'],
            'question' => $q['q'],
            'category' => $course->getTitle(),
            'options'  => $q['options'],
        ], $questions);

        $session = $request->getSession();
        $session->set('quiz_answers_' . $course->getId(), $correctAnswers);
        $session->set('quiz_questions_' . $course->getId(), $clientQuestions);
        $session->set('quiz_course_id', $course->getId());

        return $this->render('training/quiz.html.twig', [
            'course'    => $course,
            'questions' => $clientQuestions,
            'timer'     => $timer,
        ]);
    }

    // ── Quiz: submit answers (PHP validation) ──

    #[Route('/{id}/quiz-submit', name: 'app_training_quiz_submit', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function quizSubmit(
        TrainingCourse $course,
        Request $request,
        EntityManagerInterface $em,
        TrainingEnrollmentRepository $enrollRepo,
        TrainingCertificateRepository $certRepo,
        N8nWebhookService $n8n,
        NotificationService $notifier
    ): Response {
        // CSRF check
        if (!$this->isCsrfTokenValid('quiz' . $course->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_training_show', ['id' => $course->getId()]);
        }

        // Retrieve correct answers from session
        $session = $request->getSession();
        $correctAnswers = $session->get('quiz_answers_' . $course->getId());
        $sessionQuestions = $session->get('quiz_questions_' . $course->getId(), []);
        if (!$correctAnswers || $session->get('quiz_course_id') !== $course->getId()) {
            $this->addFlash('danger', 'Quiz session expired. Please start the quiz again.');
            return $this->redirectToRoute('app_training_show', ['id' => $course->getId()]);
        }

        // Validate submitted answers server-side
        $errors = [];
        $submittedAnswers = $request->request->all('answers');
        if (!is_array($submittedAnswers)) {
            $errors[] = 'No answers submitted.';
        }

        $totalQuestions = count($correctAnswers);
        if (count($submittedAnswers) !== $totalQuestions) {
            $errors[] = 'You must answer all ' . $totalQuestions . ' questions.';
        }

        // Validate each answer is an integer 0-3
        foreach ($submittedAnswers as $qIdx => $answer) {
            $answer = (int) $answer;
            if ($answer < 0 || $answer > 3) {
                $errors[] = 'Invalid answer for question ' . ($qIdx + 1) . '.';
            }
        }

        if (!empty($errors)) {
            foreach ($errors as $e) {
                $this->addFlash('danger', $e);
            }
            return $this->redirectToRoute('app_training_quiz', ['id' => $course->getId()]);
        }

        // Calculate score
        $correct = 0;
        $results = [];
        foreach ($correctAnswers as $qIdx => $correctIdx) {
            $submitted = isset($submittedAnswers[$qIdx]) ? (int) $submittedAnswers[$qIdx] : -1;
            $isCorrect = ($submitted === $correctIdx);
            if ($isCorrect) {
                $correct++;
            }
            $qData = $sessionQuestions[$qIdx] ?? [];
            $results[] = [
                'index'     => $qIdx,
                'submitted' => $submitted,
                'correct'   => $correctIdx,
                'isCorrect' => $isCorrect,
                'question'  => $qData['question'] ?? ('Question ' . ($qIdx + 1)),
                'options'   => $qData['options'] ?? [],
            ];
        }

        $scorePercent = ($correct / $totalQuestions) * 100;
        $passed = $scorePercent >= 70;

        // Find the current user's enrollment
        $enrollment = $enrollRepo->findOneBy(['course' => $course, 'user' => $this->getUser()]);
        $certificateGenerated = false;
        $issuedCertificate = null;

        if ($enrollment) {
            $enrollment->setScore($scorePercent);

            if ($passed) {
                $enrollment->setStatus('COMPLETED');
                $enrollment->setProgress(100);
                $enrollment->initCompletedAt(new \DateTime());

                // Generate certificate if not already exists
                $existingCert = $certRepo->findOneBy(['enrollment' => $enrollment]);
                if ($existingCert) {
                    if (!$existingCert->getSignedAt()) {
                        $this->autoSignCertificate($existingCert);
                    }
                    $issuedCertificate = $existingCert;
                } else {
                    $year = date('Y');
                    $uid = strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
                    $certNumber = 'SG-' . $year . '-C' . $course->getId() . '-E' . $enrollment->getId() . '-' . $uid;

                    $cert = new TrainingCertificate();
                    $cert->setEnrollment($enrollment);
                    $cert->setUser($enrollment->getUser());
                    $cert->setCourse($course);
                    $cert->setCertificateNumber($certNumber);
                    $cert->initIssuedAt(new \DateTime());
                    $this->autoSignCertificate($cert);
                    $em->persist($cert);
                    $certificateGenerated = true;
                    $issuedCertificate = $cert;
                }
            }

            $em->flush();

            // Fire n8n webhook on training completion
            if ($passed) {
                $enrollUser = $enrollment->getUser();
                if ($enrollUser !== null) {
                    $n8n->trainingCompleted(
                        (int) $enrollUser->getId(),
                        $enrollUser->getFirstName() . ' ' . $enrollUser->getLastName(),
                        (int) $course->getId(),
                        (string) $course->getTitle(),
                        $scorePercent
                    );
                    $notifier->trainingCompleted($enrollUser, (int) $course->getId(), (string) $course->getTitle(), $scorePercent);
                }
            }
        }

        // Clear quiz session data
        $session->remove('quiz_answers_' . $course->getId());
        $session->remove('quiz_questions_' . $course->getId());
        $session->remove('quiz_course_id');

        return $this->render('training/quiz_result.html.twig', [
            'course' => $course,
            'results' => $results,
            'correct' => $correct,
            'total' => $totalQuestions,
            'scorePercent' => $scorePercent,
            'passed' => $passed,
            'certificateGenerated' => $certificateGenerated,
            'issuedCertificate' => $issuedCertificate,
        ]);
    }

    // ── AI: Generate courses in bulk ──

    #[Route('/generate-ai', name: 'app_training_generate_ai', methods: ['POST'])]
    #[IsGranted('ROLE_HR')]
    public function generateAiCourses(
        Request $request,
        EntityManagerInterface $em,
        AIService $ai
    ): Response {
        if (!$this->isCsrfTokenValid('generate_ai_courses', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_training_index', ['tab' => 'manage']);
        }

        $topic    = trim((string) $request->request->get('topic', ''));
        $megaLink = trim((string) $request->request->get('mega_link', ''));
        $count    = max(1, min(10, (int) $request->request->get('count', 5)));

        if (empty($topic)) {
            $this->addFlash('warning', 'Please provide a topic to generate courses.');
            return $this->redirectToRoute('app_training_index', ['tab' => 'manage']);
        }

        $systemPrompt = 'You are an expert HR training course designer for a corporate workforce platform. Return only raw JSON arrays with no markdown, no code fences, no explanation.';
        $megaNote = $megaLink
            ? ' Use this exact mega_link value for all courses: ' . $megaLink
            : ' Set mega_link to an empty string.';

        $userMessage = sprintf(
            'Generate %d professional workplace training courses on the topic: "%s".%s ' .
            'Return ONLY a raw JSON array like: ' .
            '[{"title":"...","description":"2-3 sentence engaging description","category":"TECHNICAL","difficulty":"BEGINNER","duration_hours":4,"instructor_name":"Full Name","mega_link":"...","quiz_timer_seconds":15}]. ' .
            'Allowed categories: TECHNICAL, SOFT_SKILLS, COMPLIANCE, ONBOARDING, LEADERSHIP. ' .
            'Allowed difficulties: BEGINNER, INTERMEDIATE, ADVANCED. ' .
            'Vary the categories and difficulties across courses. Return the raw JSON array only — nothing else.',
            $count,
            $topic,
            $megaNote
        );

        $raw = $ai->chat($systemPrompt, $userMessage, 0.8, 2048);

        if (!$raw) {
            $this->addFlash('danger', 'AI generation failed. Please try again.');
            return $this->redirectToRoute('app_training_index', ['tab' => 'manage']);
        }

        $raw = (string) preg_replace('/^```(?:json)?\s*/im', '', $raw);
        $raw = (string) preg_replace('/\s*```$/m', '', $raw);
        $raw = trim($raw);

        $courses = json_decode($raw, true);
        if (!is_array($courses) || count($courses) === 0) {
            $this->addFlash('danger', 'AI returned invalid data. Try again with a different topic.');
            return $this->redirectToRoute('app_training_index', ['tab' => 'manage']);
        }

        $validCategories  = ['TECHNICAL', 'SOFT_SKILLS', 'COMPLIANCE', 'ONBOARDING', 'LEADERSHIP'];
        $validDifficulties = ['BEGINNER', 'INTERMEDIATE', 'ADVANCED'];
        $created = 0;

        foreach ($courses as $cd) {
            if (empty($cd['title'])) continue;
            $course = new TrainingCourse();
            $course->setTitle(substr((string) $cd['title'], 0, 255));
            $course->setDescription($cd['description'] ?? null);
            $course->setCategory(in_array($cd['category'] ?? '', $validCategories) ? $cd['category'] : 'TECHNICAL');
            $course->setDifficulty(in_array($cd['difficulty'] ?? '', $validDifficulties) ? $cd['difficulty'] : 'BEGINNER');
            $course->setDurationHours(isset($cd['duration_hours']) ? (float) $cd['duration_hours'] : null);
            $course->setInstructorName($cd['instructor_name'] ?? null);
            $course->setMegaLink($megaLink ?: (isset($cd['mega_link']) && $cd['mega_link'] ? $cd['mega_link'] : null));
            $course->setQuizTimerSeconds(isset($cd['quiz_timer_seconds']) ? (int) $cd['quiz_timer_seconds'] : 15);
            $course->setStatus('ACTIVE');
            /** @var \App\Entity\User|null $creator */
            $creator = $this->getUser();
            $course->initCreatedBy($creator);
            $em->persist($course);
            $created++;
        }

        $em->flush();
        $this->addFlash('success', sprintf('🤖 AI generated %d courses successfully!', $created));
        return $this->redirectToRoute('app_training_index', ['tab' => 'manage']);
    }

    // ── Enrollment: update progress (AJAX) ──

    #[Route('/enrollment/{id}/progress', name: 'app_training_enrollment_progress', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateProgress(
        TrainingEnrollment $enrollment,
        Request $request,
        EntityManagerInterface $em
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('progress' . $enrollment->getId(), (string) $request->request->get('_token'))) {
            return new JsonResponse(['ok' => false, 'error' => 'Invalid CSRF token.'], 400);
        }

        if ($enrollment->getUser()?->getId() !== $this->getUser()?->getId()) {
            return new JsonResponse(['ok' => false, 'error' => 'Access denied.'], 403);
        }

        if ($enrollment->getStatus() === 'COMPLETED') {
            return new JsonResponse(['ok' => false, 'error' => 'Cannot edit a completed enrollment.'], 400);
        }

        $progress = max(0, min(100, (int) $request->request->get('progress', 0)));
        $enrollment->setProgress($progress);

        if ($progress > 0 && $enrollment->getStatus() === 'ENROLLED') {
            $enrollment->setStatus('IN_PROGRESS');
        }

        $em->flush();
        return new JsonResponse(['ok' => true, 'progress' => $progress, 'status' => $enrollment->getStatus()]);
    }

    // ── AI: Course recommendations (AJAX) ──

    #[Route('/recommendations-ai', name: 'app_training_recommendations_ai')]
    public function recommendationsAi(
        TrainingEnrollmentRepository $enrollRepo,
        TrainingCourseRepository $courseRepo,
        AIService $ai
    ): JsonResponse {
        $user        = $this->getUser();
        $enrollments = $user ? $enrollRepo->findBy(['user' => $user], [], 10) : [];
        $allCourses  = $courseRepo->findBy(['status' => 'ACTIVE'], [], 30);

        $enrolledIds = array_map(fn($e) => $e->getCourse()?->getId(), $enrollments);

        $enrolledSummary = implode(', ', array_map(
            fn($e) => '"' . ($e->getCourse()?->getTitle() ?? '') . '" (' . $e->getStatus() . ')',
            $enrollments
        )) ?: 'None yet';

        $available = array_values(array_filter(
            $allCourses,
            fn($c) => !in_array($c->getId(), $enrolledIds)
        ));

        if (count($available) === 0) {
            return new JsonResponse(['recommendations' => []]);
        }

        $availableSummary = implode(', ', array_map(
            fn($c) => '"' . $c->getTitle() . '" [' . $c->getCategory() . '/' . $c->getDifficulty() . ']',
            array_slice($available, 0, 20)
        ));

        $systemPrompt = 'You are a corporate training advisor. Return only raw JSON with no markdown, no code fences.';
        $userMessage  = sprintf(
            'Employee\'s enrolled courses: %s. ' .
            'Unenrolled available courses: %s. ' .
            'Recommend the 3 best next courses for this employee based on their learning history. ' .
            'Return raw JSON only: {"recommendations":[{"title":"exact title from the list","reason":"1-sentence reason"}]}.',
            $enrolledSummary,
            $availableSummary
        );

        $raw = $ai->chat($systemPrompt, $userMessage, 0.5, 512);

        if (!$raw) {
            return new JsonResponse(['recommendations' => []]);
        }

        $raw  = (string) preg_replace('/^```(?:json)?\s*/im', '', $raw);
        $raw  = (string) preg_replace('/\s*```$/m', '', $raw);
        $raw  = trim($raw);
        $data = json_decode($raw, true);
        $recs = $data['recommendations'] ?? [];

        // Attach course IDs for linking
        $titleMap = [];
        foreach ($available as $c) {
            $titleMap[strtolower(trim((string) $c->getTitle()))] = $c->getId();
        }
        foreach ($recs as &$r) {
            $r['course_id'] = $titleMap[strtolower(trim($r['title'] ?? ''))] ?? null;
        }
        unset($r);

        return new JsonResponse(['recommendations' => array_slice($recs, 0, 3)]);
    }

    // ── Certificate: PDF download ──

    #[Route('/certificate/{id}/pdf', name: 'app_training_certificate_pdf', requirements: ['id' => '\d+'])]
    public function certificatePdf(
        TrainingCertificate $certificate,
        UserRepository $userRepo,
        EntityManagerInterface $em
    ): Response {
        if (!$this->isGranted('ROLE_HR')) {
            if ($certificate->getUser()?->getId() !== $this->getUser()?->getId()) {
                throw $this->createAccessDeniedException('You can only view your own certificates.');
            }
        }

        if (!$certificate->getSignedAt() && !$certificate->getSignatureData()) {
            $this->autoSignCertificate($certificate);
            $em->flush();
        }

        $signerName = null;
        if ($certificate->getSignedByUserId()) {
            $signer = $userRepo->find($certificate->getSignedByUserId());
            if ($signer) {
                $signerName = $signer->getFirstName() . ' ' . $signer->getLastName();
            }
        }

        $html = $this->renderView('training/certificate_pdf.html.twig', [
            'cert'       => $certificate,
            'signerName' => $signerName,
        ]);

        if (class_exists(Dompdf::class)) {
            $dompdf = new Dompdf();
            $dompdf->set_option('isRemoteEnabled', false);
            $dompdf->set_option('isHtml5ParserEnabled', true);
            $dompdf->set_option('defaultFont', 'serif');
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('letter', 'landscape');
            $dompdf->render();

            $pdfOutput = $dompdf->output();
        } else {
            $pdfOutput = $this->buildFallbackCertificatePdf($certificate, $signerName);
        }

        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower((string)($certificate->getCertificateNumber() ?? 'cert')));
        $filename = 'certificate-' . $slug . '.pdf';

        return new Response(
            $pdfOutput,
            200,
            [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]
        );
    }

    // ── Quiz: AI-generated (instant feedback mode — same generator as quiz route) ──

    #[Route('/{id}/quiz-ai', name: 'app_training_quiz_ai', requirements: ['id' => '\d+'])]
    public function quizAi(
        TrainingCourse $course,
        Request $request,
        SessionInterface $session,
        AIService $ai,
        TrainingEnrollmentRepository $enrollRepo
    ): Response {
        $user = $this->getUser();
        $enrollment = $enrollRepo->findOneBy(['course' => $course, 'user' => $user]);
        if (!$enrollment) {
            $this->addFlash('warning', 'You must be enrolled to take the quiz.');
            return $this->redirectToRoute('app_training_show', ['id' => $course->getId()]);
        }

        $data = $this->generateCourseQuizData($course, $ai);

        if ($data === null) {
            $this->addFlash('warning', 'AI quiz generation failed. Starting standard quiz instead.');
            return $this->redirectToRoute('app_training_quiz', ['id' => $course->getId()]);
        }

        ['questions' => $rawQs, 'correctAnswers' => $sessionAnswers, 'timer' => $timer] = $data;

        // quiz_ai.html.twig expects 'q', 'options', 'answer', 'category' keys
        $questions = array_map(fn($q) => [
            'index'    => $q['index'],
            'q'        => $q['q'],
            'options'  => $q['options'],
            'answer'   => $q['answer'],
            'category' => $course->getTitle(),
        ], $rawQs);

        $session->set('quiz_answers_' . $course->getId(), $sessionAnswers);
        $session->set('quiz_course_id', $course->getId());

        return $this->render('training/quiz_ai.html.twig', [
            'course'    => $course,
            'questions' => $questions,
            'timer'     => $timer,
        ]);
    }

    /**
     * Shared AI quiz generator — mirrors Java ZAIService::generateCourseQuiz().
     * Returns ['questions' => [...], 'correctAnswers' => [...], 'timer' => int]
     * or null on failure.
     * @return array{questions: array<int, array<string, mixed>>, correctAnswers: array<int, int>, timer: int}|null
     */
    private function generateCourseQuizData(TrainingCourse $course, AIService $ai): ?array
    {
        $difficulty   = $course->getDifficulty() ?? 'INTERMEDIATE';
        $customTimer  = $course->getQuizTimerSeconds();
        $defaultTimer = match ($difficulty) {
            'ADVANCED'     => 18,
            'INTERMEDIATE' => 13,
            default        => 11,
        };

        $systemPrompt = <<<SYSTEM
You are an expert course examiner. Generate a multiple-choice quiz specifically about this course topic.
Return ONLY valid JSON — no markdown, no code fences, no explanation.
Exact structure:
{"recommended_timer_seconds":<int>,"questions":[{"q":"<question>","options":["A","B","C","D"],"answer":<0-3>}]}
Rules:
- Generate exactly 10 questions about the COURSE TOPIC only. Do NOT include questions about unrelated subjects.
- Each question must have exactly 4 answer options.
- "answer" is the 0-based index of the correct option.
- Questions must test understanding of the course material, not general trivia.
- Difficulty: {$difficulty}.
- recommended_timer_seconds: BEGINNER=12, INTERMEDIATE=15, ADVANCED=18.
SYSTEM;

        $userMessage = sprintf(
            'Course title: "%s". Category: %s. Difficulty: %s. Description: %s. Generate 10 multiple-choice questions about this specific topic.',
            $course->getTitle(),
            $course->getCategory() ?? 'TECHNICAL',
            $difficulty,
            substr($course->getDescription() ?? 'Professional workplace training course.', 0, 400)
        );

        // Keep quiz generation under proxy timeouts; if AI is slow, we fall back to local questions.
        $raw = $ai->chatFast($systemPrompt, $userMessage, 0.2, 1600);

        $parsed = null;
        if ($raw) {
            $firstBrace = strpos($raw, '{');
            $lastBrace  = strrpos($raw, '}');
            if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
                $parsed = json_decode(substr($raw, $firstBrace, $lastBrace - $firstBrace + 1), true);
            }
        }

        // Build questions from AI response
        $questions      = [];
        $correctAnswers = [];

        if (is_array($parsed) && !empty($parsed['questions'])) {
            foreach ($parsed['questions'] as $i => $q) {
                if (!isset($q['q'], $q['options'], $q['answer'])) continue;
                $opts = array_values(array_slice((array) $q['options'], 0, 4));
                if (count($opts) < 2) continue;
                $ansIdx = max(0, min(count($opts) - 1, (int) $q['answer']));
                $questions[]        = ['index' => $i, 'q' => (string) $q['q'], 'options' => $opts, 'answer' => $ansIdx];
                $correctAnswers[$i] = $ansIdx;
            }
        }

        // If AI failed or returned no/wrong questions, use a course-specific local fallback
        if (empty($questions)) {
            [$questions, $correctAnswers] = $this->fallbackQuizQuestions($course);
        }

        if (empty($questions)) return null;

        $timer = ($customTimer && $customTimer > 0)
            ? $customTimer
            : (isset($parsed['recommended_timer_seconds']) ? max(8, min(30, (int) $parsed['recommended_timer_seconds'])) : $defaultTimer);

        return ['questions' => $questions, 'correctAnswers' => $correctAnswers, 'timer' => $timer];
    }

    /**
     * Course-specific fallback quiz — used when AI is unavailable. Never uses external APIs.
     * @return array{array<int, array<string, mixed>>, array<int, int>}
     */
    private function fallbackQuizQuestions(TrainingCourse $course): array
    {
        $title    = $course->getTitle();
        $category = $course->getCategory() ?? 'TECHNICAL';

        // Generic professional development questions tailored by category
        $banks = [
            'TECHNICAL' => [
                ['q' => 'What is the primary goal of the "' . $title . '" course?', 'options' => ['Build practical skills in the subject area', 'Study unrelated entertainment topics', 'Memorize irrelevant facts', 'Practice cooking recipes'], 'answer' => 0],
                ['q' => 'Which approach best reflects mastery of a technical skill?', 'options' => ['Applying it to solve real problems', 'Memorizing definitions only', 'Watching videos passively', 'Avoiding practice'], 'answer' => 0],
                ['q' => 'In technical training, what is a "best practice"?', 'options' => ['A proven method that delivers reliable results', 'A shortcut that skips important steps', 'An outdated way of working', 'A personal opinion with no evidence'], 'answer' => 0],
                ['q' => 'What does continuous learning mean in a technical field?', 'options' => ['Regularly updating skills as technology evolves', 'Learning once and never revisiting topics', 'Avoiding new tools', 'Relying only on experience from years ago'], 'answer' => 0],
                ['q' => 'Why is documentation important in technical work?', 'options' => ['It helps others understand and maintain the work', 'It is optional and rarely useful', 'It slows down projects', 'It is only needed for non-technical staff'], 'answer' => 0],
                ['q' => 'What is a key benefit of completing the "' . $title . '" course?', 'options' => ['Gaining verified, applicable knowledge in the subject', 'Learning facts about video games', 'Earning unrelated credentials', 'Studying topics outside your field'], 'answer' => 0],
                ['q' => 'Which skill is most valuable after completing a technical course?', 'options' => ['Applying learned concepts to workplace challenges', 'Reciting theory without understanding', 'Completing the course as fast as possible', 'Ignoring practical exercises'], 'answer' => 0],
                ['q' => 'How should you handle a technical problem you have not seen before?', 'options' => ['Break it down, research, and apply systematic reasoning', 'Give up immediately', 'Ask someone else to do it', 'Ignore it and move on'], 'answer' => 0],
                ['q' => 'What is the purpose of a quiz at the end of a training module?', 'options' => ['To verify understanding and reinforce learning', 'To entertain the learner', 'To test memory of unrelated topics', 'To discourage further study'], 'answer' => 0],
                ['q' => 'Which habit best supports long-term mastery of a technical subject?', 'options' => ['Regular practice and reviewing new developments', 'Studying once and never revisiting', 'Relying on others to solve problems', 'Avoiding hands-on projects'], 'answer' => 0],
            ],
            'SOFT_SKILLS' => [
                ['q' => 'What is the main focus of the "' . $title . '" course?', 'options' => ['Developing interpersonal and professional competencies', 'Learning programming languages', 'Studying historical events', 'Practicing physical exercises'], 'answer' => 0],
                ['q' => 'What is active listening?', 'options' => ['Fully concentrating on and understanding the speaker', 'Waiting for your turn to speak', 'Multitasking while someone talks', 'Interrupting frequently'], 'answer' => 0],
                ['q' => 'Which of the following best describes emotional intelligence?', 'options' => ['Recognising and managing your emotions and others\'s', 'Being able to perform complex calculations', 'Memorising large amounts of data', 'Avoiding all conflict at work'], 'answer' => 0],
                ['q' => 'How does effective communication improve team performance?', 'options' => ['It reduces misunderstandings and aligns goals', 'It increases email volume unnecessarily', 'It replaces the need for meetings', 'It has no measurable impact'], 'answer' => 0],
                ['q' => 'What does constructive feedback look like?', 'options' => ['Specific, actionable, and delivered respectfully', 'Vague criticism with no suggestions', 'Personal attacks on character', 'Praise with no honest assessment'], 'answer' => 0],
                ['q' => 'Why is time management a critical professional skill?', 'options' => ['It increases productivity and reduces stress', 'It allows you to work fewer hours', 'It eliminates the need for priorities', 'It is only useful for managers'], 'answer' => 0],
                ['q' => 'Which approach best resolves workplace conflict?', 'options' => ['Open dialogue focused on the issue, not the person', 'Avoiding the other party indefinitely', 'Escalating immediately without discussion', 'Winning the argument at all costs'], 'answer' => 0],
                ['q' => 'What is the value of professional networking?', 'options' => ['Building relationships that support career growth', 'Collecting business cards without follow-up', 'Avoiding collaboration with peers', 'Promoting yourself aggressively'], 'answer' => 0],
                ['q' => 'What does adaptability in the workplace mean?', 'options' => ['Adjusting effectively to new situations and challenges', 'Refusing to change established routines', 'Working in the same way regardless of context', 'Avoiding new projects'], 'answer' => 0],
                ['q' => 'How does completing "' . $title . '" benefit your career?', 'options' => ['Strengthens professional skills valued by employers', 'Provides knowledge irrelevant to your role', 'Guarantees an immediate promotion', 'Has no practical workplace application'], 'answer' => 0],
            ],
            'LEADERSHIP' => [
                ['q' => 'What is the primary goal of the "' . $title . '" course?', 'options' => ['Develop leadership capabilities and management skills', 'Learn advanced software development', 'Study unrelated academic subjects', 'Practice financial accounting'], 'answer' => 0],
                ['q' => 'Which leadership style adapts based on the team\'s needs?', 'options' => ['Situational leadership', 'Autocratic leadership', 'Laissez-faire leadership', 'Bureaucratic leadership'], 'answer' => 0],
                ['q' => 'What is a key responsibility of an effective leader?', 'options' => ['Empowering team members to reach their potential', 'Making all decisions without input', 'Avoiding difficult conversations', 'Focusing only on personal goals'], 'answer' => 0],
                ['q' => 'What does "leading by example" mean?', 'options' => ['Demonstrating the behaviour you expect from others', 'Delegating all work to team members', 'Enforcing rules strictly without explanation', 'Avoiding accountability'], 'answer' => 0],
                ['q' => 'Why is trust essential in a leadership role?', 'options' => ['It builds psychological safety and encourages openness', 'It slows down decision-making', 'It reduces the need for processes', 'It is only important at senior levels'], 'answer' => 0],
                ['q' => 'How does a leader handle underperformance on their team?', 'options' => ['Address it directly with coaching and clear expectations', 'Ignore it and hope it resolves itself', 'Replace the person immediately', 'Complain to others without acting'], 'answer' => 0],
                ['q' => 'What distinguishes a manager from a leader?', 'options' => ['Leaders inspire vision; managers execute processes', 'They are identical roles', 'Managers are always senior to leaders', 'Leaders never manage people'], 'answer' => 0],
                ['q' => 'What is the role of delegation in effective leadership?', 'options' => ['It develops team capability and frees leaders for strategy', 'It means giving away all responsibility', 'It only works with highly experienced teams', 'It reduces accountability'], 'answer' => 0],
                ['q' => 'How should a leader respond to failure?', 'options' => ['Analyse what went wrong, learn, and adjust', 'Blame the team for all mistakes', 'Ignore failures to maintain morale', 'Avoid taking any future risks'], 'answer' => 0],
                ['q' => 'What is a measurable sign that the "' . $title . '" course was effective?', 'options' => ['Improved decision-making and team results', 'No change in leadership behaviours', 'More time spent in meetings', 'Higher frequency of escalations'], 'answer' => 0],
            ],
            'COMPLIANCE' => [
                ['q' => 'Why is compliance training important for organizations?', 'options' => ['It reduces legal risk and ensures ethical behaviour', 'It is only required by small companies', 'It replaces the need for HR policies', 'It has no measurable benefit'], 'answer' => 0],
                ['q' => 'What should you do if you observe a potential compliance violation?', 'options' => ['Report it through the appropriate channel immediately', 'Ignore it to avoid conflict', 'Handle it yourself without reporting', 'Wait to see if it happens again'], 'answer' => 0],
                ['q' => 'What does data privacy compliance require of employees?', 'options' => ['Handling personal data securely per applicable regulations', 'Sharing data freely within the organization', 'Storing data on personal devices', 'Ignoring data protection policies'], 'answer' => 0],
                ['q' => 'What is a conflict of interest in a workplace context?', 'options' => ['A personal interest that could improperly influence a decision', 'Disagreeing with a colleague on a task', 'Competing for the same project', 'Having different working styles'], 'answer' => 0],
                ['q' => 'Why must employees complete the "' . $title . '" course?', 'options' => ['To understand and meet legal and regulatory obligations', 'It is optional with no consequences', 'To satisfy personal curiosity', 'To avoid doing other work'], 'answer' => 0],
                ['q' => 'What does workplace anti-harassment policy protect?', 'options' => ['Every employee\'s right to a safe, respectful environment', 'Only senior employees', 'Contractors but not employees', 'Only applies during working hours'], 'answer' => 0],
                ['q' => 'When is it acceptable to share confidential company information?', 'options' => ['Only when authorised and with appropriate parties', 'Whenever a colleague asks', 'Always, to promote transparency', 'Only after working hours'], 'answer' => 0],
                ['q' => 'What is the consequence of ignoring compliance requirements?', 'options' => ['Legal penalties, reputational damage, and disciplinary action', 'No consequence if discovered later', 'A minor warning with no follow-up', 'It only affects management'], 'answer' => 0],
                ['q' => 'What does "due diligence" mean in a compliance context?', 'options' => ['Taking reasonable steps to verify information before acting', 'Completing work as quickly as possible', 'Delegating decisions to others', 'Avoiding documentation'], 'answer' => 0],
                ['q' => 'How often should employees review compliance policies?', 'options' => ['Regularly, and especially when policies are updated', 'Only during onboarding', 'Never after initial training', 'Once every five years'], 'answer' => 0],
            ],
            'ONBOARDING' => [
                ['q' => 'What is the primary purpose of the "' . $title . '" program?', 'options' => ['Integrate new employees into the organization effectively', 'Test new employees\' technical skills only', 'Replace the need for a manager', 'Cover topics unrelated to the role'], 'answer' => 0],
                ['q' => 'What should a new employee prioritise in their first week?', 'options' => ['Understanding the role, team, and company culture', 'Completing as many tasks as possible without guidance', 'Challenging existing processes immediately', 'Working independently without asking questions'], 'answer' => 0],
                ['q' => 'Why is understanding company values important during onboarding?', 'options' => ['Values guide decision-making and workplace behaviour', 'Values are only relevant to senior leadership', 'They are decorative and have no practical impact', 'Values change too frequently to be useful'], 'answer' => 0],
                ['q' => 'How should you approach learning your new role?', 'options' => ['Ask questions, observe, and practice with feedback', 'Pretend to know everything to appear confident', 'Avoid asking questions to not seem incompetent', 'Wait until you have a problem before learning'], 'answer' => 0],
                ['q' => 'What is a 30-60-90 day plan?', 'options' => ['A structured roadmap for your first three months in a role', 'A financial forecast for a project', 'A compliance checklist', 'A performance review format'], 'answer' => 0],
                ['q' => 'Why is meeting your team early in onboarding important?', 'options' => ['Relationships improve collaboration and information flow', 'It is a formality with no practical benefit', 'It should be delayed until after 3 months', 'It is only important for managers'], 'answer' => 0],
                ['q' => 'What does a buddy system during onboarding provide?', 'options' => ['Peer support, guidance, and faster cultural integration', 'A friend unrelated to work', 'A way to avoid manager contact', 'An alternative to formal training'], 'answer' => 0],
                ['q' => 'What is the best way to set expectations in a new role?', 'options' => ['Discuss goals and priorities clearly with your manager', 'Guess what is expected and act accordingly', 'Wait for your first performance review', 'Avoid discussions about expectations'], 'answer' => 0],
                ['q' => 'How do you know onboarding was successful?', 'options' => ['You understand your role, team, and can perform core tasks', 'You completed all forms and paperwork', 'You attended every meeting', 'You know everyone\'s name in the company'], 'answer' => 0],
                ['q' => 'Why is feedback important during the onboarding period?', 'options' => ['It helps correct course early and accelerates integration', 'It is only useful after the first year', 'Negative feedback should be avoided early on', 'It undermines new employee confidence'], 'answer' => 0],
            ],
        ];

        $pool = $banks[$category] ?? $banks['TECHNICAL'];

        // Shuffle so repeated attempts get different order
        shuffle($pool);
        $pool = array_slice($pool, 0, 10);

        $questions      = [];
        $correctAnswers = [];
        foreach ($pool as $i => $q) {
            // Shuffle options while tracking the correct answer
            $options     = $q['options'];
            $correctText = $options[$q['answer']];
            shuffle($options);
            $newCorrect = (int) array_search($correctText, $options, true);

            $questions[]        = ['index' => $i, 'q' => $q['q'], 'options' => $options, 'answer' => $newCorrect];
            $correctAnswers[$i] = $newCorrect;
        }

        return [$questions, $correctAnswers];
    }

    private function autoSignCertificate(TrainingCertificate $certificate): void
    {
        if ($certificate->getSignedAt()) {
            return;
        }

        $certificate->initSignedAt(new \DateTime());
        if ($certificate->getUser()) {
            $certificate->setSignedByUserId($certificate->getUser()->getId());
        }
    }

    private function buildFallbackCertificatePdf(TrainingCertificate $certificate, ?string $signerName): string
    {
        $recipient = trim((string) ($certificate->getUser()?->getFirstName() . ' ' . $certificate->getUser()?->getLastName()));
        if ($recipient === '') {
            $recipient = 'Participant';
        }

        $courseTitle = $certificate->getCourse()?->getTitle() ?? 'Training Course';
        $certificateNumber = $certificate->getCertificateNumber() ?? 'SG-CERTIFICATE';
        $issuedDate = $certificate->getIssuedAt()?->format('F j, Y') ?? 'N/A';
        $signedDate = $certificate->getSignedAt()?->format('F j, Y') ?? $issuedDate;
        $signerLabel = $signerName ?: 'Authorized Signatory';

        $content = "% Simple certificate fallback\n";
        $content .= $this->pdfTextLine('F2', 30, 185, 520, 'Certificate of Completion');
        $content .= $this->pdfTextLine('F1', 15, 287, 486, 'SynergyGig Training Center');
        $content .= $this->pdfTextLine('F1', 16, 255, 434, 'This certifies that');
        $content .= $this->pdfTextLine('F2', 24, 210, 392, $recipient);
        $content .= $this->pdfTextLine('F1', 16, 185, 348, 'has successfully completed the training course');
        $content .= $this->pdfTextLine('F2', 22, 145, 306, $courseTitle);
        $content .= $this->pdfTextLine('F1', 13, 120, 248, 'Certificate No.: ' . $certificateNumber);
        $content .= $this->pdfTextLine('F1', 13, 120, 224, 'Issued: ' . $issuedDate);
        $content .= $this->pdfTextLine('F1', 13, 120, 200, 'Signed: ' . $signedDate);
        $content .= $this->pdfTextLine('F1', 13, 120, 156, 'Signer: ' . $signerLabel);
        $content .= $this->pdfTextLine('F1', 11, 120, 118, 'Auto-generated certificate download');

        $objects = [];
        $objects[] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $objects[] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
        $objects[] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 792 612] /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> /Contents 4 0 R >>\nendobj\n";
        $objects[] = "4 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream\nendobj\n";
        $objects[] = "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";
        $objects[] = "6 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>\nendobj\n";

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

        return $pdf;
    }

    private function pdfTextLine(string $font, int $size, int $x, int $y, string $text): string
    {
        return sprintf(
            "BT /%s %d Tf 1 0 0 1 %d %d Tm (%s) Tj ET\n",
            $font,
            $size,
            $x,
            $y,
            $this->escapePdfText($text)
        );
    }

    private function escapePdfText(string $text): string
    {
        $encoded = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        if ($encoded === false) {
            $encoded = preg_replace('/[^\x20-\x7E]/', '', $text) ?? '';
        }

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded);
    }

    // ── Certificate: sign ──

    #[Route('/certificate/{id}/sign', name: 'app_training_certificate_sign', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function signCertificate(
        TrainingCertificate $certificate,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        if (!$this->isCsrfTokenValid('certsign' . $certificate->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_training_show', ['id' => (int) $certificate->getCourse()?->getId()]);
        }

        $errors = [];

        // Validate signature data (must be data:image/png;base64,...)
        $signatureData = (string) $request->request->get('signature_data', '');
        if (empty($signatureData)) {
            $errors[] = 'Signature is required.';
        } elseif (!preg_match('/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/', $signatureData)) {
            $errors[] = 'Invalid signature format.';
        } elseif (strlen($signatureData) > 500000) {
            $errors[] = 'Signature data is too large.';
        }

        if (!empty($errors)) {
            foreach ($errors as $e) {
                $this->addFlash('danger', $e);
            }
            return $this->redirectToRoute('app_training_show', ['id' => (int) $certificate->getCourse()?->getId()]);
        }

        $certificate->setSignatureData($signatureData);
        $certificate->initSignedAt(new \DateTime());
        if ($certificate->getUser()) {
            $certificate->setSignedByUserId($certificate->getUser()->getId());
        }

        $em->flush();
        $this->addFlash('success', 'Certificate signed successfully!');

        return $this->redirectToRoute('app_training_show', ['id' => (int) $certificate->getCourse()?->getId()]);
    }
}
