<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\CriarAcordoInput;
use App\Cobranca\Entity\Obrigacao;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Criar um acordo: substitui obrigações exigíveis do Caso por parcelas. `casoId` vem da rota. As
 * obrigações substituíveis são escopadas ao Caso (opção `obrigacoes` = mapa label→id), montada pelo
 * controller — tanto no render (show) quanto no POST — para a validação do ChoiceType casar.
 */
final class AcordoCriarType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $valores = $options['valores'];

        $builder
            ->add('dataAcordo', DateType::class, [
                'label' => 'Data do acordo',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('obrigacoesSubstituidasIds', ChoiceType::class, [
                'label' => 'Obrigações substituídas',
                'choices' => $options['obrigacoes'],
                'multiple' => true,
                'expanded' => true,
                'choice_translation_domain' => false,
                // Cada checkbox carrega o valor exigível (centavos) para o gerador somar a seleção no JS.
                'choice_attr' => static fn (mixed $id): array => ['data-valor-centavos' => (string) ($valores[$id] ?? 0)],
            ])
            // Total negociado (snapshot) e entrada — o gerador inteligente preenche/valida (Ajuste 7).
            ->add('valorTotalNegociado', CentavosType::class, [
                'label' => 'Total negociado',
                'required' => false,
                // Preserva os defaults do CentavosType (inputmode/placeholder), que attr sobrescreveria.
                'attr' => ['inputmode' => 'decimal', 'placeholder' => '0,00', 'data-acordo-total' => ''],
            ])
            ->add('valorEntrada', CentavosType::class, [
                'label' => 'Entrada (opcional)',
                'required' => false,
                'attr' => ['inputmode' => 'decimal', 'placeholder' => '0,00', 'data-acordo-entrada' => ''],
            ])
            ->add('parcelas', CollectionType::class, [
                'entry_type' => ParcelaAcordoType::class,
                'label' => 'Parcelas',
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
                'entry_options' => ['label' => false],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CriarAcordoInput::class,
            'obrigacoes' => [],
            'valores' => [],
        ]);
        $resolver->setAllowedTypes('obrigacoes', 'array');
        $resolver->setAllowedTypes('valores', 'array');
    }

    /**
     * Mapa `label → id` das obrigações substituíveis, para o ChoiceType. Chamado pelo controller no
     * render e no POST (as choices precisam casar para a validação). Rótulo com descrição, vencimento
     * e valor atual (centavos → reais).
     *
     * @param list<Obrigacao> $obrigacoes
     *
     * @return array<string, int>
     */
    public static function opcoesObrigacoes(array $obrigacoes): array
    {
        $opcoes = [];
        foreach ($obrigacoes as $o) {
            $valor = number_format(($o->getValorOriginal() + $o->getEncargosReconhecidos()) / 100, 2, ',', '.');
            $label = sprintf('%s — venc %s — R$ %s', $o->getDescricao(), $o->getVencimentoOriginal()->format('d/m/Y'), $valor);
            $opcoes[$label] = $o->getId();
        }

        return $opcoes;
    }

    /**
     * Mapa `id → valor exigível (centavos)` das obrigações substituíveis, para o gerador somar a
     * seleção no JS (via `data-valor-centavos` em cada checkbox). Mesma lista de `opcoesObrigacoes`.
     *
     * @param list<Obrigacao> $obrigacoes
     *
     * @return array<int, int>
     */
    public static function valoresObrigacoes(array $obrigacoes): array
    {
        $valores = [];
        foreach ($obrigacoes as $o) {
            $valores[(int) $o->getId()] = $o->valorExigivel();
        }

        return $valores;
    }
}

