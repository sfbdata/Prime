<?php

declare(strict_types=1);

namespace App\Profile\Form;

use App\Profile\DTO\OabPerfilInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class OabPerfilType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('oabNumero', TextType::class, [
                'label'    => 'Número da OAB',
                'required' => false,
                'attr'     => ['maxlength' => 20, 'placeholder' => 'Somente números'],
            ])
            ->add('oabUf', TextType::class, [
                'label'    => 'UF',
                'required' => false,
                'attr'     => ['maxlength' => 2, 'placeholder' => 'Ex.: SP', 'style' => 'text-transform:uppercase'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'    => OabPerfilInput::class,
            'csrf_token_id' => 'profile_oab',
        ]);
    }
}
