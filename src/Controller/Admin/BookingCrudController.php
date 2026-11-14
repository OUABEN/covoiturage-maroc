<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Booking;
use App\Enum\BookingStatus;
use App\Exception\InvalidCancellationException;
use App\Service\CancellationService;
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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Read-only CRUD in back-office: Booking creation is strictly reserved to BookingService::book()
 * so pessimistic locking and segment capacity checks are never bypassed.
 * Cancellation in admin delegates to CancellationService.
 *
 * @extends AbstractCrudController<Booking>
 */
#[IsGranted('ROLE_ADMIN')]
class BookingCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly CancellationService $cancellationService,
    ) {
    }

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
        $cancelAction = Action::new('cancelViaService', 'Annuler (CancellationService)', 'fa fa-ban')
            ->linkToRoute('admin_booking_cancel_service', static fn (Booking $booking): array => ['id' => $booking->getId()])
            ->displayIf(static fn (Booking $booking): bool => BookingStatus::CONFIRMED === $booking->getStatus())
            ->setCssClass('text-danger');

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $cancelAction)
            ->add(Crud::PAGE_DETAIL, $cancelAction)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    #[Route('/admin/booking/{id}/cancel-service', name: 'admin_booking_cancel_service', requirements: ['id' => '\d+'], methods: ['POST', 'GET'])]
    public function cancelViaService(Booking $booking): Response
    {
        $passenger = $booking->getPassenger();
        if (null !== $passenger) {
            try {
                $this->cancellationService->cancelByPassenger($booking, $passenger);
                $this->addFlash(
                    'success',
                    sprintf('Réservation #%d annulée via CancellationService (remboursement : %s MAD).', $booking->getId(), $booking->getRefundAmount())
                );
            } catch (InvalidCancellationException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->redirectToRoute('admin_booking_index');
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
