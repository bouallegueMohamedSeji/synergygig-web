<?php

namespace App\Controller;

use App\Entity\Bookmark;
use App\Entity\Comment;
use App\Entity\Post;
use App\Entity\Reaction;
use App\Entity\CommunityGroup;
use App\Entity\GroupMember;
use App\Entity\UserFollow;
use App\Repository\BookmarkRepository;
use App\Repository\PostRepository;
use App\Repository\CommentRepository;
use App\Repository\CommunityGroupRepository;
use App\Repository\UserRepository;
use App\Service\BadWordsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/community')]
class CommunityController extends AbstractController
{
    // ── Twemoji data (same 6 types as Java EmojiPicker) ──
    private const EMOJI_MAP = [
        'heart'    => ['hex' => '2764',  'label' => '❤️'],
        'thumbsup' => ['hex' => '1f44d', 'label' => '👍'],
        'laugh'    => ['hex' => '1f602', 'label' => '😂'],
        'fire'     => ['hex' => '1f525', 'label' => '🔥'],
        'clap'     => ['hex' => '1f44f', 'label' => '👏'],
        'wow'      => ['hex' => '1f62e', 'label' => '😮'],
    ];
    private const TWEMOJI_CDN = 'https://cdn.jsdelivr.net/gh/twitter/twemoji@14.0.2/assets/72x72/';

    // ── Emoji picker categories (for post composer) ──
    private const EMOJI_PICKER = [
        'Smileys' => ['😀','😃','😄','😁','😆','😅','🤣','😂','🙂','😊','😇','🥰','😍','🤩','😘','😗','😋','😛','😜','🤪','😝','🤑','🤗','🤭','🤫','🤔','😐','😑','😶','😏','😒','🙄','😬','😮‍💨','🤥','😌','😔','😪','🤤','😴','😷','🤒','🤕','🤧','🥵','🥶','🥴','😵','🤯','🤠','🥳','🥸','😎','🤓','🧐'],
        'Gestures' => ['👋','🤚','🖐','✋','🖖','👌','🤌','🤏','✌️','🤞','🤟','🤘','🤙','👈','👉','👆','🖕','👇','👍','👎','✊','👊','🤛','🤜','👏','🙌','👐','🤲','🙏','💪'],
        'Hearts' => ['❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💔','❣️','💕','💞','💓','💗','💖','💘','💝','💟'],
        'Objects' => ['🎉','🎊','🎈','🎁','🏆','🥇','⭐','🌟','✨','💫','🔥','💥','💯','📌','📎','✏️','📝','💼','📁','🗂'],
        'Nature' => ['🌸','🌺','🌻','🌹','🌷','🌼','💐','🌿','☘️','🍀','🌈','☀️','🌤','⛅','🌥','☁️','🌧','⛈','🌩','❄️'],
    ];

    // ── Sidebar helper ──
    /** @return array<string, mixed> */
    private function sidebarData(CommunityGroupRepository $groupRepo, EntityManagerInterface $em): array
    {
        $user = $this->getUser();
        $myGroups = [];
        $bookmarkCount = 0;
        if ($user) {
            $members = $em->getRepository(GroupMember::class)->findBy(['user' => $user]);
            foreach ($members as $m) {
                if ($m->getGroup()) $myGroups[] = $m->getGroup();
            }
            $bookmarkCount = count($em->getRepository(Bookmark::class)->findBy(['user' => $user]));
        }
        if (empty($myGroups)) {
            $myGroups = $groupRepo->findBy([], ['id' => 'DESC'], 5);
        }
        return ['sidebarGroups' => $myGroups, 'bookmarkCount' => $bookmarkCount];
    }

    // ── Reactions helper ──
    /**
     * @param array<mixed> $posts
     * @return array<int|string, array{counts: array<string, int>, userReaction: string|null, total: int}>
     */
    private function getPostReactions(EntityManagerInterface $em, array $posts): array
    {
        $map = [];
        $user = $this->getUser();
        foreach ($posts as $post) {
            $reactions = $em->getRepository(Reaction::class)->findBy(['post' => $post]);
            $counts = [];
            $userReaction = null;
            foreach ($reactions as $r) {
                $t = $r->getType();
                $counts[$t] = ($counts[$t] ?? 0) + 1;
                if ($user && $r->getUser() && $r->getUser()->getId() === $user->getId()) {
                    $userReaction = $t;
                }
            }
            $map[$post->getId()] = ['counts' => $counts, 'userReaction' => $userReaction, 'total' => count($reactions)];
        }
        return $map;
    }

    // ── Relative time helper ──
    public static function relativeTime(\DateTimeInterface $date): string
    {
        $diff = (new \DateTime())->getTimestamp() - $date->getTimestamp();
        if ($diff < 60) return 'just now';
        if ($diff < 3600) return floor($diff / 60) . 'm ago';
        if ($diff < 86400) return floor($diff / 3600) . 'h ago';
        if ($diff < 604800) return floor($diff / 86400) . 'd ago';
        return $date->format('M d, Y');
    }

    // ═══════════════════════════
    //  FEED
    // ═══════════════════════════

