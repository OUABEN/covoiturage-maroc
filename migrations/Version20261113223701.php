<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261113223701 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename trip_stop.position to stop_order and update unique index';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX uniq_trip_stop_trip_position ON trip_stop');
        $this->addSql('ALTER TABLE trip_stop CHANGE position stop_order INT NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_trip_stop_trip_order ON trip_stop (trip_id, stop_order)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX uniq_trip_stop_trip_order ON trip_stop');
        $this->addSql('ALTER TABLE trip_stop CHANGE stop_order position INT NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_trip_stop_trip_position ON trip_stop (trip_id, position)');
    }
}
