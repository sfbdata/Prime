<?php

namespace App\Form;

use App\Entity\Tenant\Tenant;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;

class TenantType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nome do Tenant',
            ])
            ->add('adminEmail', EmailType::class, [
                'mapped' => false, // não pertence ao Tenant, mas usado para criar User
                'label' => 'E-mail do Administrador',
            ])
            ->add('adminPassword', PasswordType::class, [
                'mapped' => false,
                'label' => 'Senha do Administrador',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Tenant::class,
        ]);
    }
}