    #[Route('/', name: 'app_community_index')]
    public function index(PostRepository $postRepo, CommunityGroupRepository $groupRepo, EntityManagerInterface $em): Response
    {
        $posts = $postRepo->findBy([], ['created_at' => 'DESC'], 100);
        $user = $this->getUser();
        $bookmarkedIds = [];
        if ($user) {
            $bookmarks = $em->getRepository(Bookmark::class)->findBy(['user' => $user]);
            foreach ($bookmarks as $b) {
                if ($b->getPost()) $bookmarkedIds[] = $b->getPost()->getId();
            }
        }
        return $this->render('community/index.html.twig', array_merge([
            'posts' => $posts,
            'reactions' => $this->getPostReactions($em, $posts),
            'bookmarkedIds' => $bookmarkedIds,
            'emojiMap' => self::EMOJI_MAP,
            'twemojiCdn' => self::TWEMOJI_CDN,
            'emojiPicker' => self::EMOJI_PICKER,
            'tab' => 'home',
        ], $this->sidebarData($groupRepo, $em)));
    }

    #[Route('/discover', name: 'app_community_discover')]
    public function discover(PostRepository $postRepo, CommunityGroupRepository $groupRepo, EntityManagerInterface $em): Response
    {
        $posts = $postRepo->findBy([], ['created_at' => 'DESC'], 100);
        $user = $this->getUser();
        $bookmarkedIds = [];
        if ($user) {
            $bookmarks = $em->getRepository(Bookmark::class)->findBy(['user' => $user]);
            foreach ($bookmarks as $b) {
                if ($b->getPost()) $bookmarkedIds[] = $b->getPost()->getId();
            }
        }
        return $this->render('community/index.html.twig', array_merge([
            'posts' => $posts,
            'reactions' => $this->getPostReactions($em, $posts),
            'bookmarkedIds' => $bookmarkedIds,
            'emojiMap' => self::EMOJI_MAP,
            'twemojiCdn' => self::TWEMOJI_CDN,
            'emojiPicker' => self::EMOJI_PICKER,
            'tab' => 'discover',
        ], $this->sidebarData($groupRepo, $em)));
    }

    // ── My Saved Posts ──
    #[Route('/saved', name: 'app_community_saved')]
    public function saved(CommunityGroupRepository $groupRepo, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        $posts = [];
        $bookmarkedIds = [];
        if ($user) {
            $bookmarks = $em->getRepository(Bookmark::class)->findBy(['user' => $user], ['created_at' => 'DESC']);
            foreach ($bookmarks as $b) {
                if ($b->getPost()) {
                    $posts[] = $b->getPost();
                    $bookmarkedIds[] = $b->getPost()->getId();
                }
            }
        }
        return $this->render('community/index.html.twig', array_merge([
            'posts' => $posts,
            'reactions' => $this->getPostReactions($em, $posts),
            'bookmarkedIds' => $bookmarkedIds,
            'emojiMap' => self::EMOJI_MAP,
            'twemojiCdn' => self::TWEMOJI_CDN,
            'emojiPicker' => self::EMOJI_PICKER,
            'tab' => 'saved',
        ], $this->sidebarData($groupRepo, $em)));
    }

    // ═══════════════════════════
    //  POST CRUD
    // ═══════════════════════════

    #[Route('/new', name: 'app_community_new', methods: ['POST'])]
    public function newPost(Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('new_post', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('app_community_index');
        }

        $content = trim((string) $request->request->get('content', ''));
        $file = $request->files->get('media');

        if ($content === '' && (!$file || !$file->isValid())) {
            $this->addFlash('warning', 'Please write something or attach media before posting.');
            return $this->redirectToRoute('app_community_index');
        }

        if (mb_strlen($content) > 5000) {
            $this->addFlash('error', 'Post is too long. Maximum 5000 characters allowed.');
            return $this->redirectToRoute('app_community_index');
        }

        if ($content !== '') {
            // Content moderation (same as Java)
            $check = BadWordsService::check($content);
            if ($check['hasBadWords']) {
                $this->addFlash('error', 'Your post contains inappropriate language. Please review and resubmit.');
                return $this->redirectToRoute('app_community_index');
            }
        }

        $visibility = strtoupper((string) $request->request->get('visibility', 'PUBLIC'));
        if (!in_array($visibility, ['PUBLIC', 'FRIENDS', 'PRIVATE'], true)) {
            $visibility = 'PUBLIC';
        }

        $post = new Post();
        $post->setContent($content);
        $post->setVisibility($visibility);
        $post->setLikesCount(0);
        $post->setCommentsCount(0);
        $post->setSharesCount(0);
        /** @var \App\Entity\User|null $postAuthor */
        $postAuthor = $this->getUser();
        $post->initAuthor($postAuthor);

        // Handle image/file upload (stored as base64)
        if ($file && $file->isValid()) {
            $allowed = ['image/jpeg','image/png','image/gif','image/webp','video/mp4','video/webm'];
            if (!in_array($file->getMimeType(), $allowed, true)) {
                $this->addFlash('error', 'Unsupported media format. Allowed: JPG, PNG, GIF, WEBP, MP4, WEBM.');
                return $this->redirectToRoute('app_community_index');
            }
            if ($file->getSize() > 10 * 1024 * 1024) {
                $this->addFlash('error', 'Media file is too large. Maximum size is 10MB.');
                return $this->redirectToRoute('app_community_index');
            }
            $rawData = file_get_contents($file->getPathname());
            $data = base64_encode($rawData !== false ? $rawData : '');
            $post->setImage_base64('data:' . $file->getMimeType() . ';base64,' . $data);
        }

        // Group context
        $groupId = $request->request->get('group_id');
        if ($groupId) {
            $group = $em->getRepository(CommunityGroup::class)->find($groupId);
            if ($group) {
                $post->setGroup($group);
            }
        }

        $em->persist($post);
        $em->flush();
        $this->addFlash('success', 'Post published!');

        $redirect = $request->request->get('redirect');
        if ($redirect) return $this->redirect((string) $redirect);
        return $this->redirectToRoute('app_community_index');
    }

