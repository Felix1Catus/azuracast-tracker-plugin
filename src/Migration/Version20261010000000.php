<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Migration;

use App\Entity\Migration\AbstractMigration; // core base: not transactional (MySQL DDL auto-commits)
use Doctrine\DBAL\Schema\Schema;

final class Version20261010000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Branch Tracker: per-station settings (public page PIN login)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE branch_station_settings (
                station_id INT NOT NULL,
                public_page_login TINYINT(1) NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY(station_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB'
        );
        $this->addSql(
            'ALTER TABLE branch_station_settings ADD CONSTRAINT FK_BSS_STATION
                FOREIGN KEY (station_id) REFERENCES station (id) ON DELETE CASCADE'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE branch_station_settings');
    }
}
