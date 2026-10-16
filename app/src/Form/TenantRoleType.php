<?php

namespace App\Form;

use App\Entity\Tenant\TenantRole;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class TenantRoleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, \App\Entity\Permission\Permission[]> $allPermissions */
        $allPermissions = $options['permissions'];
        $selectedCodes  = $options['selected_codes'];

        // Constrói as choices agrupadas por group
        $choices = [];
        $groupLabels = [
            'modules'   => 'Módulos',
            'resources' => 'Recursos',
            'admin'     => 'Administração',
        ];

        foreach ($allPermissions as $group => $permissions) {
            $groupLabel = $groupLabels[$group] ?? ucfirst($group);
            foreach ($permissions as $permission) {
                $choices[$groupLabel][$permission->getDescription()] = $permission->getCode();
            }
        }

        $builder
            ->add('name', TextType::class, [
                'label'       => 'Nome do perfil',
                // Sem empty_data o campo vazio vira null e o setName(string) estoura TypeError.
                'empty_data'  => '',
                'constraints' => [
                    new NotBlank(message: 'Informe o nome do perfil.'),
                    new Length(max: 100),
                ],
                'attr'        => ['maxlength' => 100, 'placeholder' => 'Ex.: Advogado Sênior'],
            ])
            // Desenho "05 - Perfis 1.0": DESCRIÇÃO é campo de uma linha (input), não textarea.
            ->add('description', TextType::class, [
                'label'       => 'Descrição',
                'required'    => false,
                'constraints' => [new Length(max: 255)],
                'attr'        => ['maxlength' => 255, 'placeholder' => 'Descrição opcional do perfil'],
            ])
            ->add('permissions', ChoiceType::class, [
                'label'    => false,
                'mapped'   => false,
                'expanded' => true,
                'multiple' => true,
                'choices'  => $choices,
                'data'     => $selectedCodes,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'     => TenantRole::class,
            'permissions'    => [],
            'selected_codes' => [],
        ]);
    }
}
