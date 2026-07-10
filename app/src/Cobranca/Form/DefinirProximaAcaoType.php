<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\DefinirProximaAcaoInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Definir a próxima ação do Caso (máx. 1 pendente — regra no UseCase). `casoId` vem da rota.
 */
final class DefinirProximaAcaoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('descricao', TextType::class, [
                'label' => 'O que fazer',
                'attr' => ['class' => 'form-control', 'placeholder' => 'Ex.: Ligar para o devedor', 'maxlength' => 255],
            ])
            ->add('prazo', DateType::class, [
                'label' => 'Prazo (opcional)',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'attr' => ['class' => 'form-control'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DefinirProximaAcaoInput::class,
        ]);
    }
}
