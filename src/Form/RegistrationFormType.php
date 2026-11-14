<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, [
                'label' => 'Prénom',
                'attr' => ['placeholder' => 'Ex. Youssef'],
                'constraints' => [
                    new NotBlank(message: 'Veuillez renseigner votre prénom.'),
                    new Length(max: 100),
                ],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Nom',
                'attr' => ['placeholder' => 'Ex. Benali'],
                'constraints' => [
                    new NotBlank(message: 'Veuillez renseigner votre nom.'),
                    new Length(max: 100),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Adresse e-mail',
                'attr' => ['placeholder' => 'exemple@covoiturage.ma'],
                'constraints' => [
                    new NotBlank(message: 'Veuillez renseigner votre adresse e-mail.'),
                    new Email(message: 'Veuillez saisir une adresse e-mail valide.'),
                    new Length(max: 180),
                ],
            ])
            ->add('phone', TelType::class, [
                'label' => 'Téléphone',
                'attr' => ['placeholder' => '+212 6 61 00 00 00'],
                'constraints' => [
                    new NotBlank(message: 'Veuillez renseigner votre numéro de téléphone.'),
                    new Length(max: 30),
                ],
            ])
            ->add('plainPassword', PasswordType::class, [
                'label' => 'Mot de passe',
                'mapped' => false,
                'attr' => [
                    'autocomplete' => 'new-password',
                    'placeholder' => 'Minimum 8 caractères',
                ],
                'constraints' => [
                    new NotBlank(message: 'Veuillez saisir un mot de passe.'),
                    new Length(
                        min: 8,
                        max: 4096,
                        minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.',
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
