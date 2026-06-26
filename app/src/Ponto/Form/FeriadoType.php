<?php

namespace App\Ponto\Form;

use App\Ponto\Entity\Feriado;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class FeriadoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nome', TextType::class, [
                'label' => 'Nome',
                'attr' => ['placeholder' => 'Ex: Natal, Carnaval, Feriado Municipal'],
                'constraints' => [new NotBlank(message: 'O nome é obrigatório.')],
            ])
            ->add('data', DateType::class, [
                'label' => 'Data',
                'widget' => 'single_text',
                'html5' => true,
            ])
            ->add('recorrente', CheckboxType::class, [
                'label' => 'Repete todo ano (feriado fixo)',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Feriado::class]);
    }
}
