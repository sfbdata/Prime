<?php

namespace App\Form;

use App\Entity\Tenant\Sede;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Validator\Constraints as Assert;

class SedeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nome', TextType::class, [
                'label' => 'Nome da Sede',
            ])
            ->add('latitude', NumberType::class, [
                'label' => 'Latitude',
                'scale' => 8,
                'required' => true,
                'attr' => ['step' => '0.00000001'],
                'constraints' => [
                    new Assert\NotNull(message: 'Latitude é obrigatória.'),
                    new Assert\Range(min: -90, max: 90, notInRangeMessage: 'Latitude deve estar entre -90 e 90.'),
                ],
            ])
            ->add('longitude', NumberType::class, [
                'label' => 'Longitude',
                'scale' => 8,
                'required' => true,
                'attr' => ['step' => '0.00000001'],
                'constraints' => [
                    new Assert\NotNull(message: 'Longitude é obrigatória.'),
                    new Assert\Range(min: -180, max: 180, notInRangeMessage: 'Longitude deve estar entre -180 e 180.'),
                ],
            ])
            ->add('raioPermitido', NumberType::class, [
                'label' => 'Raio Permitido (metros)',
                'required' => true,
                'empty_data' => '50',
                'attr' => ['min' => 10, 'max' => 500, 'step' => 10],
                'constraints' => [
                    new Assert\Range(min: 10, max: 500, notInRangeMessage: 'Raio permitido deve estar entre 10 e 500 metros.'),
                ],
            ])
            ->add('timezone', TextType::class, [
                'label' => 'Fuso Horário',
                'required' => false,
                'data' => 'America/Sao_Paulo',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Sede::class,
        ]);
    }
}
