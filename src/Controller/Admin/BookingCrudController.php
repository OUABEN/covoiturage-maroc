<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Booking;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Read-only CRUD in back-office: Booking creation is strictly reserved to BookingService::book()
 * so pessimistic locking and segment capacity checks are never bypassed.
 *
 * @extends AbstractCrudController<Booking>
 */
class BookingCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Booking::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Réservation (lecture seule)')
            ->setEntityLabelInPlural('Réservations (lecture seule)')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id');
        yield AssociationField::new('trip', 'Trajet');
        yield AssociationField::new('passenger', 'Passager');
        yield AssociationField::new('fromStop', 'Départ (fromStop)');
        yield AssociationField::new('toStop', 'Arrivée (toStop)');
        yield IntegerField::new('seats', 'Places');
        yield TextField::new('price', 'Prix figé (MAD)');
        yield ChoiceField::new('status', 'Statut');
        yield TextField::new('refundAmount', 'Remboursement (MAD)');
        yield DateTimeField::new('createdAt', 'Réservé le');
        yield DateTimeField::new('cancelledAt', 'Annulé le');
    }
}
