<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261113223221 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: user, vehicle, city, trip, trip_stop, booking, review';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE booking (id INT AUTO_INCREMENT NOT NULL, seats INT NOT NULL, price NUMERIC(10, 2) NOT NULL, status VARCHAR(30) NOT NULL, refund_amount NUMERIC(10, 2) DEFAULT NULL, created_at DATETIME NOT NULL, cancelled_at DATETIME DEFAULT NULL, trip_id INT NOT NULL, passenger_id INT NOT NULL, from_stop_id INT NOT NULL, to_stop_id INT NOT NULL, INDEX idx_booking_trip_status (trip_id, status), INDEX IDX_E00CEDDEA5BC2E0E (trip_id), INDEX IDX_E00CEDDE4502E565 (passenger_id), INDEX IDX_E00CEDDEBF5CE592 (from_stop_id), INDEX IDX_E00CEDDEB79A3BC8 (to_stop_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE city (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, latitude NUMERIC(9, 6) NOT NULL, longitude NUMERIC(9, 6) NOT NULL, INDEX idx_city_name (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE review (id INT AUTO_INCREMENT NOT NULL, rating SMALLINT NOT NULL, comment LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, booking_id INT NOT NULL, author_id INT NOT NULL, target_id INT NOT NULL, UNIQUE INDEX uniq_review_booking_author (booking_id, author_id), INDEX IDX_794381C63301C60 (booking_id), INDEX IDX_794381C6F675F31B (author_id), INDEX IDX_794381C6158E0B66 (target_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE trip (id INT AUTO_INCREMENT NOT NULL, seats_total INT NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, cancelled_at DATETIME DEFAULT NULL, driver_id INT NOT NULL, vehicle_id INT NOT NULL, INDEX IDX_7656F53BC3423909 (driver_id), INDEX IDX_7656F53B545317D1 (vehicle_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE trip_stop (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, arrival_time DATETIME DEFAULT NULL, departure_time DATETIME DEFAULT NULL, price_from_previous NUMERIC(10, 2) NOT NULL, trip_id INT NOT NULL, city_id INT NOT NULL, UNIQUE INDEX uniq_trip_stop_trip_position (trip_id, position), INDEX IDX_926E85DDA5BC2E0E (trip_id), INDEX IDX_926E85DD8BAC62AF (city_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `user` (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, last_name VARCHAR(100) NOT NULL, first_name VARCHAR(100) NOT NULL, phone VARCHAR(30) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_user_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE vehicle (id INT AUTO_INCREMENT NOT NULL, brand VARCHAR(100) NOT NULL, model VARCHAR(100) NOT NULL, color VARCHAR(50) NOT NULL, seats INT NOT NULL, owner_id INT NOT NULL, INDEX IDX_1B80E4867E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE booking ADD CONSTRAINT FK_E00CEDDEA5BC2E0E FOREIGN KEY (trip_id) REFERENCES trip (id)');
        $this->addSql('ALTER TABLE booking ADD CONSTRAINT FK_E00CEDDE4502E565 FOREIGN KEY (passenger_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE booking ADD CONSTRAINT FK_E00CEDDEBF5CE592 FOREIGN KEY (from_stop_id) REFERENCES trip_stop (id)');
        $this->addSql('ALTER TABLE booking ADD CONSTRAINT FK_E00CEDDEB79A3BC8 FOREIGN KEY (to_stop_id) REFERENCES trip_stop (id)');
        $this->addSql('ALTER TABLE review ADD CONSTRAINT FK_794381C63301C60 FOREIGN KEY (booking_id) REFERENCES booking (id)');
        $this->addSql('ALTER TABLE review ADD CONSTRAINT FK_794381C6F675F31B FOREIGN KEY (author_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE review ADD CONSTRAINT FK_794381C6158E0B66 FOREIGN KEY (target_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE trip ADD CONSTRAINT FK_7656F53BC3423909 FOREIGN KEY (driver_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE trip ADD CONSTRAINT FK_7656F53B545317D1 FOREIGN KEY (vehicle_id) REFERENCES vehicle (id)');
        $this->addSql('ALTER TABLE trip_stop ADD CONSTRAINT FK_926E85DDA5BC2E0E FOREIGN KEY (trip_id) REFERENCES trip (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE trip_stop ADD CONSTRAINT FK_926E85DD8BAC62AF FOREIGN KEY (city_id) REFERENCES city (id)');
        $this->addSql('ALTER TABLE vehicle ADD CONSTRAINT FK_1B80E4867E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE booking DROP FOREIGN KEY FK_E00CEDDEA5BC2E0E');
        $this->addSql('ALTER TABLE booking DROP FOREIGN KEY FK_E00CEDDE4502E565');
        $this->addSql('ALTER TABLE booking DROP FOREIGN KEY FK_E00CEDDEBF5CE592');
        $this->addSql('ALTER TABLE booking DROP FOREIGN KEY FK_E00CEDDEB79A3BC8');
        $this->addSql('ALTER TABLE review DROP FOREIGN KEY FK_794381C63301C60');
        $this->addSql('ALTER TABLE review DROP FOREIGN KEY FK_794381C6F675F31B');
        $this->addSql('ALTER TABLE review DROP FOREIGN KEY FK_794381C6158E0B66');
        $this->addSql('ALTER TABLE trip DROP FOREIGN KEY FK_7656F53BC3423909');
        $this->addSql('ALTER TABLE trip DROP FOREIGN KEY FK_7656F53B545317D1');
        $this->addSql('ALTER TABLE trip_stop DROP FOREIGN KEY FK_926E85DDA5BC2E0E');
        $this->addSql('ALTER TABLE trip_stop DROP FOREIGN KEY FK_926E85DD8BAC62AF');
        $this->addSql('ALTER TABLE vehicle DROP FOREIGN KEY FK_1B80E4867E3C61F9');
        $this->addSql('DROP TABLE booking');
        $this->addSql('DROP TABLE city');
        $this->addSql('DROP TABLE review');
        $this->addSql('DROP TABLE trip');
        $this->addSql('DROP TABLE trip_stop');
        $this->addSql('DROP TABLE `user`');
        $this->addSql('DROP TABLE vehicle');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
