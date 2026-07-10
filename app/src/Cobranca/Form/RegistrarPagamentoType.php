<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\RegistrarPagamentoInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Registrar um pagamento monetário do caso (SPEC §11). `casoId` vem da rota. O valor pago é BRUTO em
 * centavos; as alocações distribuem MANUALMENTE a parte da dívida entre as obrigações exigíveis do
 * caso (invariável 12; sugestão FIFO é follow-up, não desta onda). As obrigações do ChoiceType de cada
 * alocação são escopadas ao caso (opção `obrigacoes`, propagada às linhas via `entry_options`).
 */
final class RegistrarPagamentoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('data', DateType::class, [
                'label' => 'Data do pagamento',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('valorPago', CentavosType::class, [
                'label' => 'Valor pago (R$)',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('alocacoes', CollectionType::class, [
                'entry_type' => AlocacaoPagamentoType::class,
                'entry_options' => ['label' => false, 'obrigacoes' => $options['obrigacoes']],
                'label' => 'Alocações',
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RegistrarPagamentoInput::class,
            'obrigacoes' => [],
        ]);
        $resolver->setAllowedTypes('obrigacoes', 'array');
    }
}
