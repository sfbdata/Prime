<?php

namespace App\Form;

use App\Entity\Auth\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
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
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
