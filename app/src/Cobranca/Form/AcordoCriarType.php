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
        $alocados = $options['alocados'];

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
                // Cada checkbox carrega o REMANESCENTE (centavos) para o gerador somar a seleção no JS, e
                // o quanto já foi recebido — que dispara o aviso do modal (ajuste 10, spec §5.3).
                'choice_attr' => static fn (mixed $id): array => [
                    'data-valor-centavos' => (string) ($valores[$id] ?? 0),
                    'data-alocado-centavos' => (string) ($alocados[$id] ?? 0),
                ],
            ])
            // Total negociado (snapshot) e entrada — o gerador inteligente preenche/valida (Ajuste 7).
            ->add('valorTotalNegociado', CentavosType::class, [
                'label' => 'Total negociado',
                'required' => false,
                // `attr` SUBSTITUI o default do CentavosType — por isso inputmode/placeholder são
                // repetidos aqui. `form-control` entrou junto: sem ele os dois campos de dinheiro
                // renderizavam sem estilo (27px contra 38px dos vizinhos), desalinhando a grade.
                'attr' => ['class' => 'form-control', 'inputmode' => 'decimal', 'placeholder' => '0,00', 'data-acordo-total' => ''],
            ])
            ->add('valorEntrada', CentavosType::class, [
                'label' => 'Entrada (opcional)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'inputmode' => 'decimal', 'placeholder' => '0,00', 'data-acordo-entrada' => ''],
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
            // Mapa `obrigacaoId => Σ alocado` (centavos): só informa o aviso do modal; o valor sugerido
            // já vem descontado em `valores`. Vazio = nenhuma selecionada tem pagamento.
            'alocados' => [],
        ]);
        $resolver->setAllowedTypes('obrigacoes', 'array');
        $resolver->setAllowedTypes('valores', 'array');
        $resolver->setAllowedTypes('alocados', 'array');
    }

    /**
     * Mapa `label → id` das obrigações substituíveis, para o ChoiceType. Montado no render pelo
     * `MontadorModaisCaso` e no POST pelo `AcordoController` (as choices precisam casar para a
     * validação). Rótulo com descrição, vencimento e o REMANESCENTE (centavos → reais).
     *
     * `$alocadoPorObrigacao` vazio (default) mantém o rótulo no valor exigível cheio. É o caso do POST
     * do acordo (PRG: o rótulo nunca renderiza, o ChoiceType valida o id) e o do modal de PAGAMENTO
     * (`MontadorModaisCaso::financeiros`), que oferece a obrigação inteira.
     *
     * @param list<Obrigacao> $obrigacoes
     * @param array<int, int> $alocadoPorObrigacao Mapa `obrigacaoId => Σ alocado` (centavos).
     *
     * @return array<string, int>
     */
    public static function opcoesObrigacoes(array $obrigacoes, array $alocadoPorObrigacao = []): array
    {
        $opcoes = [];
        foreach ($obrigacoes as $o) {
            $alocado = $alocadoPorObrigacao[(int) $o->getId()] ?? 0;
            $remanescente = max(0, $o->valorExigivel() - $alocado);
            $valor = number_format($remanescente / 100, 2, ',', '.');

            $label = sprintf(
                '%s — venc %s — R$ %s',
                $o->getDescricao(),
                $o->getVencimentoOriginal()->format('d/m/Y'),
                $valor,
            );

            // Ajuste 10 (spec §5.3): o gestor não pode renegociar às cegas um valor que já foi pago.
            if ($alocado > 0) {
                $label .= sprintf(' (R$ %s já recebidos)', number_format($alocado / 100, 2, ',', '.'));
            }

            $opcoes[$label] = $o->getId();
        }

        return $opcoes;
    }

    /**
     * Mapa `id → REMANESCENTE (centavos)` das substituíveis, para o gerador somar a seleção no JS (via
     * `data-valor-centavos`). Ajuste 10 (spec §5.3 / INV-U9): era o valor exigível CHEIO, que fazia o
     * gestor renegociar o que o devedor já pagou — o pagamento então evaporava do saldo, porque a
     * substituída sai do exigível levando a alocação junto (`CalculadoraSaldo:57`). É o mesmo número que
     * `ObrigacaoOutput::restante()` mostra na linha da dívida: os dois lados TÊM de bater.
     *
     * @param list<Obrigacao> $obrigacoes
     * @param array<int, int> $alocadoPorObrigacao Mapa `obrigacaoId => Σ alocado` (centavos).
     *
     * @return array<int, int>
     */
    public static function valoresObrigacoes(array $obrigacoes, array $alocadoPorObrigacao = []): array
    {
        $valores = [];
        foreach ($obrigacoes as $o) {
            $id = (int) $o->getId();
            // Piso 0: alocação manual não tem teto por obrigação (spec §10).
            $valores[$id] = max(0, $o->valorExigivel() - ($alocadoPorObrigacao[$id] ?? 0));
        }

        return $valores;
    }
}

