<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\TripStop;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * @extends AbstractCrudController<TripStop>
 */
class TripStopCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return TripStop::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Étape de trajet')
            ->setEntityLabelInPlural('Étapes de trajet')
            ->setDefaultSort(['id' => 'ASC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('trip', 'Trajet');
        yield AssociationField::new('city', 'Ville');
        yield IntegerField::new('stopOrder', 'Ordre (stop_order)');
        yield DateTimeField::new('arrivalTime', 'Heure d\'arrivée');
        yield DateTimeField::new('departureTime', 'Heure de départ');
        yield TextField::new('priceFromPrevious', 'Prix segment (MAD)');
    }
}
