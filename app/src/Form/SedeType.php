<?php

namespace App\Form;

use App\Entity\Tenant\Sede;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;

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
                'required' => false,
            ])
            ->add('longitude', NumberType::class, [
                'label' => 'Longitude',
                'scale' => 8,
                'required' => false,
            ])
            ->add('raioPermitido', NumberType::class, [
                'label' => 'Raio Permitido (metros)',
                'required' => false,
                'attr' => ['min' => 10, 'max' => 500, 'step' => 10],
            ])
            ->add('timezone', TextType::class, [
                'label' => 'Fuso Horário',
                'required' => false,
                'data' => 'America/Sao_Paulo',
            ])
            ->add('ssidsAutorizados', TextType::class, [
                'label' => 'SSIDs Autorizados (separados por vírgula)',
                'required' => false,
                'help' => 'Ex: WiFi_Empresa, WiFi_Diretoria',
                'mapped' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Sede::class,
        ]);
    }
}
