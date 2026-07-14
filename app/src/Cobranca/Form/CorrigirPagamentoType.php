<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\CorrigirPagamentoInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Corrigir um pagamento já registrado (SPEC §22, sem estorno). `pagamentoId` vem da rota. Reescreve a
 * composição (valor bruto + alocações) e exige o motivo da correção. Por padrão a distribuição é
 * AUTOMÁTICA por FIFO (Ajuste 6); marcando `alocarManualmente`, o usuário distribui. A `data` é opcional:
 * só sobrescreve a original quando informada. As obrigações do ChoiceType das alocações são escopadas ao
 * caso do pagamento (opção `obrigacoes`, propagada às linhas via `entry_options`).
 */
final class CorrigirPagamentoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('data', DateType::class, [
                'label' => 'Data do pagamento (opcional)',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('valorPago', CentavosType::class, [
                'label' => 'Valor pago (R$)',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('alocarManualmente', CheckboxType::class, [
                'label' => 'Alocar manualmente (avançado)',
                'required' => false,
            ])
            ->add('alocacoes', CollectionType::class, [
                'entry_type' => AlocacaoPagamentoType::class,
                'entry_options' => ['label' => false, 'obrigacoes' => $options['obrigacoes']],
                'label' => 'Alocações',
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
            ])
            ->add('motivoCorrecao', TextareaType::class, [
                'label' => 'Motivo da correção',
                'attr' => ['class' => 'form-control', 'rows' => 2, 'maxlength' => 500],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CorrigirPagamentoInput::class,
            'obrigacoes' => [],
        ]);
        $resolver->setAllowedTypes('obrigacoes', 'array');
    }
}
