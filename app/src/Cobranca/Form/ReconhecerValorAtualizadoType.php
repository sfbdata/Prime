<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\ReconhecerValorAtualizadoInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Reconhecer o valor atualizado (encargos) de uma obrigação. `obrigacaoId` vem da rota, preenchido
 * pelo controller. `encargosReconhecidos` é o total reconhecido (substitui, não soma) — mantém o
 * `valorOriginal` intacto (invariável 20). Renderizado num modal reutilizável (1 por página); a URL
 * de ação e o valor atual são injetados por JS a partir do botão da linha.
 */
final class ReconhecerValorAtualizadoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('encargosReconhecidos', CentavosType::class, [
                'label' => 'Encargos reconhecidos (R$)',
                'required' => false,
                'empty_data' => '0',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('motivo', TextType::class, [
                'label' => 'Motivo (opcional)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'maxlength' => 255],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ReconhecerValorAtualizadoInput::class,
        ]);
    }
}
