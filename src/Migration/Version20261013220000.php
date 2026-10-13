<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Migration;

use App\Entity\Migration\AbstractMigration; // core base: not transactional (MySQL DDL auto-commits)
use Doctrine\DBAL\Schema\Schema;

final class Version20261013220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Branch Tracker: the company each branch belongs to';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE branch_branch ADD company VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE branch_branch DROP company');
    }
}
