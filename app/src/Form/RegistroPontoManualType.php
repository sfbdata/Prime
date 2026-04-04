<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class RegistroPontoManualType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('data', DateType::class, [
                'label'       => 'Data',
                'widget'      => 'single_text',
                'mapped'      => false,
                'constraints' => [new NotBlank(message: 'Informe a data.')],
            ])
            ->add('hora', TimeType::class, [
                'label'       => 'Hora',
                'widget'      => 'single_text',
                'mapped'      => false,
                'constraints' => [new NotBlank(message: 'Informe o horário.')],
            ])
            ->add('tipo', ChoiceType::class, [
                'label'   => 'Tipo',
                'choices' => [
                    'Entrada'  => 'entrada',
                    'Repouso'  => 'repouso',
                    'Retorno'  => 'retorno',
                    'Saída'    => 'saida',
                ],
                'constraints' => [new NotBlank(message: 'Selecione o tipo.')],
            ])
            ->add('observacao', TextareaType::class, [
                'label'    => 'Observação',
                'required' => false,
                'attr'     => ['rows' => 2, 'placeholder' => 'Motivo do lançamento manual (opcional)'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'      => null,
            'csrf_protection' => false,
        ]);
    }
}
