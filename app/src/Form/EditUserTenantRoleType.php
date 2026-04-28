<?php

namespace App\Form;

use App\Entity\Auth\User;
use App\Entity\Tenant\Cargo;
use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\TenantRole;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EditUserTenantRoleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('codigoFuncionario', TextType::class, [
                'label'    => 'Código do funcionário',
                'required' => false,
                'attr'     => ['maxlength' => 20, 'class' => 'form-control'],
            ])
            ->add('tenantRole', EntityType::class, [
                'class'        => TenantRole::class,
                'label'        => 'Perfil',
                'choices'      => $options['tenant_roles'],
                'choice_label' => 'name',
                'placeholder'  => 'Selecione um perfil',
                'required'     => true,
                'attr'         => ['class' => 'form-select'],
            ])
            ->add('cargo', EntityType::class, [
                'class'        => Cargo::class,
                'label'        => 'Cargo',
                'choices'      => $options['tenant_cargos'],
                'choice_label' => 'nome',
                'placeholder'  => '— Nenhum —',
                'required'     => false,
                'attr'         => ['class' => 'form-select'],
            ])
            ->add('lotacao', EntityType::class, [
                'class'        => Lotacao::class,
                'label'        => 'Lotação',
                'choices'      => $options['tenant_lotacoes'],
                'choice_label' => 'nome',
                'placeholder'  => '— Nenhuma —',
                'required'     => false,
                'attr'         => ['class' => 'form-select'],
            ])
            ->add('isActive', CheckboxType::class, [
                'label'    => 'Conta ativa',
                'required' => false,
            ])
            ->add('newPassword', RepeatedType::class, [
                'type'            => PasswordType::class,
                'mapped'          => false,
                'required'        => false,
                'invalid_message' => 'As senhas não coincidem.',
                'first_options'   => ['label' => 'Nova senha'],
                'second_options'  => ['label' => 'Confirmar nova senha'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'      => User::class,
            'tenant_roles'    => [],
            'tenant_cargos'   => [],
            'tenant_lotacoes' => [],
        ]);

        $resolver->setAllowedTypes('tenant_roles',    ['array']);
        $resolver->setAllowedTypes('tenant_cargos',   ['array']);
        $resolver->setAllowedTypes('tenant_lotacoes', ['array']);
    }
}
