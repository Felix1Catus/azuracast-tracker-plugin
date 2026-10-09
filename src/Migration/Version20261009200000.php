<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Migration;

use Doctrine\DBAL\Schema\Schema;
use App\Entity\Migration\AbstractMigration; // core base: not transactional (MySQL DDL auto-commits)

final class Version20261009200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Branch Tracker: store the IP / user agent Icecast reports per connection (for listener matching)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE branch_session_listener
                ADD listener_ip VARCHAR(45) DEFAULT NULL,
                ADD listener_agent VARCHAR(255) DEFAULT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE branch_session_listener DROP listener_ip, DROP listener_agent');
    }
}