    #[Route('/post/{id}/edit', name: 'app_community_edit', methods: ['POST'])]
    public function editPost(Request $request, Post $post, EntityManagerInterface $em): Response
    {
        if ($post->getAuthor() !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('You can only edit your own posts.');
        }
        $content = trim((string) $request->request->get('content', ''));
        if ($content !== '' && $this->isCsrfTokenValid('edit-post-' . $post->getId(), (string) $request->request->get('_token'))) {
            // Content moderation
            $check = BadWordsService::check($content);
            if ($check['hasBadWords']) {
                $this->addFlash('error', 'Your edit contains inappropriate language. Please review.');
                $from = $request->request->get('from', 'show');
                if ($from === 'index') return $this->redirectToRoute('app_community_index');
                return $this->redirectToRoute('app_community_show', ['id' => $post->getId()]);
            }

            $post->setContent($content);

            // Handle optional image replacement
            $file = $request->files->get('media');
            if ($file && $file->isValid()) {
                $allowed = ['image/jpeg','image/png','image/gif','image/webp','video/mp4','video/webm'];
                if (in_array($file->getMimeType(), $allowed) && $file->getSize() <= 10 * 1024 * 1024) {
                    $rawData2 = file_get_contents($file->getPathname());
                    $data = base64_encode($rawData2 !== false ? $rawData2 : '');
                    $post->setImage_base64('data:' . $file->getMimeType() . ';base64,' . $data);
                }
            }
            // Remove image if requested
            if ($request->request->get('remove_media')) {
                $post->setImage_base64(null);
            }

            $em->flush();
            $this->addFlash('success', 'Post updated.');
        }

        $from = $request->request->get('from', 'show');
        if ($from === 'index') return $this->redirectToRoute('app_community_index');
        return $this->redirectToRoute('app_community_show', ['id' => $post->getId()]);
    }

