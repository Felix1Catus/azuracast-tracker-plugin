<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Migration;

use App\Entity\Migration\AbstractMigration; // core base: not transactional (MySQL DDL auto-commits)
use Doctrine\DBAL\Schema\Schema;

final class Version20261013210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Branch Tracker: each branch PIN, encrypted, so managers can see it on the board';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE branch_branch ADD pin_encrypted VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE branch_branch DROP pin_encrypted');
    }
}
