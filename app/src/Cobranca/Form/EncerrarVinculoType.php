<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\EncerrarVinculoInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Encerrar um vínculo Pessoa↔Objeto (SPEC §7). `vinculoId` NÃO é campo — vem da rota e é preenchido
 * pelo controller. O motivo é obrigatório (regra de negócio). Validação nos #[Assert] do DTO.
 */
final class EncerrarVinculoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('motivoEncerramento', TextType::class, [
                'label' => 'Motivo do encerramento',
                'attr' => ['class' => 'form-control', 'placeholder' => 'Ex.: venda, fim da locação', 'maxlength' => 255],
            ])
            ->add('dataFim', DateType::class, [
                'label' => 'Data final (opcional)',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('observacao', TextareaType::class, [
                'label' => 'Observação (opcional)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'rows' => 2],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EncerrarVinculoInput::class,
        ]);
    }
}