    // ── Groq API helper ──
    private function callGroqApi(string $systemPrompt, string $userMessage): ?string
    {
        $apiKey = $_ENV['GROQ_API_KEY'] ?? null;
        $model = $_ENV['GROQ_MODEL'] ?? 'llama-3.3-70b-versatile';
        $fallback = $_ENV['GROQ_FALLBACK_MODEL'] ?? 'llama-3.1-8b-instant';

        if (!$apiKey || $apiKey === 'your_groq_key') {
            return null;
        }

        $models = [$model, $fallback];
        foreach ($models as $m) {
            $payload = json_encode([
                'model' => $m,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userMessage],
                ],
                'temperature' => 0.7,
                'max_tokens' => 1024,
            ]);

            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\nAuthorization: Bearer " . $apiKey . "\r\n",
                    'content' => $payload,
                    'timeout' => 15,
                    'ignore_errors' => true,
                ],
            ]);

            $response = @file_get_contents('https://api.groq.com/openai/v1/chat/completions', false, $ctx);
            if ($response) {
                $data = json_decode($response, true);
                $text = $data['choices'][0]['message']['content'] ?? null;
                if ($text) return trim($text);
            }
        }

        return null;
    }

    #[Route('/ai-improve', name: 'app_community_ai_improve', methods: ['POST'])]
    public function aiImprove(Request $request): Response
    {
        $text = trim((string) $request->request->get('content', ''));
        $action = $request->request->get('ai_action', 'improve');

        if ($text === '') {
            $this->addFlash('warning', 'Write something first.');
            return $this->redirectToRoute('app_community_index');
        }

        $session = $request->getSession();

        if ($action === 'tone') {
            $systemPrompt = 'Analyze the tone and sentiment of the following text. Respond in EXACTLY this JSON format (no markdown, no code blocks, just raw JSON):
{"tones":["emoji label"],"harmful":false,"harmReason":"","sentiment":"positive/negative/neutral","confidence":0.9}

Rules for tones array — include ALL that apply:
- If positive/happy: "😊 Positive"
- If negative/sad: "😟 Negative"
- If angry/aggressive: "😡 Angry"
- If formal/professional: "👔 Formal"
- If casual/informal: "😎 Casual"
- If enthusiastic/excited: "⚡ Enthusiastic"
- If questioning: "🤔 Questioning"
- If sarcastic: "😏 Sarcastic"
- If neutral: "😐 Neutral"

CRITICAL: Set "harmful" to true if the text contains hate speech, threats, self-harm, bullying, harassment, slurs, or any toxic/abusive language. Provide the reason in "harmReason".';

            $aiResult = $this->callGroqApi($systemPrompt, $text);
            if ($aiResult) {
                $aiResult = (string) preg_replace('/^```(?:json)?\s*/', '', $aiResult);
                $aiResult = (string) preg_replace('/\s*```$/', '', $aiResult);
                $parsed = json_decode((string) $aiResult, true);
                if ($parsed && isset($parsed['tones'])) {
                    $tones = implode(', ', $parsed['tones']);
                    $sentiment = $parsed['sentiment'] ?? 'neutral';
                    $msg = '🎭 Tone: ' . $tones . ' | Sentiment: ' . ucfirst($sentiment);
                    if (!empty($parsed['harmful'])) {
                        $msg .= ' | ⚠️ WARNING: ' . ($parsed['harmReason'] ?? 'Harmful content detected');
                        $this->addFlash('error', $msg);
                    } else {
                        $this->addFlash('success', $msg);
                    }
                    $session->set('ai_composer_text', $text);
                    return $this->redirectToRoute('app_community_index');
                }
            }

            // Fallback
            $lower = strtolower($text);
            $harmful = (bool) preg_match('/\b(kill\s*(your\s*self|myself|him|her|them)|suicide|die|kys|stfu|fuck\s*you|hate\s*you|go\s*die|shoot|murder|rape|n[i1]gg|f[a4]g|retard)\b/i', $text);
            $positive = preg_match_all('/\b(great|good|happy|love|awesome|amazing|wonderful|fantastic|excellent|thanks|appreciate)\b/', $lower);
            $negative = preg_match_all('/\b(bad|hate|angry|sad|terrible|awful|worst|annoying|frustrated|disappointed)\b/', $lower);
            $tone = $harmful ? '⚠️ Harmful, 😡 Angry' : ($positive > $negative ? '😊 Positive' : ($negative > $positive ? '😟 Negative' : '😐 Neutral'));
            $msg = '🎭 Tone: ' . $tone;
            if ($harmful) {
                $msg .= ' | ⚠️ WARNING: Potentially harmful content';
            }
            $this->addFlash($harmful ? 'error' : 'success', $msg);
            $session->set('ai_composer_text', $text);
            return $this->redirectToRoute('app_community_index');
        }

        // Default: AI Improve
        $systemPrompt = 'You are a professional writing assistant. Improve the following text to make it clearer, more professional, and well-written. Fix grammar, spelling, and punctuation. Return ONLY the improved text, nothing else.';
        $result = $this->callGroqApi($systemPrompt, $text);
        if (!$result) {
            $result = $text;
            $result = preg_replace_callback('/(^|[.!?]\s+)([a-z])/', fn($m) => $m[1] . strtoupper($m[2]), $result);
            $fixes = ['/\bdont\b/i'=>"don't",'/\bcant\b/i'=>"can't",'/\bim\b/i'=>"I'm",'/\bteh\b/i'=>'the'];
            foreach ($fixes as $p => $r) { $result = (string) preg_replace($p, $r, (string) $result); }
            if ($result !== '' && !preg_match('/[.!?]$/', $result)) $result .= '.';
        }
        $this->addFlash('success', '✨ AI improved your text!');
        $session->set('ai_composer_text', $result);
        return $this->redirectToRoute('app_community_index');
    }

    #[Route('/post/{id}', name: 'app_community_show')]
    public function show(Post $post, CommentRepository $commentRepo, EntityManagerInterface $em, CommunityGroupRepository $groupRepo): Response
    {
        // Build threaded comments: top-level first, then replies grouped
        $allComments = $commentRepo->findBy(['post' => $post], ['created_at' => 'ASC']);
        $topLevel = [];
        $replies = [];
        foreach ($allComments as $c) {
            /** @var \App\Entity\Comment $c */
            if ($c->getParent()) {
                $pid = $c->getParent()->getId();
                $replies[$pid][] = $c;
            } else {
                $topLevel[] = $c;
            }
        }

        $reactions = $this->getPostReactions($em, [$post]);

        $user = $this->getUser();
        $isBookmarked = false;
        if ($user) {
            $isBookmarked = (bool) $em->getRepository(Bookmark::class)->findOneBy(['user' => $user, 'post' => $post]);
        }

        return $this->render('community/show.html.twig', array_merge([
            'post' => $post,
            'topComments' => $topLevel,
            'replies' => $replies,
            'totalComments' => count($allComments),
            'reactions' => $reactions[$post->getId()] ?? ['counts' => [], 'userReaction' => null, 'total' => 0],
            'isBookmarked' => $isBookmarked,
            'emojiMap' => self::EMOJI_MAP,
            'twemojiCdn' => self::TWEMOJI_CDN,
            'emojiPicker' => self::EMOJI_PICKER,
        ], $this->sidebarData($groupRepo, $em)));
    }

    #[Route('/post/{id}/delete', name: 'app_community_delete', methods: ['POST'])]
    public function delete(Request $request, Post $post, EntityManagerInterface $em): Response
    {
        if ($post->getAuthor() !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('You can only delete your own posts.');
        }
        if ($this->isCsrfTokenValid('delete-post-' . $post->getId(), (string) $request->request->get('_token'))) {
            $em->remove($post);
            $em->flush();
            $this->addFlash('success', 'Post deleted.');
        }
        return $this->redirectToRoute('app_community_index');
    }

    // ═══════════════════════════
    //  REACTIONS (6 types)
    // ═══════════════════════════

    #[Route('/post/{id}/react', name: 'app_community_react', methods: ['POST'])]
    public function react(Post $post, Request $request, EntityManagerInterface $em): Response
    {
        $type = $request->request->get('type', 'heart');
        $allowed = ['heart', 'thumbsup', 'laugh', 'fire', 'clap', 'wow'];
        if (!in_array($type, $allowed)) $type = 'heart';

        $user = $this->getUser();
        $existing = $em->getRepository(Reaction::class)->findOneBy(['post' => $post, 'user' => $user]);

        if ($existing) {
            if ($existing->getType() === $type) {
                // Toggle off same reaction
                $em->remove($existing);
                $post->setLikesCount(max(0, ($post->getLikesCount() ?? 1) - 1));
            } else {
                // Switch to different reaction
                $existing->setType((string) $type);
            }
        } else {
            $reaction = new Reaction();
            /** @var \App\Entity\User|null $reactUser */
            $reactUser = $user;
            $reaction->setPost($post);
            $reaction->setUser($reactUser);
            $reaction->setType((string) $type);
            $em->persist($reaction);
            $post->setLikesCount(($post->getLikesCount() ?? 0) + 1);
        }
        $em->flush();

        $from = $request->request->get('from', 'show');
        if ($from === 'index') return $this->redirectToRoute('app_community_index');
        return $this->redirectToRoute('app_community_show', ['id' => $post->getId()]);
    }

    #[Route('/post/{id}/share', name: 'app_community_share', methods: ['POST'])]
    public function share(Post $post, EntityManagerInterface $em): Response
    {
        $post->setSharesCount(($post->getSharesCount() ?? 0) + 1);
        $em->flush();
        return new \Symfony\Component\HttpFoundation\JsonResponse(['shares' => $post->getSharesCount()]);
    }

    // ═══════════════════════════
    //  BOOKMARKS (server-side)
    // ═══════════════════════════

    #[Route('/post/{id}/bookmark', name: 'app_community_bookmark', methods: ['POST'])]
    public function toggleBookmark(Post $post, Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user) return $this->redirectToRoute('app_community_index');

        $existing = $em->getRepository(Bookmark::class)->findOneBy(['user' => $user, 'post' => $post]);
        if ($existing) {
            $em->remove($existing);
            $em->flush();
            $this->addFlash('success', 'Bookmark removed.');
        } else {
            /** @var \App\Entity\User $bookmarkUser */
            $bookmarkUser = $user;
            $bookmark = new Bookmark();
            $bookmark->setUser($bookmarkUser);
            $bookmark->setPost($post);
            $em->persist($bookmark);
            $em->flush();
            $this->addFlash('success', 'Post saved!');
        }

        $from = $request->request->get('from', 'index');
        if ($from === 'show') return $this->redirectToRoute('app_community_show', ['id' => $post->getId()]);
        if ($from === 'saved') return $this->redirectToRoute('app_community_saved');
        return $this->redirectToRoute('app_community_index');
    }

    // ═══════════════════════════
    //  COMMENTS (threaded)
    // ═══════════════════════════

    #[Route('/post/{id}/comment', name: 'app_community_comment', methods: ['POST'])]
    public function addComment(Request $request, Post $post, EntityManagerInterface $em, CommentRepository $commentRepo): Response
    {
        $content = trim((string) $request->request->get('content', ''));
        if ($content !== '') {
            // Content moderation
            $check = BadWordsService::check($content);
            if ($check['hasBadWords']) {
                $this->addFlash('error', 'Your comment contains inappropriate language. Please review.');
                return $this->redirectToRoute('app_community_show', ['id' => $post->getId()]);
            }

            $comment = new Comment();
            /** @var \App\Entity\User|null $commentAuthor */
            $commentAuthor = $this->getUser();
            $comment->setPost($post);
            $comment->initAuthor($commentAuthor);
            $comment->setContent($content);
            // Threading: parent comment
            $parentId = $request->request->get('parent_id');
            if ($parentId) {
                $parent = $commentRepo->find($parentId);
                if ($parent instanceof Comment) $comment->setParent($parent);
            }

            $post->setCommentsCount(($post->getCommentsCount() ?? 0) + 1);
            $em->persist($comment);
            $em->flush();
        }
        return $this->redirectToRoute('app_community_show', ['id' => $post->getId()]);
    }

    #[Route('/comment/{id}/delete', name: 'app_community_comment_delete', methods: ['POST'])]
    public function deleteComment(Request $request, int $id, CommentRepository $commentRepo, EntityManagerInterface $em): Response
    {
        $comment = $commentRepo->find($id);
        if ($comment && $comment->getAuthor() !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('You can only delete your own comments.');
        }
        if ($comment && $this->isCsrfTokenValid('delete-comment-' . $id, (string) $request->request->get('_token'))) {
            /** @var \App\Entity\Comment $typedComment */
            $typedComment = $comment;
            $delPost = $typedComment->getPost();
            if ($delPost !== null) {
                $delPost->setCommentsCount(max(0, ($delPost->getCommentsCount() ?? 1) - 1));
            }
            $em->remove($typedComment);
            $em->flush();
            return $this->redirectToRoute('app_community_show', ['id' => $delPost?->getId()]);
        }
        return $this->redirectToRoute('app_community_index');
    }

    #[Route('/comment/{id}/edit', name: 'app_community_comment_edit', methods: ['POST'])]
    public function editComment(Request $request, int $id, CommentRepository $commentRepo, EntityManagerInterface $em): Response
    {
        $comment = $commentRepo->find($id);
        if (!$comment) {
            throw $this->createNotFoundException('Comment not found.');
        }
        if ($comment->getAuthor() !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('You can only edit your own comments.');
        }
        /** @var \App\Entity\Comment $typedComment2 */
        $typedComment2 = $comment;
        if ($this->isCsrfTokenValid('edit-comment-' . $id, (string) $request->request->get('_token'))) {
            $content = trim((string) $request->request->get('content', ''));
            if ($content !== '') {
                $check = BadWordsService::check($content);
                if ($check['hasBadWords']) {
                    $this->addFlash('error', 'Your comment contains inappropriate language. Please review.');
                    return $this->redirectToRoute('app_community_show', ['id' => (int) $typedComment2->getPost()?->getId()]);
                }
                $typedComment2->setContent($content);
                $em->flush();
                $this->addFlash('success', 'Comment updated.');
            }
        }
        return $this->redirectToRoute('app_community_show', ['id' => (int) $typedComment2->getPost()?->getId()]);
    }

    // ═══════════════════════════
    //  PEOPLE + FRIEND + FOLLOW
    // ═══════════════════════════

    #[Route('/people', name: 'app_community_people')]
    public function people(Request $request, UserRepository $userRepo, EntityManagerInterface $em, CommunityGroupRepository $groupRepo): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        if ($q !== '') {
            $users = $em->createQuery("SELECT u FROM App\\Entity\\User u WHERE LOWER(u.firstName) LIKE :q OR LOWER(u.lastName) LIKE :q ORDER BY u.id ASC")
                ->setParameter('q', '%' . strtolower($q) . '%')->getResult();
        } else {
            $users = $userRepo->findBy([], ['id' => 'ASC'], 100);
        }
        $me = $this->getUser();

        // Build relationship map
        $relationships = [];
        if ($me) {
            // Follows I sent
            $followsSent = $em->getRepository(UserFollow::class)->findBy(['follower' => $me]);
            foreach ($followsSent as $f) {
                $uid = $f->getFollowed() ? $f->getFollowed()->getId() : null;
                if (!$uid) continue;
                $status = $f->getStatus() ?? 'following';
                if ($status === 'friend_pending') {
                    $relationships[$uid]['friend'] = 'pending_sent';
                } elseif ($status === 'friend_accepted') {
                    $relationships[$uid]['friend'] = 'friends';
                }
                if (in_array($status, ['following', 'friend_accepted', 'friend_pending'])) {
                    $relationships[$uid]['follow'] = true;
                }
            }
            // Follows I received
            $followsReceived = $em->getRepository(UserFollow::class)->findBy(['followed' => $me]);
            foreach ($followsReceived as $f) {
                $uid = $f->getFollower() ? $f->getFollower()->getId() : null;
                if (!$uid) continue;
                $status = $f->getStatus() ?? 'following';
                if ($status === 'friend_pending') {
                    $relationships[$uid]['friend'] = 'pending_received';
                } elseif ($status === 'friend_accepted') {
                    $relationships[$uid]['friend'] = 'friends';
                }
            }
        }

        return $this->render('community/people.html.twig', array_merge([
            'users' => $users,
            'relationships' => $relationships,
            'tab' => 'people',
            'q' => $q,
        ], $this->sidebarData($groupRepo, $em)));
    }

    #[Route('/people/{id}/friend', name: 'app_community_add_friend', methods: ['POST'])]
    public function addFriend(int $id, UserRepository $userRepo, EntityManagerInterface $em): Response
    {
        $me = $this->getUser();
        $target = $userRepo->find($id);
        if (!$target || !$me || $me->getId() === $id) {
            return $this->redirectToRoute('app_community_people');
        }

        $existing = $em->getRepository(UserFollow::class)->findOneBy(['follower' => $me, 'followed' => $target]);
        if (!$existing) {
            /** @var \App\Entity\User $meUser */
            $meUser = $me;
            $follow = new UserFollow();
            $follow->setFollower($meUser);
            $follow->setFollowed($target);
            $follow->setStatus('friend_pending');
            $em->persist($follow);
            $em->flush();
            $this->addFlash('success', 'Friend request sent!');
        }
        return $this->redirectToRoute('app_community_people');
    }

    #[Route('/people/{id}/friend-accept', name: 'app_community_accept_friend', methods: ['POST'])]
    public function acceptFriend(int $id, UserRepository $userRepo, EntityManagerInterface $em): Response
    {
        $me = $this->getUser();
        $sender = $userRepo->find($id);
        if (!$sender || !$me) return $this->redirectToRoute('app_community_people');

        $request = $em->getRepository(UserFollow::class)->findOneBy(['follower' => $sender, 'followed' => $me, 'status' => 'friend_pending']);
        if ($request) {
            $request->setStatus('friend_accepted');
            // Create reverse follow too
            $reverse = $em->getRepository(UserFollow::class)->findOneBy(['follower' => $me, 'followed' => $sender]);
            if (!$reverse) {
                /** @var \App\Entity\User $meAccept */
                $meAccept = $me;
                $reverse = new UserFollow();
                $reverse->setFollower($meAccept);
                $reverse->setFollowed($sender);
                $em->persist($reverse);
            }
            $reverse->setStatus('friend_accepted');
            $em->flush();
            $this->addFlash('success', 'Friend request accepted!');
        }
        return $this->redirectToRoute('app_community_people');
    }

    #[Route('/people/{id}/friend-decline', name: 'app_community_decline_friend', methods: ['POST'])]
    public function declineFriend(int $id, UserRepository $userRepo, EntityManagerInterface $em): Response
    {
        $me = $this->getUser();
        $sender = $userRepo->find($id);
        if (!$sender || !$me) return $this->redirectToRoute('app_community_people');

        $request = $em->getRepository(UserFollow::class)->findOneBy(['follower' => $sender, 'followed' => $me, 'status' => 'friend_pending']);
        if ($request) {
            $em->remove($request);
            $em->flush();
        }
        return $this->redirectToRoute('app_community_people');
    }

    #[Route('/people/{id}/follow', name: 'app_community_follow', methods: ['POST'])]
    public function follow(int $id, UserRepository $userRepo, EntityManagerInterface $em): Response
    {
        $me = $this->getUser();
        $target = $userRepo->find($id);
        if (!$target || !$me || $me->getId() === $id) {
            return $this->redirectToRoute('app_community_people');
        }

        $existing = $em->getRepository(UserFollow::class)->findOneBy(['follower' => $me, 'followed' => $target]);
        if ($existing) {
            // Unfollow
            if ($existing->getStatus() === 'following') {
                $em->remove($existing);
                $em->flush();
            }
        } else {
            /** @var \App\Entity\User $meFollow */
            $meFollow = $me;
            $follow = new UserFollow();
            $follow->setFollower($meFollow);
            $follow->setFollowed($target);
            $follow->setStatus('following');
            $em->persist($follow);
            $em->flush();
        }
        return $this->redirectToRoute('app_community_people');
    }

    // ═══════════════════════════
    //  GROUPS
    // ═══════════════════════════

    #[Route('/groups', name: 'app_community_groups')]
    public function groups(CommunityGroupRepository $groupRepo, EntityManagerInterface $em): Response
    {
        $groups = $groupRepo->findBy([], ['created_at' => 'DESC'], 100);
        $me = $this->getUser();
        $membership = [];
        if ($me) {
            $members = $em->getRepository(GroupMember::class)->findBy(['user' => $me]);
            foreach ($members as $m) {
                if ($m->getGroup()) $membership[$m->getGroup()->getId()] = $m->getRole() ?? 'MEMBER';
            }
        }

        return $this->render('community/groups.html.twig', array_merge([
            'groups' => $groups,
            'membership' => $membership,
            'tab' => 'groups',
        ], $this->sidebarData($groupRepo, $em)));
    }

    #[Route('/groups/create', name: 'app_community_create_group', methods: ['POST'])]
    public function createGroup(Request $request, EntityManagerInterface $em): Response
    {
        $name = trim((string) $request->request->get('name', ''));
        if ($name !== '') {
            $group = new CommunityGroup();
            /** @var \App\Entity\User|null $groupCreator */
            $groupCreator = $this->getUser();
            $group->setName($name);
            $group->setDescription(trim((string) $request->request->get('description', '')));
            $group->setPrivacy((string) $request->request->get('privacy', 'PUBLIC'));
            $group->initCreator($groupCreator);
            $group->setMember_count(1);
            $em->persist($group);

            // Add creator as ADMIN member
            $member = new GroupMember();
            $member->setGroup($group);
            $member->setUser($groupCreator);
            $member->setRole('ADMIN');
            $member->initJoined_at(new \DateTime());
            $em->persist($member);

            $em->flush();
            $this->addFlash('success', 'Group "' . $name . '" created!');
        }
        return $this->redirectToRoute('app_community_groups');
    }

    #[Route('/groups/{id}', name: 'app_community_group_detail')]
    public function groupDetail(CommunityGroup $group, PostRepository $postRepo, EntityManagerInterface $em, CommunityGroupRepository $groupRepo): Response
    {
        $posts = $postRepo->findBy(['group' => $group], ['created_at' => 'DESC']);
        $me = $this->getUser();
        $myRole = null;
        if ($me) {
            $membership = $em->getRepository(GroupMember::class)->findOneBy(['group' => $group, 'user' => $me]);
            if ($membership) $myRole = $membership->getRole() ?? 'MEMBER';
        }

        $bookmarkedIds = [];
        if ($me) {
            $bookmarks = $em->getRepository(Bookmark::class)->findBy(['user' => $me]);
            foreach ($bookmarks as $b) {
                if ($b->getPost()) $bookmarkedIds[] = $b->getPost()->getId();
            }
        }

        return $this->render('community/group_detail.html.twig', array_merge([
            'group' => $group,
            'posts' => $posts,
            'reactions' => $this->getPostReactions($em, $posts),
            'bookmarkedIds' => $bookmarkedIds,
            'emojiMap' => self::EMOJI_MAP,
            'twemojiCdn' => self::TWEMOJI_CDN,
            'myRole' => $myRole,
            'tab' => 'groups',
        ], $this->sidebarData($groupRepo, $em)));
    }

    #[Route('/groups/{id}/join', name: 'app_community_join_group', methods: ['POST'])]
    public function joinGroup(CommunityGroup $group, EntityManagerInterface $em): Response
    {
        $me = $this->getUser();
        /** @var \App\Entity\User|null $meJoin2 */
        $meJoin2 = $me;
        if ($meJoin2) {
            $existing = $em->getRepository(GroupMember::class)->findOneBy(['group' => $group, 'user' => $meJoin2]);
            if (!$existing) {
                $member = new GroupMember();
                $member->setGroup($group);
                $member->setUser($meJoin2);
                $member->setRole('MEMBER');
                $member->initJoined_at(new \DateTime());
                $em->persist($member);
                $group->setMember_count(($group->getMember_count() ?? 0) + 1);
                $em->flush();
                $this->addFlash('success', 'Joined "' . $group->getName() . '"!');
            }
        }
        return $this->redirectToRoute('app_community_groups');
    }

    #[Route('/groups/{id}/leave', name: 'app_community_leave_group', methods: ['POST'])]
    public function leaveGroup(CommunityGroup $group, EntityManagerInterface $em): Response
    {
        $me = $this->getUser();
        /** @var \App\Entity\User|null $meLeave */
        $meLeave = $me;
        if ($meLeave) {
            $member = $em->getRepository(GroupMember::class)->findOneBy(['group' => $group, 'user' => $meLeave]);
            if ($member && ($member->getRole() ?? 'MEMBER') !== 'ADMIN') {
                $em->remove($member);
                $group->setMember_count(max(0, ($group->getMember_count() ?? 1) - 1));
                $em->flush();
                $this->addFlash('success', 'Left group.');
            }
        }
        return $this->redirectToRoute('app_community_groups');
    }

    // ═══════════════════════════
    //  QUOTES + WIKIPEDIA
    // ═══════════════════════════

    #[Route('/quotes', name: 'app_community_quotes')]
    public function quotes(CommunityGroupRepository $groupRepo, EntityManagerInterface $em): Response
    {
        return $this->render('community/quotes.html.twig', array_merge([
            'tab' => 'quotes',
        ], $this->sidebarData($groupRepo, $em)));
    }

    #[Route('/wiki', name: 'app_community_wiki')]
    public function wiki(Request $request, CommunityGroupRepository $groupRepo, EntityManagerInterface $em): Response
    {
        $query = trim((string) $request->query->get('q', ''));
        $article = null;
        if ($query !== '') {
            $article = $this->fetchWikipediaArticle($query);
        }

        return $this->render('community/wiki.html.twig', array_merge([
            'tab' => 'wiki',
            'query' => $query,
            'article' => $article,
        ], $this->sidebarData($groupRepo, $em)));
    }

    /** @return array<string, mixed>|null */
    private function fetchWikipediaArticle(string $query): ?array
    {
        $url = 'https://en.wikipedia.org/w/api.php?' . http_build_query([
            'action' => 'query',
            'titles' => $query,
            'prop' => 'extracts|pageimages|description',
            'exintro' => true,
            'explaintext' => true,
            'pithumbsize' => 600,
            'redirects' => 1,
            'format' => 'json',
        ]);

        $ctx = stream_context_create(['http' => ['timeout' => 5, 'user_agent' => 'SynergyGig/1.0']]);
        $json = @file_get_contents($url, false, $ctx);
        if (!$json) return null;

        $data = json_decode($json, true);
        $pages = $data['query']['pages'] ?? [];
        $page = reset($pages);
        if (!$page || isset($page['missing'])) return null;

        return [
            'title' => $page['title'] ?? $query,
            'description' => $page['description'] ?? '',
            'extract' => $page['extract'] ?? '',
            'thumbnail' => $page['thumbnail']['source'] ?? null,
            'url' => 'https://en.wikipedia.org/wiki/' . urlencode(str_replace(' ', '_', $page['title'] ?? $query)),
        ];
    }
}
