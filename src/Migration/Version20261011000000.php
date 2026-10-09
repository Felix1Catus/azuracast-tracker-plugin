<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Migration;

use App\Entity\Migration\AbstractMigration; // core base: not transactional (MySQL DDL auto-commits)
use Doctrine\DBAL\Schema\Schema;

final class Version20261011000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Branch Tracker: public read-only board share key';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE branch_station_settings ADD public_board_key VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE branch_station_settings DROP public_board_key');
    }
}
