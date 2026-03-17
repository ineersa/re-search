<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add ResearchRun, ResearchStep, ResearchMessage, ResearchSource entities
 * for SQLite-backed research history and audit data.
 */
final class Version20260317215012 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add research entities (ResearchRun, ResearchStep, ResearchMessage, ResearchSource) for history and audit';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE research_message (id BLOB NOT NULL, role VARCHAR(16) NOT NULL, content CLOB NOT NULL, sequence INTEGER NOT NULL, created_at DATETIME NOT NULL, run_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_2F13806584E3FEC4 FOREIGN KEY (run_id) REFERENCES research_run (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_2F13806584E3FEC4 ON research_message (run_id)');
        $this->addSql('CREATE TABLE research_run (id BLOB NOT NULL, "query" CLOB NOT NULL, query_hash VARCHAR(64) NOT NULL, status VARCHAR(32) NOT NULL, final_answer_markdown CLOB DEFAULT NULL, token_budget_hard_cap INTEGER NOT NULL, token_budget_used INTEGER NOT NULL, token_budget_estimated BOOLEAN NOT NULL, loop_detected BOOLEAN NOT NULL, answer_only_triggered BOOLEAN NOT NULL, failure_reason CLOB DEFAULT NULL, mercure_topic VARCHAR(255) NOT NULL, client_key VARCHAR(128) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, completed_at DATETIME DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE research_source (id BLOB NOT NULL, url VARCHAR(2048) NOT NULL, title VARCHAR(512) DEFAULT NULL, snippet CLOB DEFAULT NULL, created_at DATETIME NOT NULL, step_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_F86BDF7073B21E9C FOREIGN KEY (step_id) REFERENCES research_step (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_F86BDF7073B21E9C ON research_source (step_id)');
        $this->addSql('CREATE TABLE research_step (id BLOB NOT NULL, sequence INTEGER NOT NULL, type VARCHAR(32) NOT NULL, turn_number INTEGER NOT NULL, tool_name VARCHAR(64) DEFAULT NULL, tool_arguments_json CLOB DEFAULT NULL, tool_signature VARCHAR(255) DEFAULT NULL, summary CLOB NOT NULL, payload_json CLOB DEFAULT NULL, prompt_tokens INTEGER DEFAULT NULL, completion_tokens INTEGER DEFAULT NULL, total_tokens INTEGER DEFAULT NULL, estimated_tokens BOOLEAN NOT NULL, created_at DATETIME NOT NULL, run_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_1609CEEC84E3FEC4 FOREIGN KEY (run_id) REFERENCES research_run (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_1609CEEC84E3FEC4 ON research_step (run_id)');
        $this->addSql('CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, body CLOB NOT NULL, headers CLOB NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE research_message');
        $this->addSql('DROP TABLE research_run');
        $this->addSql('DROP TABLE research_source');
        $this->addSql('DROP TABLE research_step');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
