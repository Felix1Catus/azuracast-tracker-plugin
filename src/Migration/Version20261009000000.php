<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Migration;

use Doctrine\DBAL\Schema\Schema;
use App\Entity\Migration\AbstractMigration; // core base: not transactional (MySQL DDL auto-commits)

final class Version20261009000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Branch Tracker: phase 0 spike probe table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE branch_spike_probe (
                id INT AUTO_INCREMENT NOT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                kind VARCHAR(50) NOT NULL,
                payload LONGTEXT NOT NULL,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE branch_spike_probe');
    }
}
