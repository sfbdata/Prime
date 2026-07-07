<?php

declare(strict_types=1);

namespace App\Djen\Form;

use App\Djen\DTO\OabMonitoradaInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class OabMonitoradaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('numero', TextType::class, [
                'label' => 'Número da OAB',
                'required' => true,
                'attr' => ['class' => 'form-control', 'placeholder' => 'Somente números', 'inputmode' => 'numeric'],
            ])
            ->add('uf', TextType::class, [
                'label' => 'UF',
                'required' => true,
                'attr' => ['class' => 'form-control text-uppercase', 'placeholder' => 'Ex.: PR', 'maxlength' => 2],
            ])
            ->add('apelido', TextType::class, [
                'label' => 'Apelido (opcional)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'placeholder' => 'Ex.: Dra. Fulana'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => OabMonitoradaInput::class,
        ]);
    }
}
