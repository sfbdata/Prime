<?php

namespace App\Form;

use App\Entity\Auth\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UserRolesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('roles', ChoiceType::class, [
            'choices' => RolesProfile::ROLES,
            'expanded' => true,   // checkboxes
            'multiple' => true,   // permite selecionar mais de um
            'label' => 'Perfis',
        ])
        ->add('newPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'mapped' => false,
            'required' => false,
            'invalid_message' => 'As senhas não coincidem.',
            'first_options' => [
                'label' => 'Nova senha',
            ],
            'second_options' => [
                'label' => 'Confirmar nova senha',
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
