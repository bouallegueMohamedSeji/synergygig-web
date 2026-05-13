<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260505180155 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE chat_rooms ADD CONSTRAINT FK_7DDCF70DB03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id)');
        $this->addSql('CREATE INDEX IDX_7DDCF70DB03A8386 ON chat_rooms (created_by_id)');
        $this->addSql('ALTER TABLE comments DROP FOREIGN KEY FK_5F9E962A4B89032C');
        $this->addSql('ALTER TABLE comments DROP FOREIGN KEY FK_5F9E962A727ACA70');
        $this->addSql('ALTER TABLE comments CHANGE post_id post_id INT NOT NULL, CHANGE author_id author_id INT NOT NULL');
        $this->addSql('ALTER TABLE comments ADD CONSTRAINT FK_5F9E962A4B89032C FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE comments ADD CONSTRAINT FK_5F9E962A727ACA70 FOREIGN KEY (parent_id) REFERENCES comments (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE community_groups CHANGE creator_id creator_id INT NOT NULL');
        $this->addSql('ALTER TABLE contracts DROP FOREIGN KEY FK_950A97353C674EE');
        $this->addSql('ALTER TABLE contracts CHANGE offer_id offer_id INT NOT NULL, CHANGE amount amount NUMERIC(10, 2) DEFAULT NULL, CHANGE counter_amount counter_amount NUMERIC(10, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE contracts ADD CONSTRAINT FK_950A97353C674EE FOREIGN KEY (offer_id) REFERENCES offers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE departments CHANGE allocated_budget allocated_budget NUMERIC(10, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE group_members DROP FOREIGN KEY FK_C3A086F3FE54D947');
        $this->addSql('ALTER TABLE group_members CHANGE group_id group_id INT NOT NULL');
        $this->addSql('ALTER TABLE group_members ADD CONSTRAINT FK_C3A086F3FE54D947 FOREIGN KEY (group_id) REFERENCES community_groups (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE interviews DROP FOREIGN KEY FK_3A7526823E030ACD');
        $this->addSql('ALTER TABLE interviews DROP FOREIGN KEY FK_3A75268253C674EE');
        $this->addSql('ALTER TABLE interviews CHANGE offer_id offer_id INT NOT NULL');
        $this->addSql('ALTER TABLE interviews ADD CONSTRAINT FK_3A7526823E030ACD FOREIGN KEY (application_id) REFERENCES job_applications (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE interviews ADD CONSTRAINT FK_3A75268253C674EE FOREIGN KEY (offer_id) REFERENCES offers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE job_applications DROP FOREIGN KEY FK_F8AAF3DF53C674EE');
        $this->addSql('ALTER TABLE job_applications CHANGE offer_id offer_id INT NOT NULL');
        $this->addSql('ALTER TABLE job_applications ADD CONSTRAINT FK_F8AAF3DF53C674EE FOREIGN KEY (offer_id) REFERENCES offers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE messages DROP FOREIGN KEY FK_DB021E9654177093');
        $this->addSql('ALTER TABLE messages CHANGE room_id room_id INT NOT NULL');
        $this->addSql('ALTER TABLE messages ADD CONSTRAINT FK_DB021E9654177093 FOREIGN KEY (room_id) REFERENCES chat_rooms (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE offers CHANGE amount amount NUMERIC(10, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE posts DROP FOREIGN KEY FK_885DBAFAFE54D947');
        $this->addSql('ALTER TABLE posts CHANGE author_id author_id INT NOT NULL');
        $this->addSql('ALTER TABLE posts ADD CONSTRAINT FK_885DBAFAFE54D947 FOREIGN KEY (group_id) REFERENCES community_groups (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_members DROP FOREIGN KEY FK_D3BEDE9A166D1F9C');
        $this->addSql('ALTER TABLE project_members CHANGE project_id project_id INT NOT NULL');
        $this->addSql('ALTER TABLE project_members ADD CONSTRAINT FK_D3BEDE9A166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reactions DROP FOREIGN KEY FK_38737FB34B89032C');
        $this->addSql('ALTER TABLE reactions CHANGE post_id post_id INT NOT NULL');
        $this->addSql('ALTER TABLE reactions ADD CONSTRAINT FK_38737FB34B89032C FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE tasks DROP FOREIGN KEY FK_5058659789EEAF91');
        $this->addSql('ALTER TABLE tasks DROP FOREIGN KEY FK_50586597166D1F9C');
        $this->addSql('DROP INDEX IDX_5058659789EEAF91 ON tasks');
        $this->addSql('ALTER TABLE tasks CHANGE project_id project_id INT NOT NULL, CHANGE assigned_to assigned_to_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tasks ADD CONSTRAINT FK_50586597F4BD7827 FOREIGN KEY (assigned_to_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE tasks ADD CONSTRAINT FK_50586597166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_50586597F4BD7827 ON tasks (assigned_to_id)');
        $this->addSql('ALTER TABLE training_certificates DROP FOREIGN KEY FK_9535D752591CC992');
        $this->addSql('ALTER TABLE training_certificates DROP FOREIGN KEY FK_9535D7528F7DB25B');
        $this->addSql('ALTER TABLE training_certificates CHANGE enrollment_id enrollment_id INT NOT NULL, CHANGE course_id course_id INT NOT NULL');
        $this->addSql('ALTER TABLE training_certificates ADD CONSTRAINT FK_9535D752591CC992 FOREIGN KEY (course_id) REFERENCES training_courses (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE training_certificates ADD CONSTRAINT FK_9535D7528F7DB25B FOREIGN KEY (enrollment_id) REFERENCES training_enrollments (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE training_courses DROP FOREIGN KEY FK_749BCA42DE12AB56');
        $this->addSql('DROP INDEX IDX_749BCA42DE12AB56 ON training_courses');
        $this->addSql('ALTER TABLE training_courses ADD created_by_id INT DEFAULT NULL');
        $this->addSql('UPDATE training_courses SET created_by_id = created_by');
        $this->addSql('UPDATE training_courses SET created_by_id = 1 WHERE created_by_id IS NULL');
        $this->addSql('ALTER TABLE training_courses CHANGE created_by_id created_by_id INT NOT NULL');
        $this->addSql('ALTER TABLE training_courses DROP created_by');
        $this->addSql('ALTER TABLE training_courses ADD CONSTRAINT FK_749BCA42B03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id)');
        $this->addSql('CREATE INDEX IDX_749BCA42B03A8386 ON training_courses (created_by_id)');
        $this->addSql('ALTER TABLE training_enrollments DROP FOREIGN KEY FK_68B3BBE5591CC992');
        $this->addSql('ALTER TABLE training_enrollments CHANGE course_id course_id INT NOT NULL');
        $this->addSql('ALTER TABLE training_enrollments ADD CONSTRAINT FK_68B3BBE5591CC992 FOREIGN KEY (course_id) REFERENCES training_courses (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user CHANGE monthly_salary monthly_salary NUMERIC(10, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE chat_rooms DROP FOREIGN KEY FK_7DDCF70DB03A8386');
        $this->addSql('DROP INDEX IDX_7DDCF70DB03A8386 ON chat_rooms');
        $this->addSql('ALTER TABLE comments DROP FOREIGN KEY FK_5F9E962A4B89032C');
        $this->addSql('ALTER TABLE comments DROP FOREIGN KEY FK_5F9E962A727ACA70');
        $this->addSql('ALTER TABLE comments CHANGE post_id post_id INT DEFAULT NULL, CHANGE author_id author_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE comments ADD CONSTRAINT FK_5F9E962A4B89032C FOREIGN KEY (post_id) REFERENCES posts (id)');
        $this->addSql('ALTER TABLE comments ADD CONSTRAINT FK_5F9E962A727ACA70 FOREIGN KEY (parent_id) REFERENCES comments (id)');
        $this->addSql('ALTER TABLE community_groups CHANGE creator_id creator_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE contracts DROP FOREIGN KEY FK_950A97353C674EE');
        $this->addSql('ALTER TABLE contracts CHANGE offer_id offer_id INT DEFAULT NULL, CHANGE amount amount DOUBLE PRECISION DEFAULT NULL, CHANGE counter_amount counter_amount DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE contracts ADD CONSTRAINT FK_950A97353C674EE FOREIGN KEY (offer_id) REFERENCES offers (id)');
        $this->addSql('ALTER TABLE departments CHANGE allocated_budget allocated_budget DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE group_members DROP FOREIGN KEY FK_C3A086F3FE54D947');
        $this->addSql('ALTER TABLE group_members CHANGE group_id group_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE group_members ADD CONSTRAINT FK_C3A086F3FE54D947 FOREIGN KEY (group_id) REFERENCES community_groups (id)');
        $this->addSql('ALTER TABLE interviews DROP FOREIGN KEY FK_3A7526823E030ACD');
        $this->addSql('ALTER TABLE interviews DROP FOREIGN KEY FK_3A75268253C674EE');
        $this->addSql('ALTER TABLE interviews CHANGE offer_id offer_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE interviews ADD CONSTRAINT FK_3A7526823E030ACD FOREIGN KEY (application_id) REFERENCES job_applications (id)');
        $this->addSql('ALTER TABLE interviews ADD CONSTRAINT FK_3A75268253C674EE FOREIGN KEY (offer_id) REFERENCES offers (id)');
        $this->addSql('ALTER TABLE job_applications DROP FOREIGN KEY FK_F8AAF3DF53C674EE');
        $this->addSql('ALTER TABLE job_applications CHANGE offer_id offer_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE job_applications ADD CONSTRAINT FK_F8AAF3DF53C674EE FOREIGN KEY (offer_id) REFERENCES offers (id)');
        $this->addSql('ALTER TABLE messages DROP FOREIGN KEY FK_DB021E9654177093');
        $this->addSql('ALTER TABLE messages CHANGE room_id room_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE messages ADD CONSTRAINT FK_DB021E9654177093 FOREIGN KEY (room_id) REFERENCES chat_rooms (id)');
        $this->addSql('ALTER TABLE offers CHANGE amount amount DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE posts DROP FOREIGN KEY FK_885DBAFAFE54D947');
        $this->addSql('ALTER TABLE posts CHANGE author_id author_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE posts ADD CONSTRAINT FK_885DBAFAFE54D947 FOREIGN KEY (group_id) REFERENCES community_groups (id)');
        $this->addSql('ALTER TABLE project_members DROP FOREIGN KEY FK_D3BEDE9A166D1F9C');
        $this->addSql('ALTER TABLE project_members CHANGE project_id project_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE project_members ADD CONSTRAINT FK_D3BEDE9A166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id)');
        $this->addSql('ALTER TABLE reactions DROP FOREIGN KEY FK_38737FB34B89032C');
        $this->addSql('ALTER TABLE reactions CHANGE post_id post_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE reactions ADD CONSTRAINT FK_38737FB34B89032C FOREIGN KEY (post_id) REFERENCES posts (id)');
        $this->addSql('ALTER TABLE tasks DROP FOREIGN KEY FK_50586597F4BD7827');
        $this->addSql('ALTER TABLE tasks DROP FOREIGN KEY FK_50586597166D1F9C');
        $this->addSql('DROP INDEX IDX_50586597F4BD7827 ON tasks');
        $this->addSql('ALTER TABLE tasks CHANGE project_id project_id INT DEFAULT NULL, CHANGE assigned_to_id assigned_to INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tasks ADD CONSTRAINT FK_5058659789EEAF91 FOREIGN KEY (assigned_to) REFERENCES user (id)');
        $this->addSql('ALTER TABLE tasks ADD CONSTRAINT FK_50586597166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id)');
        $this->addSql('CREATE INDEX IDX_5058659789EEAF91 ON tasks (assigned_to)');
        $this->addSql('ALTER TABLE training_certificates DROP FOREIGN KEY FK_9535D7528F7DB25B');
        $this->addSql('ALTER TABLE training_certificates DROP FOREIGN KEY FK_9535D752591CC992');
        $this->addSql('ALTER TABLE training_certificates CHANGE enrollment_id enrollment_id INT DEFAULT NULL, CHANGE course_id course_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE training_certificates ADD CONSTRAINT FK_9535D7528F7DB25B FOREIGN KEY (enrollment_id) REFERENCES training_enrollments (id)');
        $this->addSql('ALTER TABLE training_certificates ADD CONSTRAINT FK_9535D752591CC992 FOREIGN KEY (course_id) REFERENCES training_courses (id)');
        $this->addSql('ALTER TABLE training_courses DROP FOREIGN KEY FK_749BCA42B03A8386');
        $this->addSql('DROP INDEX IDX_749BCA42B03A8386 ON training_courses');
        $this->addSql('ALTER TABLE training_courses ADD created_by INT DEFAULT NULL, DROP created_by_id');
        $this->addSql('ALTER TABLE training_courses ADD CONSTRAINT FK_749BCA42DE12AB56 FOREIGN KEY (created_by) REFERENCES user (id)');
        $this->addSql('CREATE INDEX IDX_749BCA42DE12AB56 ON training_courses (created_by)');
        $this->addSql('ALTER TABLE training_enrollments DROP FOREIGN KEY FK_68B3BBE5591CC992');
        $this->addSql('ALTER TABLE training_enrollments CHANGE course_id course_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE training_enrollments ADD CONSTRAINT FK_68B3BBE5591CC992 FOREIGN KEY (course_id) REFERENCES training_courses (id)');
        $this->addSql('ALTER TABLE user CHANGE monthly_salary monthly_salary DOUBLE PRECISION DEFAULT NULL');
    }
}
