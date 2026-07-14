<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\EditarObrigacaoInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Editar (corrigir) uma obrigação. `obrigacaoId` NÃO é campo — vem da rota. Espelha o form de registro +
 * `encargosReconhecidos` (unificação do "Reconhecer valor", ajuste 5) + `motivo` obrigatório. O modal é
 * reutilizável (1 por página) e pré-preenchido por JS via os `data-*` da linha da obrigação.
 */
final class EditarObrigacaoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('descricao', TextType::class, [
                'label' => 'Descrição',
                'attr' => ['class' => 'form-control', 'maxlength' => 255],
            ])
            ->add('valorOriginal', CentavosType::class, [
                'label' => 'Valor original (R$)',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('vencimentoOriginal', DateType::class, [
                'label' => 'Vencimento',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('referenciaExterna', TextType::class, [
                'label' => 'Referência externa (opcional)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'maxlength' => 255],
            ])
            ->add('encargosReconhecidos', CentavosType::class, [
                'label' => 'Encargos reconhecidos (R$)',
                'required' => false,
                'empty_data' => '0',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('motivo', TextType::class, [
                'label' => 'Motivo da correção',
                'attr' => ['class' => 'form-control', 'maxlength' => 255, 'placeholder' => 'Ex.: valor digitado errado na importação'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EditarObrigacaoInput::class,
        ]);
    }
}
