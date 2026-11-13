<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261113232950 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add original_arrival_time and original_departure_time to trip_stop for schedule tolerance calculation';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE trip_stop ADD original_arrival_time DATETIME DEFAULT NULL, ADD original_departure_time DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE trip_stop DROP original_arrival_time, DROP original_departure_time');
    }
}
