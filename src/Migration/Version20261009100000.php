<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Migration;

use Doctrine\DBAL\Schema\Schema;
use App\Entity\Migration\AbstractMigration; // core base: not transactional (MySQL DDL auto-commits)

final class Version20261009100000 extends AbstractMigration
{
    private const string TABLE_OPTS = 'DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB';

    public function getDescription(): string
    {
        return 'Branch Tracker: branches, sessions, session-listener links (drops phase 0 probe table)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS branch_spike_probe');

        $this->addSql(
            'CREATE TABLE branch_branch (
                id INT AUTO_INCREMENT NOT NULL,
                station_id INT NOT NULL,
                code VARCHAR(50) NOT NULL,
                name VARCHAR(100) NOT NULL,
                pin_hmac VARCHAR(64) NOT NULL,
                is_enabled TINYINT(1) NOT NULL,
                failed_attempts INT NOT NULL,
                locked_until DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_branch_station_code (station_id, code),
                UNIQUE INDEX uniq_branch_station_pin (station_id, pin_hmac),
                INDEX IDX_4EE76E4A21BDB235 (station_id),
                PRIMARY KEY(id)
            ) ' . self::TABLE_OPTS
        );
        $this->addSql(
            'ALTER TABLE branch_branch ADD CONSTRAINT FK_BRANCH_STATION
                FOREIGN KEY (station_id) REFERENCES station (id) ON DELETE CASCADE'
        );

        $this->addSql(
            'CREATE TABLE branch_session (
                id INT AUTO_INCREMENT NOT NULL,
                branch_id INT NOT NULL,
                station_id INT NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                started_at DATETIME(6) NOT NULL,
                last_seen_at DATETIME(6) NOT NULL,
                ended_at DATETIME(6) DEFAULT NULL,
                end_reason VARCHAR(20) DEFAULT NULL,
                ip VARCHAR(45) NOT NULL,
                user_agent VARCHAR(255) NOT NULL,
                volume SMALLINT DEFAULT NULL,
                client_state VARCHAR(20) DEFAULT NULL,
                UNIQUE INDEX uniq_branch_session_token (token_hash),
                INDEX idx_branch_session_open (branch_id, ended_at),
                INDEX idx_branch_session_station_time (station_id, started_at),
                PRIMARY KEY(id)
            ) ' . self::TABLE_OPTS
        );
        $this->addSql(
            'ALTER TABLE branch_session
                ADD CONSTRAINT FK_BSESSION_BRANCH FOREIGN KEY (branch_id) REFERENCES branch_branch (id) ON DELETE CASCADE,
                ADD CONSTRAINT FK_BSESSION_STATION FOREIGN KEY (station_id) REFERENCES station (id) ON DELETE CASCADE'
        );

        $this->addSql(
            'CREATE TABLE branch_session_listener (
                id INT AUTO_INCREMENT NOT NULL,
                session_id INT NOT NULL,
                listener_uid INT NOT NULL,
                auth_at DATETIME(6) NOT NULL,
                listener_id INT DEFAULT NULL,
                INDEX idx_bsl_uid_auth (listener_uid, auth_at),
                INDEX IDX_D13E2A87613FECDF (session_id),
                INDEX IDX_D13E2A871F1B7BB3 (listener_id),
                PRIMARY KEY(id)
            ) ' . self::TABLE_OPTS
        );
        $this->addSql(
            'ALTER TABLE branch_session_listener
                ADD CONSTRAINT FK_BSL_SESSION FOREIGN KEY (session_id) REFERENCES branch_session (id) ON DELETE CASCADE,
                ADD CONSTRAINT FK_BSL_LISTENER FOREIGN KEY (listener_id) REFERENCES listener (id) ON DELETE SET NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE branch_session_listener');
        $this->addSql('DROP TABLE branch_session');
        $this->addSql('DROP TABLE branch_branch');
    }
}
