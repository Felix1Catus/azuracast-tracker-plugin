<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Migration;

use App\Entity\Migration\AbstractMigration; // core base: not transactional (MySQL DDL auto-commits)
use Doctrine\DBAL\Schema\Schema;

final class Version20261013200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Branch Tracker: remember which "what\'s new" notice each branch has dismissed';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE branch_branch ADD notice_seen VARCHAR(40) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE branch_branch DROP notice_seen');
    }
}
