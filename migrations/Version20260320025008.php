<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260320025008 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE research_operation (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, type VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL, turn_number INTEGER NOT NULL, position INTEGER NOT NULL, idempotency_key VARCHAR(255) NOT NULL, request_payload_json CLOB NOT NULL, result_payload_json CLOB DEFAULT NULL, error_message CLOB DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, started_at DATETIME DEFAULT NULL, completed_at DATETIME DEFAULT NULL, run_id INTEGER NOT NULL, CONSTRAINT FK_62CD769484E3FEC4 FOREIGN KEY (run_id) REFERENCES research_run (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_62CD769484E3FEC4 ON research_operation (run_id)');
        $this->addSql('CREATE INDEX idx_research_operation_run_status_type_turn ON research_operation (run_id, status, type, turn_number)');
        $this->addSql('CREATE UNIQUE INDEX uniq_research_operation_idempotency_key ON research_operation (idempotency_key)');
        $this->addSql("ALTER TABLE research_run ADD COLUMN phase VARCHAR(32) DEFAULT 'queued' NOT NULL");
        $this->addSql('ALTER TABLE research_run ADD COLUMN cancel_requested_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE research_run ADD COLUMN orchestration_version INTEGER DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE research_run ADD COLUMN orchestrator_state_json CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE research_operation');
        $this->addSql('CREATE TEMPORARY TABLE __temp__research_run AS SELECT id, run_uuid, "query", query_hash, status, final_answer_markdown, token_budget_hard_cap, token_budget_used, token_budget_estimated, loop_detected, answer_only_triggered, failure_reason, mercure_topic, client_key, created_at, updated_at, completed_at FROM research_run');
        $this->addSql('DROP TABLE research_run');
        $this->addSql('CREATE TABLE research_run (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, run_uuid VARCHAR(36) NOT NULL, "query" CLOB NOT NULL, query_hash VARCHAR(64) NOT NULL, status VARCHAR(32) NOT NULL, final_answer_markdown CLOB DEFAULT NULL, token_budget_hard_cap INTEGER NOT NULL, token_budget_used INTEGER NOT NULL, token_budget_estimated BOOLEAN NOT NULL, loop_detected BOOLEAN NOT NULL, answer_only_triggered BOOLEAN NOT NULL, failure_reason CLOB DEFAULT NULL, mercure_topic VARCHAR(255) NOT NULL, client_key VARCHAR(128) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, completed_at DATETIME DEFAULT NULL)');
        $this->addSql('INSERT INTO research_run (id, run_uuid, "query", query_hash, status, final_answer_markdown, token_budget_hard_cap, token_budget_used, token_budget_estimated, loop_detected, answer_only_triggered, failure_reason, mercure_topic, client_key, created_at, updated_at, completed_at) SELECT id, run_uuid, "query", query_hash, status, final_answer_markdown, token_budget_hard_cap, token_budget_used, token_budget_estimated, loop_detected, answer_only_triggered, failure_reason, mercure_topic, client_key, created_at, updated_at, completed_at FROM __temp__research_run');
        $this->addSql('DROP TABLE __temp__research_run');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E5605B774D810F82 ON research_run (run_uuid)');
    }
}
