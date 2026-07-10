<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\ConcluirAcaoInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Concluir a ação pendente do Caso, opcionalmente já definindo a próxima. `acaoId` vem da rota.
 */
final class ConcluirAcaoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('resultado', TextareaType::class, [
                'label' => 'Resultado',
                'attr' => ['class' => 'form-control', 'rows' => 2, 'maxlength' => 2000, 'placeholder' => 'O que aconteceu'],
            ])
            ->add('proximaDescricao', TextType::class, [
                'label' => 'Próxima ação (opcional)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'maxlength' => 255],
            ])
            ->add('proximoPrazo', DateType::class, [
                'label' => 'Prazo da próxima ação (opcional)',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'attr' => ['class' => 'form-control'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ConcluirAcaoInput::class,
        ]);
    }
}
