<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\AlocacaoPagamentoInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Uma linha de alocação de pagamento (sub-form da coleção em RegistrarPagamentoType/CorrigirPagamentoType):
 * a qual obrigação do caso o valor abate. As obrigações exigíveis são escopadas ao caso (opção
 * `obrigacoes` = mapa label→id, montada pelo controller — igual ao acordo, para o ChoiceType casar no
 * render e no POST). Valor em CENTAVOS via CentavosType.
 */
final class AlocacaoPagamentoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('obrigacaoId', ChoiceType::class, [
                'label' => 'Obrigação',
                'choices' => $options['obrigacoes'],
                'placeholder' => 'Selecione a obrigação',
                'choice_translation_domain' => false,
                'attr' => ['class' => 'form-select form-select-sm'],
            ])
            ->add('valor', CentavosType::class, [
                'label' => 'Valor alocado (R$)',
                'attr' => ['class' => 'form-control form-control-sm'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AlocacaoPagamentoInput::class,
            'obrigacoes' => [],
        ]);
        $resolver->setAllowedTypes('obrigacoes', 'array');
    }
}
