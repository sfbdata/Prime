<?php

namespace App\Form;

use App\Entity\Auth\User;
use App\Entity\Tenant\TenantRole;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EditUserTenantRoleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('tenantRole', EntityType::class, [
                'class'        => TenantRole::class,
                'label'        => 'Perfil',
                'choices'      => $options['tenant_roles'],
                'choice_label' => 'name',
                'placeholder'  => 'Selecione um perfil',
                'required'     => true,
                'attr'         => ['class' => 'form-select'],
            ])
            ->add('isActive', CheckboxType::class, [
                'label'    => 'Conta ativa',
                'required' => false,
            ])
            ->add('newPassword', RepeatedType::class, [
                'type'             => PasswordType::class,
                'mapped'           => false,
                'required'         => false,
                'invalid_message'  => 'As senhas não coincidem.',
                'first_options'    => ['label' => 'Nova senha'],
                'second_options'   => ['label' => 'Confirmar nova senha'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'   => User::class,
            'tenant_roles' => [],
        ]);

        $resolver->setAllowedTypes('tenant_roles', ['array']);
    }
}
