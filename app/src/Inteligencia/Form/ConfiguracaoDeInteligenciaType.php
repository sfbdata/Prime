<?php

declare(strict_types=1);

namespace App\Inteligencia\Form;

use App\Inteligencia\DTO\ConfiguracaoDeInteligenciaInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ConfiguracaoDeInteligenciaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('habilitada', CheckboxType::class, [
                'label' => 'Ligar a BlueJus IA neste escritório',
                'required' => false,
            ])
            ->add('limiteDiario', IntegerType::class, [
                'label' => 'Limite diário de análises',
                'attr' => ['min' => 0, 'max' => 100000],
            ])
            ->add('limiteMensal', IntegerType::class, [
                'label' => 'Limite mensal de análises',
                'attr' => ['min' => 0, 'max' => 1000000],
            ])
            ->add('mascararDadosPessoais', CheckboxType::class, [
                'label' => 'Mascarar CPF, CNPJ, telefone e e-mail antes de enviar',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ConfiguracaoDeInteligenciaInput::class,
        ]);
    }
}
