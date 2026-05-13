<?php

namespace App\Tests\Service;

use App\Entity\Post;
use App\Entity\CommunityGroup;
use App\Service\CommunityManager;
use PHPUnit\Framework\TestCase;

class CommunityManagerTest extends TestCase
{
    // ─── Post Tests ───────────────────────────────────────────────

    public function testValidPost(): void
    {
        $post = new Post();
        $post->setContent('Bienvenue dans SynergyGig ! Partagez vos expériences.');

        $manager = new CommunityManager();
        $this->assertTrue($manager->validatePost($post));
    }

    public function testPostWithEmptyContent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Le contenu du post est obligatoire');

        $post = new Post();
        $post->setContent('');

        $manager = new CommunityManager();
        $manager->validatePost($post);
    }

    public function testPostWithNullContent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Le contenu du post est obligatoire');

        $post = new Post();
        $post->setContent(null);

        $manager = new CommunityManager();
        $manager->validatePost($post);
    }

    public function testPostWithTooShortContent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Le contenu du post doit contenir au moins 3 caractères');

        $post = new Post();
        $post->setContent('hi');

        $manager = new CommunityManager();
        $manager->validatePost($post);
    }

    public function testPostWithExactlyThreeCharsIsValid(): void
    {
        $post = new Post();
        $post->setContent('OK!');

        $manager = new CommunityManager();
        $this->assertTrue($manager->validatePost($post));
    }

    // ─── Community Group Tests ────────────────────────────────────

    public function testValidCommunityGroup(): void
    {
        $group = new CommunityGroup();
        $group->setName('Équipe Développement Backend');
        $group->setDescription('Discussions techniques autour du backend Symfony');

        $manager = new CommunityManager();
        $this->assertTrue($manager->validateGroup($group));
    }

    public function testGroupWithEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Le nom du groupe est obligatoire');

        $group = new CommunityGroup();
        $group->setName('   ');

        $manager = new CommunityManager();
        $manager->validateGroup($group);
    }

    public function testGroupWithTooLongName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Le nom du groupe ne peut pas dépasser 100 caractères');

        $group = new CommunityGroup();
        $group->setName(str_repeat('A', 101));

        $manager = new CommunityManager();
        $manager->validateGroup($group);
    }
}
